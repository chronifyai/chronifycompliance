// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Undo toast shown after a fix was applied. The page reloads after a fix, so the fix is remembered in the
 * session for a short time and the toast appears on the reloaded page.
 *
 * @module     local_chronifycompliance/undo_toast
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {undoFix} from 'local_chronifycompliance/repository';

const KEY = 'local_chronifycompliance_lastfix';
const SHOW_FOR = 30000;

/**
 * Read and clear the remembered fix.
 *
 * @returns {Object|null}
 */
const take = () => {
    try {
        const value = JSON.parse(window.sessionStorage.getItem(KEY));
        window.sessionStorage.removeItem(KEY);
        return value && value.until > Date.now() ? value : null;
    } catch (error) {
        return null;
    }
};

/**
 * Remember an applied fix so the next page load offers Undo.
 *
 * @param {Number|Number[]} fixlogid One fix, or all fixes of a bulk fix (undone together).
 * @param {String} message
 */
export const remember = (fixlogid, message) => {
    const fixlogids = Array.isArray(fixlogid) ? fixlogid : [fixlogid];
    try {
        window.sessionStorage.setItem(KEY, JSON.stringify({fixlogids, message, until: Date.now() + SHOW_FOR}));
    } catch (error) {
        // Storage is not available: the Undo button on the finding still works.
    }
};

/**
 * Show the toast for a fix.
 *
 * @param {Object} fix {fixlogids, message, until}
 */
export const show = async(fix) => {
    const pending = new Pending('local_chronifycompliance/undo_toast');
    try {
        const {html} = await Templates.renderForPromise('local_chronifycompliance/undo_toast', fix);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        const toast = wrapper.firstElementChild;
        document.body.append(toast);
        const timer = window.setTimeout(() => toast.remove(), Math.max(0, fix.until - Date.now()));
        toast.addEventListener('click', async(e) => {
            const button = e.target.closest('[data-action]');
            if (!button) {
                return;
            }
            e.preventDefault();
            window.clearTimeout(timer);
            if (button.dataset.action === 'cc-toast-close') {
                toast.remove();
                return;
            }
            const undoing = new Pending('local_chronifycompliance/toast_undo');
            button.disabled = true;
            try {
                // Newest first, so fixes of the same course are undone in reverse order.
                for (const fixlogid of [...fix.fixlogids].reverse()) {
                    await undoFix(fixlogid);
                }
                window.location.reload();
            } catch (error) {
                button.disabled = false;
                Notification.exception(error);
            } finally {
                undoing.resolve();
            }
        });
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Show the toast for a fix applied on another page (a guided fix), passed by the server.
 *
 * @param {Number} fixlogid
 * @param {String} message
 */
export const showFixed = (fixlogid, message) => show({fixlogids: [fixlogid], message, until: Date.now() + SHOW_FOR});

/**
 * Show the toast when a fix was applied just before this page loaded.
 */
export const init = () => {
    const fix = take();
    if (fix) {
        show(fix);
    }
};
