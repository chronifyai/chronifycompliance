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
 * Web service calls of Chronify.Compliance.
 *
 * @module     local_chronifycompliance/repository
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';

/**
 * Preview the fix of a finding.
 *
 * @param {Number} findingid
 * @param {Array} params List of {name, value}.
 * @returns {Promise}
 */
export const previewFix = (findingid, params = []) => fetchMany([{
    methodname: 'local_chronifycompliance_preview_fix',
    args: {findingid, params},
}])[0];

/**
 * Apply the fix of a finding.
 *
 * @param {Number} findingid
 * @param {Array} params List of {name, value}.
 * @returns {Promise}
 */
export const applyFix = (findingid, params = []) => fetchMany([{
    methodname: 'local_chronifycompliance_apply_fix',
    args: {findingid, params},
}])[0];

/**
 * Undo a fix.
 *
 * @param {Number} fixlogid
 * @returns {Promise}
 */
export const undoFix = (fixlogid) => fetchMany([{
    methodname: 'local_chronifycompliance_undo_fix',
    args: {fixlogid},
}])[0];

/**
 * Ignore a finding, or reopen it.
 *
 * @param {Number} findingid
 * @param {String} reason
 * @param {Boolean} ignore
 * @returns {Promise}
 */
export const ignoreFinding = (findingid, reason, ignore = true) => fetchMany([{
    methodname: 'local_chronifycompliance_ignore_finding',
    args: {findingid, reason, ignore},
}])[0];

/**
 * Progress of the latest site scan.
 *
 * @returns {Promise}
 */
export const getScanStatus = () => fetchMany([{
    methodname: 'local_chronifycompliance_get_scan_status',
    args: {},
}])[0];

/**
 * Whether a requested check of a course is still waiting.
 *
 * @param {Number} courseid
 * @returns {Promise}
 */
export const getCourseStatus = (courseid) => fetchMany([{
    methodname: 'local_chronifycompliance_get_course_status',
    args: {courseid},
}])[0];
