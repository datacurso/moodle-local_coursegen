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
 * The conversation the professor reads while their course is built.
 *
 * Course creation without a template narrates itself in the left column: the
 * instruction as the opening turn and a turn at every milestone. That column
 * is where the run is actually followed, so it is reproduced here rather than
 * approximated, reusing the same elements and the same classes.
 *
 * @module     local_coursegen/local/courseai/template/thread
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import Templates from 'core/templates';
import {createLog} from 'local_coursegen/local/courseai/ui/log';
import {resetChecklist} from 'local_coursegen/local/courseai/template/generation_checklist';

const STRING_KEYS = [
    'courseai_template_log_starting',
    'courseai_template_log_writing',
    'courseai_template_log_review_ready',
    'courseai_template_log_approved',
    'courseai_template_log_adjusting',
    'courseai_template_log_completed',
];

let labels = null;
let log = null;

/**
 * The batched string request for every thread string key.
 *
 * @param {Array<string>} keys
 * @returns {Array<Object>}
 */
const threadStringRequests = (keys) => keys.map((key) => ({key, component: 'local_coursegen'}));

/**
 * Copy the fetched thread strings onto the labels map, keyed by string id.
 *
 * @param {Object} target
 * @param {Array<string>} keys
 * @param {Array<string>} values
 * @returns {void}
 */
const assignThreadLabels = (target, keys, values) => {
    keys.forEach((key, index) => {
        target[key] = values[index];
    });
};

/**
 * The thread's localised strings, fetched once.
 *
 * @returns {Promise<Object>} Keyed by string id.
 */
const getLabels = async() => {
    if (!labels) {
        const requests = threadStringRequests(STRING_KEYS);
        const values = await getStrings(requests);
        labels = {};
        assignThreadLabels(labels, STRING_KEYS, values);
    }
    return labels;
};

/**
 * The feed the run's turns are added to.
 *
 * @returns {Object} The log controller.
 */
const getLog = () => {
    if (!log) {
        log = createLog({container: document.getElementById('cgLog')});
    }
    return log;
};

/**
 * Append one turn to the thread.
 *
 * @param {string} actor 'user' or 'ai'.
 * @param {string} kind Visual kind, as the log module names them.
 * @param {string} message
 * @param {boolean} [markdown] Whether to render the message as Markdown.
 */
export const turn = (actor, kind, message, markdown = false) => {
    if (!String(message || '').trim()) {
        return;
    }
    getLog().add({actor, kind, message, markdown});
};

/**
 * Announce a milestone, by its string id.
 *
 * @param {string} key One of STRING_KEYS.
 * @param {string} [actor]
 * @param {string} [kind]
 * @returns {Promise<void>}
 */
export const milestone = async(key, actor = 'ai', kind = 'ai') => {
    const texts = await getLabels();
    turn(actor, kind, texts[key]);
};

/**
 * Open the thread with what the course is generated with, and put the picker away.
 *
 * Once the run starts the picker and the composer are no longer controls:
 * changing them would not change anything. So the picker leaves, and the
 * template, language, syllabus and instruction become the first message of
 * the conversation, which is where the rest of the run is recorded anyway.
 * A course reopened later shows the same message, rendered by the page.
 *
 * @param {Object} summary {template, language, syllabus, instruction}.
 * @returns {Promise<void>}
 */
export const announceTemplate = async(summary) => {
    const card = document.getElementById('templateModeCard');
    if (card) {
        card.hidden = true;
    }
    const node = document.getElementById('cgLog');
    if (!node) {
        return;
    }
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_run_summary', {
        template: summary.template || '',
        language: summary.language || '',
        hassyllabusrow: true,
        syllabus: summary.syllabus || '',
        instruction: String(summary.instruction || '').trim(),
    });
    Templates.prependNodeContents(node, html, js);
};

/**
 * Bring the picker back, for a run that ended without a course.
 */
export const restorePicker = () => {
    const card = document.getElementById('templateModeCard');
    if (card) {
        card.hidden = false;
    }
};

/**
 * Empty the thread and the progress list, for a run that starts over.
 */
export const resetThread = () => {
    log = null;
    const node = document.getElementById('cgLog');
    if (node) {
        node.innerHTML = '';
    }
    resetChecklist();
};
