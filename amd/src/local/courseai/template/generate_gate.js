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
/**
 * When the Generate button of the template form is on.
 *
 * A generation needs something to work from: a text that says what the course is to be, a file, or both. The button
 * follows the form: off with neither, on as soon as there is one, off again if both are taken away.
 *
 * @module     local_coursegen/local/courseai/template/generate_gate
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether the teacher wrote a request.
 *
 * @param {Object} tplState The template form state.
 * @returns {boolean}
 */
const hasRequest = (tplState) => typeof tplState.prompt === 'string' && tplState.prompt.trim() !== '';

/**
 * Whether the teacher attached a file.
 *
 * @param {Object} tplState The template form state.
 * @returns {boolean}
 */
const hasFile = (tplState) => Number.isInteger(tplState.syllabusdraftitemid) && tplState.syllabusdraftitemid > 0;

/**
 * Whether there is something to work from: a request, a file or both.
 *
 * @param {Object} tplState The template form state.
 * @returns {boolean}
 */
export const hasMaterial = (tplState) => hasRequest(tplState) || hasFile(tplState);

/**
 * Whether a generation may start now.
 *
 * @param {Object} tplState The template form state.
 * @param {boolean} generating True while a generation is on screen.
 * @returns {boolean}
 */
export const canStart = (tplState, generating) => Boolean(tplState.loaded) && !generating && hasMaterial(tplState);

/**
 * Put the Generate button on or off to match the form.
 *
 * While a generation is on screen the button is the send button of the review, so it is left as it is.
 *
 * @param {Object} tplState The template form state.
 * @param {{disabled: boolean}|null} button The Generate button.
 * @param {boolean} generating True while a generation is on screen.
 */
export const refreshGenerateButton = (tplState, button, generating) => {
    if (!button || generating) {
        return;
    }
    button.disabled = !canStart(tplState, generating);
};

/**
 * Keep the request of the state equal to the text field, from the text already in it.
 *
 * @param {HTMLTextAreaElement|null} field The text field of the request.
 * @param {Object} tplState The template form state.
 * @param {Function} onChange Called after the request changed.
 */
export const trackPrompt = (field, tplState, onChange) => {
    if (!field) {
        return;
    }
    tplState.prompt = field.value;
    field.addEventListener('input', () => {
        tplState.prompt = field.value;
        onChange();
    });
    onChange();
};

/**
 * Keep the file the teacher picked.
 *
 * @param {Object} tplState The template form state.
 * @param {string} filename Name of the file.
 * @param {number} draftItemId Draft area that holds the file.
 * @param {Function} onChange Called after the file changed.
 */
export const attachSyllabus = (tplState, filename, draftItemId, onChange) => {
    tplState.syllabusfilename = filename;
    tplState.syllabusdraftitemid = draftItemId;
    onChange();
};

/**
 * Forget the file the teacher picked.
 *
 * @param {Object} tplState The template form state.
 * @param {Function} onChange Called after the file changed.
 */
export const removeSyllabus = (tplState, onChange) => {
    tplState.syllabusfilename = '';
    tplState.syllabusdraftitemid = 0;
    onChange();
};
