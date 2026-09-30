// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The single shared "space for the professor" modal: asks whether the
 * professor must provide the activity or may skip it, and for the
 * instruction that says exactly what to provide. One Moodle
 * core/modal_save_cancel instance is created lazily and reused for every
 * space (a new one, an edit, or a real activity marked as a space).
 *
 * @module     local_coursegen/local/template/template_space_modal
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

/** @type {Promise<Object>|null} The lazily-created shared modal instance. */
let modalPromise = null;

/**
 * Get (creating on first use) the shared modal instance.
 *
 * @returns {Promise<Object>} The core/modal_save_cancel instance.
 */
const getModal = () => {
    if (!modalPromise) {
        const title = getString('template_space_modal_title', 'local_coursegen');
        modalPromise = ModalSaveCancel.create({title, removeOnClose: false});
    }
    return modalPromise;
};

/**
 * Read what the admin chose in the modal body.
 *
 * @param {Object} modal The modal instance.
 * @returns {{required: boolean, instruction: string}}
 */
const readChoice = (modal) => {
    const rootList = modal.getRoot();
    const root = rootList[0];
    const checked = root.querySelector('input[name="template-space-required-choice"]:checked');
    const instructionField = root.querySelector('#template-space-instruction');
    const instruction = instructionField.value.trim();
    return {
        required: !checked || checked.value === '1',
        instruction,
    };
};

/**
 * The admin saved: hand the choice to the caller and close the modal.
 *
 * @param {Object} ctx {modal, onSave, onCancel, resolved} of this open.
 */
const handleSave = (ctx) => {
    ctx.resolved = true;
    const choice = readChoice(ctx.modal);
    ctx.onSave(choice);
    ctx.modal.hide();
};

/**
 * The admin cancelled: tell the caller and close the modal.
 *
 * @param {Object} ctx {modal, onSave, onCancel, resolved} of this open.
 */
const handleCancel = (ctx) => {
    ctx.resolved = true;
    if (ctx.onCancel) {
        ctx.onCancel();
    }
    ctx.modal.hide();
};

/**
 * The modal closed: any close that was not a save or a cancel counts as one.
 *
 * @param {Object} ctx {modal, onSave, onCancel, resolved} of this open.
 */
const handleHidden = (ctx) => {
    if (!ctx.resolved && ctx.onCancel) {
        ctx.onCancel();
    }
    ctx.resolved = true;
};

/**
 * Open the shared modal to set (or change) one space.
 *
 * Save and Cancel each resolve exactly once per open: closing the modal any
 * other way (Escape, the backdrop, the header close button) also resolves as
 * a cancel, so a caller relying on onCancel to revert a fresh selection
 * cannot be left in a half-configured state.
 *
 * @param {Object} options
 * @param {string} options.subject What the space is for — an activity type or name.
 * @param {boolean} options.required Whether "Required" is preselected.
 * @param {string} options.instruction The instruction typed so far.
 * @param {Function} options.onSave ({required, instruction}) => void.
 * @param {Function} [options.onCancel] Called once for any non-save close.
 */
export const openSpaceModal = async({subject, required, instruction, onSave, onCancel}) => {
    const modal = await getModal();
    const body = await Templates.render('local_coursegen/template_space_modal_body', {
        subject,
        requiredchecked: required,
        optionalchecked: !required,
        instruction,
    });
    await modal.setBody(body);

    const ctx = {modal, onSave, onCancel, resolved: false};
    const saveHandler = handleSave.bind(null, ctx);
    const cancelHandler = handleCancel.bind(null, ctx);
    const hiddenHandler = handleHidden.bind(null, ctx);
    const root = modal.getRoot();
    root.off(ModalEvents.save);
    root.on(ModalEvents.save, saveHandler);
    root.off(ModalEvents.cancel);
    root.on(ModalEvents.cancel, cancelHandler);
    root.off(ModalEvents.hidden);
    root.on(ModalEvents.hidden, hiddenHandler);

    modal.show();
};
