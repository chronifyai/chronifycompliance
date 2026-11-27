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
 * Shows the progress of the latest site scan and refreshes it while the scan runs.
 *
 * @module     local_chronifycompliance/scan_progress
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import {getScanStatus} from 'local_chronifycompliance/repository';

/** @type {Number} Milliseconds between refreshes while a scan runs. */
const INTERVAL = 15000;

/**
 * Fetch and render the progress.
 *
 * @param {HTMLElement} region
 * @returns {Promise<Boolean>} Whether the scan is still running.
 */
const refresh = async(region) => {
    const status = await getScanStatus();
    const running = status.status === 'queued' || status.status === 'running';
    const done = status.processed + status.failed;
    const context = {
        scanid: status.scanid,
        running,
        finished: status.status === 'finished',
        total: status.total,
        done,
        failed: status.failed,
        percent: status.total ? Math.round(done * 100 / status.total) : 0,
        timefinished: status.timefinished ? new Date(status.timefinished * 1000).toLocaleString() : '',
    };
    const {html, js} = await Templates.renderForPromise('local_chronifycompliance/scan_progress', context);
    Templates.replaceNodeContents(region, html, js);
    return running;
};

/**
 * Start showing the progress. Refreshes stop when the scan ends or a refresh fails.
 *
 * @param {String} selector
 */
export const init = async(selector) => {
    const region = document.querySelector(selector);
    if (!region) {
        return;
    }
    const poll = async() => {
        try {
            if (await refresh(region)) {
                setTimeout(poll, INTERVAL);
            }
        } catch (error) {
            Notification.exception(error);
        }
    };
    await poll();
};
