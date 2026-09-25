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
 * Opening and closing the template layout: the `is-template` class, the
 * shared thread/decision ids, the professor's text carried between the two
 * composers, and telling the native template select which template is
 * attached. Pure DOM operations - no page state of its own, so any caller
 * can drive them directly.
 *
 * The two layouts share the ids of their thread and decision elements
 * (data-shared-id): only the column that is active carries them, so every
 * module that looks an id up finds the element that is on screen.
 *
 * @module     local_coursegen/local/courseai/context/template_layout
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Give the shared ids to the column that is active and take them from the other.
 *
 * @param {boolean} templateActive
 */
const claimSharedIds = (templateActive) => {
    const templateColumn = document.getElementById('templateModeView');
    document.querySelectorAll('[data-shared-id]').forEach((el) => {
        const inTemplate = !!templateColumn && templateColumn.contains(el);
        if (inTemplate === templateActive) {
            el.id = el.dataset.sharedId;
        } else {
            el.removeAttribute('id');
        }
    });
};

/**
 * Whether the starting point is fixed: planning has started in the free path,
 * or a generation is running in the template path.
 *
 * @returns {boolean}
 */
export const isLocked = () => (document.getElementById('courseaiWorkspace')?.classList.contains('is-planning') ?? false)
    || document.body.classList.contains('cg-generating');

/**
 * Carry the professor's text from one composer to the other.
 *
 * @param {string} fromId
 * @param {string} toId
 */
const carryPrompt = (fromId, toId) => {
    const from = document.getElementById(fromId);
    const to = document.getElementById(toId);
    if (!from || !to) {
        return;
    }
    if (from.value.trim() !== '' || to.value.trim() === '') {
        to.value = from.value;
    }
    to.dispatchEvent(new Event('input', {bubbles: true}));
};

/**
 * Open or close the template layout.
 *
 * @param {boolean} on
 */
export const setTemplateLayout = (on) => {
    const workspace = document.getElementById('courseaiWorkspace');
    if (!workspace) {
        return;
    }
    // Always align the shared ids with what's being asked for: this runs
    // once at boot too, to settle a page the server already rendered
    // into the template layout, and claimSharedIds() only ever looks at
    // where each element currently sits, so repeating it is harmless.
    claimSharedIds(on);
    const wasOn = workspace.classList.contains('is-template');
    if (on === wasOn) {
        return;
    }
    workspace.classList.toggle('is-template', on);
    if (on) {
        carryPrompt('promptInput', 'tplPromptInput');
    } else {
        carryPrompt('tplPromptInput', 'promptInput');
    }
};

/**
 * Tell the native picker which template is attached; template_mode.js
 * listens to its 'change' and loads or clears the structure. The change
 * is always dispatched: for a template named in the address the server
 * has already given the select that value, and the structure still has
 * to load.
 *
 * @param {string} value
 */
export const setPickerValue = (value) => {
    const select = document.getElementById('id_templateid');
    if (!select) {
        return;
    }
    select.value = value;
    select.dispatchEvent(new Event('change', {bubbles: true}));
};
