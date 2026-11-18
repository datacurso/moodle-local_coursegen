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
 * An activity "modified with AI" in the editor of a template: its badge, its instruction under the name, and the modal
 * where the instruction is written (optional when the activity has markers, required when it has none).
 *
 * @module     local_coursegen/local/template/template_row_aimodify
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';
import {CLASS, COMPONENT} from 'local_coursegen/local/template/constants';

const TAG = '[data-region="local_coursegen/template/aimodify-tag"]';
const LINE = '[data-region="local_coursegen/template/aimodify-instruction"]';
const FIELD = '[data-region="local_coursegen/template/aimodify-field"]';

/**
 * Show (or hide) the badge and the instruction of a row.
 *
 * @param {HTMLElement} row The activity row.
 * @param {boolean} on Whether the row's action is "modify with AI".
 * @param {number} cmid
 * @param {Object} state The live wizard state.
 */
export const applyAimodifyVisual = (row, on, cmid, state) => {
    const tag = row.querySelector(TAG);
    const line = row.querySelector(LINE);
    if (!tag || !line) {
        return;
    }
    const text = String(state.activityPrompt[cmid] || '').trim();
    tag.classList.toggle(CLASS.HIDDEN, !on);
    line.textContent = text;
    line.classList.toggle(CLASS.HIDDEN, !on || text === '');
};

/**
 * Open the modal of the instruction of a row.
 *
 * @param {HTMLElement} row The activity row.
 * @param {number} cmid
 * @param {Object} state The live wizard state.
 * @param {Object} handlers What to do when the modal closes.
 * @param {Function} handlers.onSave Called with the instruction when it is saved.
 * @param {Function} [handlers.onCancel] Called when the modal closes without saving.
 * @returns {Promise<void>}
 */
export const openAimodifyModal = async(row, cmid, state, {onSave, onCancel}) => {
    const hasmarkers = row.dataset.hasmarkers === '1';
    const name = row.querySelector('a, span')?.textContent.trim() || '';
    const modal = await ModalSaveCancel.create({
        title: getString('template_aimodify_modal_title', COMPONENT),
        body: Templates.render('local_coursegen/template_aimodify_modal_body', {
            name,
            instruction: state.activityPrompt[cmid] || '',
            hasmarkers,
        }),
        removeOnClose: true,
    });
    let resolved = false;
    const root = modal.getRoot();
    const field = () => root[0].querySelector(FIELD);
    // Without markers there is nothing for the AI to fill: the instruction is what tells it how to change the activity.
    const refresh = () => {
        const save = root[0].querySelector('[data-action="save"]');
        if (save && field()) {
            save.disabled = !hasmarkers && field().value.trim() === '';
        }
    };
    root.on(ModalEvents.bodyRendered, () => {
        refresh();
        field()?.addEventListener('input', refresh);
        field()?.focus();
    });
    root.on(ModalEvents.save, (event) => {
        const value = field() ? field().value.trim() : '';
        if (!hasmarkers && value === '') {
            event.preventDefault();
            return;
        }
        resolved = true;
        onSave(value);
    });
    root.on(ModalEvents.hidden, () => {
        if (!resolved && onCancel) {
            onCancel();
        }
    });
    modal.show();
};

/**
 * Let a click on the badge of a row reopen its instruction.
 *
 * @param {HTMLElement} container The course sections of the editor.
 * @param {Object} state The live wizard state.
 * @param {Function} markDirty Marks the template as changed.
 */
export const bindAimodifyTags = (container, state, markDirty) => {
    container.addEventListener('click', (event) => {
        const tag = event.target.closest(TAG);
        if (!tag) {
            return;
        }
        event.preventDefault();
        const row = tag.closest('[data-for="cmitem"]');
        const cmid = parseInt(tag.dataset.id, 10);
        openAimodifyModal(row, cmid, state, {
            onSave: (instruction) => {
                state.activityPrompt[cmid] = instruction;
                applyAimodifyVisual(row, true, cmid, state);
                markDirty();
            },
        });
    });
};
