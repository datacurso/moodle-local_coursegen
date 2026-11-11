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
 * The single shared "configure template" modal: asks whether an activity
 * marked "Use as template" is usable as a mold by every "Allow AI
 * modification" activity in the course, or only by ones in the same
 * section. One Moodle core/modal_save_cancel instance is created lazily and
 * reused for every row (and for a bulk apply) instead of one modal per row.
 *
 * @module     local_coursegen/local/template/template_scope_modal
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
        modalPromise = ModalSaveCancel.create({
            title: getString('template_scope_modal_title', 'local_coursegen'),
            removeOnClose: false,
        });
    }
    return modalPromise;
};

/**
 * Read whichever scope radio is currently checked in the modal body.
 *
 * @param {Object} modal The modal instance.
 * @returns {string} "course" or "section" (falls back to "course").
 */
const checkedScope = (modal) => {
    const checked = modal.getRoot()[0].querySelector('input[name="template-scope-choice"]:checked');
    return checked ? checked.value : 'course';
};

/**
 * Open the shared modal to set (or change) one subject's template scope.
 *
 * Save and Cancel each resolve exactly once per open: closing the modal any
 * other way (Escape, the backdrop, the header close button) also resolves
 * as a cancel, so a caller relying on onCancel to revert a fresh selection
 * cannot be left in a half-configured state.
 *
 * @param {Object} options
 * @param {string} options.subject What the choice applies to — an activity
 *     name, or a "N activities selected" phrase for a bulk apply.
 * @param {string} options.scope The scope to preselect ("course"|"section").
 * @param {Function} options.onSave (scope) => void, called once when Save
 *     is pressed, with the radio value the admin picked.
 * @param {Function} [options.onCancel] Called once for any non-save close.
 */
export const openTemplateScopeModal = async({subject, scope, onSave, onCancel}) => {
    const modal = await getModal();
    const body = await Templates.render('local_coursegen/template_scope_modal_body', {
        subject,
        coursechecked: scope !== 'section',
        sectionchecked: scope === 'section',
    });
    await modal.setBody(body);

    let resolved = false;
    const root = modal.getRoot();
    root.off(ModalEvents.save).on(ModalEvents.save, () => {
        resolved = true;
        onSave(checkedScope(modal));
        modal.hide();
    });
    root.off(ModalEvents.cancel).on(ModalEvents.cancel, () => {
        resolved = true;
        if (onCancel) {
            onCancel();
        }
        modal.hide();
    });
    root.off(ModalEvents.hidden).on(ModalEvents.hidden, () => {
        if (!resolved && onCancel) {
            onCancel();
        }
        resolved = true;
    });

    modal.show();
};
