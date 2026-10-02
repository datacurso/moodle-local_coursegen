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
 * The list of activities a template generation is writing, in the left column.
 *
 * Course creation without a template shows its progress as a checklist whose
 * rows turn from a spinner into a check; this reproduces it with that same
 * markup (template_generation_item), one row per activity, added when the
 * activity starts and closed when it is written. The group head carries how
 * many are written out of how many there are.
 *
 * The stream delivers events faster than a row can be rendered, so every change
 * goes through one queue: rows appear in the order the activities started and a
 * row is never closed before it exists.
 *
 * @module     local_coursegen/local/courseai/template/generation_checklist
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getString} from 'core/str';

const ITEM_TEMPLATE = 'local_coursegen/template_generation_item';

let queue = Promise.resolve();

/**
 * Run a change after every earlier one has finished.
 *
 * @param {Function} task
 */
const enqueue = (task) => {
    queue = queue.then(task);
};

/**
 * The text of a node, trimmed, or '' when there is no node.
 *
 * @param {Element|null} node
 * @returns {string}
 */
const textOf = (node) => {
    if (!node) {
        return '';
    }
    return node.textContent.trim();
};

/**
 * What the structure on the right says about one activity: its type and the
 * name of its section, already localised by the server.
 *
 * @param {string} uid
 * @returns {Object} {typelabel, sectionname}
 */
const describeRow = (uid) => {
    const row = document.querySelector(`li.activity[data-generation-uid="${uid}"]`);
    if (!row) {
        return {typelabel: '', sectionname: ''};
    }
    const section = row.closest('li.section');
    const sectionName = section?.querySelector('.sectionname span');
    return {
        typelabel: textOf(row.querySelector('.cg-activity-desc')),
        sectionname: textOf(sectionName),
    };
};

/**
 * The list the rows are added to.
 *
 * @returns {Element|null}
 */
const listNode = () => document.getElementById('courseaiChecklistList');

/**
 * Show how many activities are written out of how many there are.
 *
 * @param {Object} progress {total, done}
 * @returns {Promise<void>}
 */
const paintCount = async(progress) => {
    const count = document.getElementById('courseaiChecklistCount');
    if (!count) {
        return;
    }
    count.textContent = await getString('courseai_template_progress_count', 'local_coursegen', {
        done: progress.done,
        total: progress.total,
    });
};

/**
 * Empty the list and hide it, for a run that starts over.
 */
export const resetChecklist = () => {
    enqueue(() => {
        const list = listNode();
        if (list) {
            list.innerHTML = '';
        }
        const checklist = document.getElementById('courseaiChecklist');
        if (checklist) {
            checklist.classList.add('hidden');
        }
    });
};

/**
 * Open the list for a pass that is going to write the given number of activities.
 *
 * @param {Object} progress {total, done}
 */
export const openChecklist = (progress) => {
    resetChecklist();
    enqueue(async() => {
        const checklist = document.getElementById('courseaiChecklist');
        if (checklist) {
            checklist.classList.remove('hidden');
        }
        await paintCount(progress);
    });
};

/**
 * Add the row of an activity that has just started.
 *
 * @param {Object} data An activity_progress_start event: uid and name.
 */
export const addActivity = (data) => {
    const context = {uid: data.uid, name: data.name || '', ...describeRow(data.uid)};
    enqueue(async() => {
        const list = listNode();
        if (!list) {
            return;
        }
        const {html, js} = await Templates.renderForPromise(ITEM_TEMPLATE, context);
        Templates.appendNodeContents(list, html, js);
    });
};

/**
 * Close the row of an activity that has just finished and update the count.
 *
 * @param {string} uid
 * @param {Object} progress {total, done}
 */
export const closeActivity = (uid, progress) => {
    const done = progress.done;
    const total = progress.total;
    enqueue(async() => {
        const item = document.querySelector(`#courseaiChecklistList [data-generation-uid="${uid}"]`);
        if (item) {
            item.classList.remove('is-loading');
            item.classList.add('is-done');
        }
        await paintCount({done, total});
    });
};
