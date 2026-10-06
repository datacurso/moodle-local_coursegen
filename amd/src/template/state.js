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
 * What the template editor knows, without the page: the choices, the checks and the payload to save.
 *
 * @module     local_coursegen/template/state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const KEEP = 'keep';
export const AI = 'ai';

/**
 * Whether an action shows the box of the instruction.
 *
 * @param {string} action The action of an activity, for example "ai".
 * @return {boolean}
 */
export function showsInstruction(action) {
    return action === AI;
}

/**
 * Turn any value into one of the two actions. Anything that is not "ai" keeps the activity.
 *
 * @param {*} value The value read from the page.
 * @return {string}
 */
export function normalizeAction(value) {
    if (value === AI) {
        return AI;
    }

    return KEEP;
}

/**
 * Turn any value into text.
 *
 * @param {*} value The value read from the page.
 * @return {string}
 */
export function toText(value) {
    if (typeof value === 'string') {
        return value;
    }

    return '';
}

/**
 * The entry that is saved for an activity. A kept activity has no instruction.
 *
 * @param {{cmid: number, action: string, instruction: string}} row What the page shows for the activity.
 * @return {{cmid: number, action: string, instruction: string}}
 */
export function buildItem(row) {
    const action = normalizeAction(row.action);
    let instruction = '';
    if (showsInstruction(action)) {
        instruction = toText(row.instruction);
    }

    return {cmid: row.cmid, action, instruction};
}

/**
 * The entries that are saved for the activities of the page, in the order of the page.
 *
 * @param {Array} rows What the page shows for each activity.
 * @return {Array}
 */
export function buildItems(rows) {
    const items = [];
    for (const row of rows) {
        items.push(buildItem(row));
    }

    return items;
}

/**
 * What must be fixed before saving.
 *
 * @param {{courseid: number, name: string}} model The editor.
 * @return {string[]} Keys of what is missing: "course" and "name".
 */
export function validate(model) {
    const errors = [];
    if (!(model.courseid > 0)) {
        errors.push('course');
    }

    const text = toText(model.name);
    const name = text.trim();
    if (name === '') {
        errors.push('name');
    }

    return errors;
}

/**
 * The arguments of the web service that saves the template.
 *
 * @param {{templateid: number, courseid: number, name: string, description: string, rows: Array}} model The editor.
 * @return {object}
 */
export function buildPayload(model) {
    const name = toText(model.name);
    const description = toText(model.description);
    const items = buildItems(model.rows);

    return {
        templateid: model.templateid,
        courseid: model.courseid,
        name: name.trim(),
        description,
        items,
    };
}

/**
 * A text that is the same when the admin has changed nothing that would be saved.
 *
 * @param {object} model The editor.
 * @return {string}
 */
export function signature(model) {
    const payload = buildPayload(model);

    return JSON.stringify(payload);
}

/**
 * Whether the admin changed something that would be saved.
 *
 * @param {string} initialSignature The signature when the page opened or was last saved.
 * @param {object} model The editor.
 * @return {boolean}
 */
export function isDirty(initialSignature, model) {
    return initialSignature !== signature(model);
}
