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
 * The spaces where the teacher adds one of the activities the admin allowed: the modal to choose it (and say what they
 * want of it) and to take it out. The AI writes the activity when the course is generated.
 *
 * @module     local_coursegen/local/courseai/template/space_choices
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import {setSpaceChoice} from 'local_coursegen/local/courseai/template/state';

const OPTION = '[data-region="local_coursegen/template/space-choice-option"]';
const OWN = '[data-region="local_coursegen/template/space-choice-instruction"]';

/**
 * Open the modal of a space and keep the activity the teacher adds.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange Called after the space changed, to draw it again; may return a promise.
 * @returns {Promise<void>}
 */
export const pickSpaceChoice = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (document.body.classList.contains('cg-generating')) {
        return;
    }
    const row = tplState.sections[sectionIndex]?.activities[activityIndex];
    if (!row || row.spacekind !== 'choice') {
        return;
    }
    const chosen = row.spacechoice?.modname || (row.spaceoptions.length === 1 ? row.spaceoptions[0].modname : '');
    const modal = await ModalSaveCancel.create({
        title: getString('courseai_template_choice_space_title', 'local_coursegen'),
        body: Templates.render('local_coursegen/courseai_space_choice_modal_body', {
            options: row.spaceoptions.map((option) => ({...option, checked: option.modname === chosen})),
            instruction: row.spacechoice?.instruction || '',
        }),
        buttons: {save: getString('courseai_template_choice_space_add', 'local_coursegen')},
        removeOnClose: true,
    });
    const root = modal.getRoot();
    const refresh = () => {
        const save = root[0].querySelector('[data-action="save"]');
        if (save) {
            save.disabled = !root[0].querySelector(`${OPTION}:checked`);
        }
    };
    root.on(ModalEvents.bodyRendered, () => {
        root[0].querySelectorAll(OPTION).forEach((radio) => radio.addEventListener('change', refresh));
        refresh();
    });
    root.on(ModalEvents.save, async(event) => {
        const picked = root[0].querySelector(`${OPTION}:checked`);
        if (!picked) {
            event.preventDefault();
            return;
        }
        const option = row.spaceoptions.find((entry) => entry.modname === picked.value);
        const instruction = root[0].querySelector(OWN)?.value.trim() || '';
        if (setSpaceChoice(tplState, sectionIndex, activityIndex, {modname: option.modname, title: option.title, instruction})) {
            await onChange();
        }
    });
    modal.show();
};

/**
 * Take out the activity the teacher added in a space.
 *
 * @param {Object} tplState
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Function} onChange Called after the space changed; may return a promise.
 * @returns {Promise<void>}
 */
export const removeSpaceChoice = async(tplState, sectionIndex, activityIndex, onChange) => {
    if (setSpaceChoice(tplState, sectionIndex, activityIndex, null)) {
        await onChange();
    }
};
