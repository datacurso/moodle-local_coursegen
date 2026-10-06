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
 * Applying an action to one activity row of the sections review: its select,
 * its state and the box that holds its instruction, in one place so a single
 * change and a bulk change always leave the row the same way.
 *
 * @module     local_coursegen/local/template/row_action
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {normalizeAction, showsInstruction} from 'local_coursegen/local/template/items_state';
import Selectors from 'local_coursegen/local/template/selectors';
import {CLASS} from 'local_coursegen/local/template/constants';

/**
 * Show the instruction box of a row only while the AI modifies the activity.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {number} cmid The row's course module id.
 * @param {string} action The action the row now has.
 */
const toggleInstructionRow = (row, cmid, action) => {
    const selector = Selectors.rows.instructionOf(cmid);
    const instructionRow = row.parentElement.querySelector(selector);
    if (!instructionRow) {
        return;
    }
    const visible = showsInstruction(action);
    instructionRow.classList.toggle(CLASS.HIDDEN, !visible);
};

/**
 * Apply one action to one row: updates its select, the state and the
 * instruction box. Any value that is not "ai" keeps the activity intact.
 *
 * @param {HTMLElement} row The activity row (data-for="cmitem").
 * @param {string} requestedAction The requested action, for example "ai".
 * @param {Object} state The live editor state from init.js.
 */
export const applyRowAction = (row, requestedAction, state) => {
    const cmid = parseInt(row.dataset.id, 10);
    if (!cmid) {
        return;
    }
    const action = normalizeAction(requestedAction);
    state.activityAction[cmid] = action;
    const select = row.querySelector(Selectors.regions.activityActionSelect);
    if (select) {
        select.value = action;
    }
    toggleInstructionRow(row, cmid, action);
};
