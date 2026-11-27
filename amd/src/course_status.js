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
 * Waits for a requested check of a course (or of a SCORM package) to finish, then reloads the page.
 *
 * @module     local_completiondoctor/course_status
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getCourseStatus} from 'local_completiondoctor/repository';

/** @type {Number} Milliseconds between two status requests. */
const INTERVAL = 5000;
/** @type {Number} Stop waiting after this many requests (about 15 minutes). */
const MAX_POLLS = 180;

/**
 * Poll until the check is done.
 *
 * @param {Number} courseid
 */
export const init = (courseid) => {
    let polls = 0;
    const poll = async() => {
        polls++;
        try {
            const status = await getCourseStatus(courseid);
            if (!status.pending) {
                window.location.reload();
            } else if (polls < MAX_POLLS) {
                setTimeout(poll, INTERVAL);
            }
        } catch (error) {
            Notification.exception(error);
        }
    };
    setTimeout(poll, INTERVAL);
};
