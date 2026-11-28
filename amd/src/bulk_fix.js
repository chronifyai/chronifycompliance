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
 * Completion workspace: fix all one click problems of the selected courses, after one combined preview.
 *
 * @module     local_chronifycompliance/bulk_fix
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {previewBulkFix, applyBulkFix} from 'local_chronifycompliance/repository';
import * as UndoToast from 'local_chronifycompliance/undo_toast';

/**
 * Ids of the courses ticked in the course table.
 *
 * @returns {Number[]}
 */
const selectedCourses = () => Array.from(document.querySelectorAll('input[name="report-select-row[]"]:checked'))
    .map((input) => Number(input.value))
    .filter((id) => id > 0);

/**
 * Show the combined preview and apply on save.
 *
 * @param {Number[]} courseids
 */
const showPreview = async(courseids) => {
    const pending = new Pending('local_chronifycompliance/bulk_preview');
    try {
        const preview = await previewBulkFix(courseids);
        preview.hasitems = preview.items.length > 0;
        const modal = await ModalSaveCancel.create({
            title: getString('bulk:button', 'local_chronifycompliance'),
            body: Templates.render('local_chronifycompliance/bulk_preview', preview),
            buttons: {save: getString('bulk:apply', 'local_chronifycompliance', preview.count)},
            removeOnClose: true,
            large: true,
        });
        modal.show();
        modal.setButtonDisabled('save', !preview.canapply);
        const findingids = preview.items.filter((item) => item.error === '').map((item) => item.findingid);
        modal.getRoot().on(ModalEvents.save, async(e) => {
            e.preventDefault();
            modal.setButtonDisabled('save', true);
            const applying = new Pending('local_chronifycompliance/bulk_apply');
            try {
                const result = await applyBulkFix(courseids, findingids);
                modal.destroy();
                if (result.fixlogids.length) {
                    UndoToast.remember(result.fixlogids, result.message);
                }
                window.location.reload();
            } catch (error) {
                modal.setButtonDisabled('save', false);
                Notification.exception(error);
            } finally {
                applying.resolve();
            }
        });
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Listen to the bulk fix button and offer Undo after a bulk fix.
 */
export const init = () => {
    UndoToast.init();
    const button = document.querySelector('[data-action="cc-bulk-fix"]');
    if (!button) {
        return;
    }
    button.addEventListener('click', async(e) => {
        e.preventDefault();
        const courseids = selectedCourses();
        if (!courseids.length) {
            Notification.alert(
                getString('bulk:button', 'local_chronifycompliance'),
                getString('bulk:noselection', 'local_chronifycompliance')
            );
            return;
        }
        await showPreview(courseids);
    });
};
