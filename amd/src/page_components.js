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
 * Shared page components: Refresh now on the data age line, and dismissing an invitation.
 *
 * @module     local_chronifycompliance/page_components
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import {setUserPreference} from 'core_user/repository';
import {requestRefresh} from 'local_chronifycompliance/repository';

/**
 * Queue a refresh and show what happens next in place of the button.
 *
 * @param {HTMLElement} button
 */
const refresh = async(button) => {
    const pending = new Pending('local_chronifycompliance/refresh');
    button.disabled = true;
    try {
        const result = await requestRefresh(button.dataset.dataset);
        const region = button.closest('[data-region="data-age"]');
        const note = region.querySelector('[data-region="data-age-note"]');
        note.textContent = result.message;
        note.classList.remove('d-none');
        button.remove();
    } catch (error) {
        button.disabled = false;
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Hide an invitation for 30 days.
 *
 * @param {HTMLElement} button
 */
const dismiss = async(button) => {
    const pending = new Pending('local_chronifycompliance/dismiss');
    const invitation = button.closest('[data-region="invitation"]');
    try {
        await setUserPreference(invitation.dataset.preference, Math.floor(Date.now() / 1000));
        invitation.remove();
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Listen to the shared components on the page. Safe to call more than once.
 */
export const init = () => {
    if (document.body.dataset.lccComponents) {
        return;
    }
    document.body.dataset.lccComponents = '1';
    document.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="cc-refresh"], [data-action="cc-dismiss-invitation"]');
        if (!button) {
            return;
        }
        e.preventDefault();
        if (button.dataset.action === 'cc-refresh') {
            refresh(button);
        } else {
            dismiss(button);
        }
    });
};
