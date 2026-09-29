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
 * instruction as the opening turn, a checklist that fills in as the plan is
 * written, each row opening to its own detail, and a turn at every milestone.
 * That column is where the run is actually followed, so it is reproduced here
 * rather than approximated, reusing the same elements and the same classes.
 *
 * The checklist itself - one row per section, filled in as activities plan -
 * lives in thread_checklist.js; this module is the surrounding conversation
 * and the milestones announced in it.
 *
 * @module     local_coursegen/local/courseai/template/thread
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {createLog} from 'local_coursegen/local/courseai/ui/log';
import {resetChecklist} from 'local_coursegen/local/courseai/template/thread_checklist';

const STRING_KEYS = [
    'courseai_template_log_selected',
    'courseai_template_log_planning',
    'courseai_template_log_plan_ready',
    'courseai_template_log_approved',
    'courseai_template_log_adjusting',
    'courseai_template_log_generating',
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
 * The feed, routed the way free mode routes its own.
 *
 * Turns land above the checklist until the plan exists and below it afterwards,
 * so the thread reads in the order things happened instead of piling every turn
 * at the top.
 *
 * @returns {Object} The log controller.
 */
const getLog = () => {
    if (!log) {
        log = createLog({
            container: document.getElementById('cgLog'),
            actionContainer: document.getElementById('cgLogAfter'),
            isActionPhase: () => {
                const list = document.getElementById('courseaiChecklistList');
                return !!(list && list.children.length);
            },
        });
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
 * Say which template the run is built on, and put the picker away.
 *
 * Once the run starts the picker is no longer a control: changing it would not
 * change anything. So it leaves, and what it said becomes the first thing in
 * the conversation, which is where the rest of the run is recorded anyway.
 *
 * @param {string} name The chosen template's name.
 * @returns {Promise<void>}
 */
export const announceTemplate = async(name) => {
    const card = document.getElementById('templateModeCard');
    if (card) {
        card.hidden = true;
    }
    const texts = await getLabels();
    turn('user', 'user', texts.courseai_template_log_selected.replace('{$a}', name || ''));
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
 * Empty the thread and the checklist, for a run that starts over.
 */
export const resetThread = () => {
    log = null;
    ['cgLog', 'cgLogAfter'].forEach((id) => {
        const node = document.getElementById(id);
        if (node) {
            node.innerHTML = '';
        }
    });
    resetChecklist();
};
