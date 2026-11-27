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
 * Actions of the course report and the SCORM panel: preview, apply and undo fixes, ignore and reopen findings.
 *
 * @module     local_chronifycompliance/course_report
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {previewFix, applyFix, undoFix, ignoreFinding} from 'local_chronifycompliance/repository';
import * as UndoToast from 'local_chronifycompliance/undo_toast';

/**
 * Parameter values entered in the preview modal.
 *
 * @param {HTMLElement} root Modal body.
 * @returns {Array} List of {name, value}.
 */
const getParams = (root) => Array.from(root.querySelectorAll('[data-region="fix-param"]'))
    .map((input) => ({name: input.name, value: input.value}));

/**
 * Render the preview and update the modal.
 *
 * @param {Object} modal
 * @param {Number} findingid
 * @param {Array} params
 */
const refreshPreview = async(modal, findingid, params) => {
    const pending = new Pending('local_chronifycompliance/preview');
    try {
        const preview = await previewFix(findingid, params);
        const {html, js} = await Templates.renderForPromise('local_chronifycompliance/fix_preview', preview);
        await modal.setBody(html);
        Templates.runTemplateJS(js);
        modal.setButtonDisabled('save', !preview.canapply || preview.error !== '');
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Open the fix preview modal and apply on save.
 *
 * @param {Number} findingid
 */
const showFixModal = async(findingid) => {
    const modal = await ModalSaveCancel.create({
        title: getString('report:previewfix', 'local_chronifycompliance'),
        body: '',
        buttons: {save: getString('report:applyfix', 'local_chronifycompliance')},
        removeOnClose: true,
    });
    modal.show();
    await refreshPreview(modal, findingid, []);

    modal.getRoot()[0].addEventListener('click', (e) => {
        if (e.target.closest('[data-action="cd-update-preview"]')) {
            e.preventDefault();
            refreshPreview(modal, findingid, getParams(modal.getBody()[0]));
        }
    });
    modal.getRoot().on(ModalEvents.save, async(e) => {
        e.preventDefault();
        const pending = new Pending('local_chronifycompliance/apply');
        try {
            const result = await applyFix(findingid, getParams(modal.getBody()[0]));
            modal.destroy();
            if (result.success) {
                UndoToast.remember(result.fixlogid, result.message);
                window.location.reload();
            } else {
                Notification.addNotification({message: result.message, type: 'error'});
            }
        } catch (error) {
            Notification.exception(error);
        } finally {
            pending.resolve();
        }
    });
};

/**
 * Open the ignore modal and ignore on save.
 *
 * @param {Number} findingid
 */
const showIgnoreModal = async(findingid) => {
    const modal = await ModalSaveCancel.create({
        title: getString('report:ignore', 'local_chronifycompliance'),
        body: Templates.render('local_chronifycompliance/ignore_form', {findingid}),
        buttons: {save: getString('report:ignore', 'local_chronifycompliance')},
        removeOnClose: true,
    });
    modal.show();
    modal.getRoot().on(ModalEvents.save, async(e) => {
        e.preventDefault();
        const reason = modal.getBody()[0].querySelector('[data-region="ignore-reason"]').value;
        const pending = new Pending('local_chronifycompliance/ignore');
        try {
            await ignoreFinding(findingid, reason, true);
            modal.destroy();
            window.location.reload();
        } catch (error) {
            Notification.exception(error);
        } finally {
            pending.resolve();
        }
    });
};

/**
 * Confirm and undo a fix.
 *
 * @param {Number} fixlogid
 */
const confirmUndo = async(fixlogid) => {
    try {
        await Notification.saveCancelPromise(
            getString('report:undo', 'local_chronifycompliance'),
            getString('report:undoconfirm', 'local_chronifycompliance'),
            getString('report:undo', 'local_chronifycompliance')
        );
    } catch (error) {
        return;
    }
    const pending = new Pending('local_chronifycompliance/undo');
    try {
        await undoFix(fixlogid);
        window.location.reload();
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Reopen an ignored finding.
 *
 * @param {Number} findingid
 */
const reopen = async(findingid) => {
    const pending = new Pending('local_chronifycompliance/reopen');
    try {
        await ignoreFinding(findingid, '', false);
        window.location.reload();
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Listen to the action buttons of a report region.
 *
 * @param {String} selector Selector of the report region.
 */
export const init = (selector) => {
    UndoToast.init();
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button) {
            return;
        }
        const action = button.dataset.action;
        if (action === 'cd-preview-fix') {
            e.preventDefault();
            showFixModal(Number(button.dataset.findingid));
        } else if (action === 'cd-ignore') {
            e.preventDefault();
            showIgnoreModal(Number(button.dataset.findingid));
        } else if (action === 'cd-undo-fix') {
            e.preventDefault();
            confirmUndo(Number(button.dataset.fixlogid));
        } else if (action === 'cd-reopen') {
            e.preventDefault();
            reopen(Number(button.dataset.findingid));
        }
    });
};
