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
 * What the teacher sends when the AI asks for a file and there is none.
 *
 * @module     local_coursegen/local/courseai/template/question_answer
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The answer of a teacher who has no file: a text, so the AI asks for the content instead.
 *
 * @param {string} sentence What the teacher says, in the language of the page, for example "I do not have a file for this."
 * @returns {{kind: string, answer: {text: string}}} The kind and the body of the answer.
 */
export const answerWithoutFile = (sentence) => ({kind: 'text', answer: {text: String(sentence).trim()}});

/**
 * Whether a question lets the teacher say there is no file: only a question that asks for one does.
 *
 * @param {string} kind The kind of the question: file, text or choice.
 * @returns {boolean}
 */
export const allowsNoFile = (kind) => kind === 'file';
