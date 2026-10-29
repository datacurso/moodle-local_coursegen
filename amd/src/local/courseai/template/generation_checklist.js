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
import {progressRatio} from 'local_coursegen/local/courseai/template/progress_ratio';
import {closeActivitiesPanel} from 'local_coursegen/local/courseai/template/top_bar';
import {
    finishActivity,
    openSections,
    resetSections,
    setActivityRepeat,
    showWrittenSections,
    startActivity,
} from 'local_coursegen/local/courseai/template/section_progress';

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
const LIST_IDS = ['courseaiChecklistList'];
const SECTION_LIST_ID = 'courseaiLeftChecklistList';
const CARD_IDS = ['courseaiChecklist', 'courseaiLeftChecklist'];
const COUNT_IDS = ['courseaiChecklistCount', 'courseaiLeftChecklistCount'];

/**
 * The nodes of the page that have one of these ids; a page may lack some of them.
 *
 * @param {string[]} ids
 * @returns {Element[]}
 */
const nodesOf = (ids) => {
    const found = [];
    for (const id of ids) {
        const node = document.getElementById(id);
        if (node) {
            found.push(node);
        }
    }
    return found;
};

/**
 * Fill the meter of the progress card: the card carries the share written as a number from 0 to 1 that its style draws.
 *
 * @param {Object} progress {total, done}
 */
const paintMeter = (progress) => {
    const ratio = progressRatio(progress);
    const value = String(ratio);
    for (const checklist of nodesOf(CARD_IDS)) {
        checklist.style.setProperty('--cg-progress', value);
    }
};

/**
 * Show how many activities are written out of how many there are, in words, on the pill and in the meter.
 *
 * @param {Object} progress {total, done}
 * @returns {Promise<void>}
 */
const paintCount = async(progress) => {
    paintMeter(progress);
    const words = {done: progress.done, total: progress.total};
    const text = await getString('courseai_template_progress_count', 'local_coursegen', words);
    for (const count of nodesOf(COUNT_IDS)) {
        count.textContent = text;
    }
    // The pill of the top bar says the same in a few characters.
    const shortCount = document.getElementById('tplTopActivitiesCount');
    if (shortCount) {
        shortCount.textContent = await getString('courseai_template_progress_short', 'local_coursegen', words);
    }
};

/**
 * Empty the list and hide it, for a run that starts over.
 */
export const resetChecklist = () => {
    enqueue(() => {
        closeActivitiesPanel();
        for (const list of nodesOf(LIST_IDS)) {
            list.innerHTML = '';
        }
        resetSections(document.getElementById(SECTION_LIST_ID));
        for (const checklist of nodesOf(CARD_IDS)) {
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
        for (const checklist of nodesOf(CARD_IDS)) {
            checklist.classList.remove('hidden');
        }
        await openSections(document.getElementById(SECTION_LIST_ID));
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
        const lists = nodesOf(LIST_IDS);
        if (lists.length === 0) {
            return;
        }
        const {html, js} = await Templates.renderForPromise(ITEM_TEMPLATE, context);
        for (const list of lists) {
            Templates.appendNodeContents(list, html, js);
        }
    });
    enqueue(() => startActivity(data.uid, data.name || ''));
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
        for (const item of document.querySelectorAll(`.courseai-checklist-list [data-progress-uid="${uid}"]`)) {
            item.classList.remove('is-loading');
            item.classList.add('is-done');
        }
        await paintCount({done, total});
        await finishActivity(uid);
    });
};

/**
 * Show how many items of the repeat blocks of an activity are written, and the last one, in its rows.
 *
 * @param {string} uid
 * @param {Object} summary {done, total, last} from the repeat tracker.
 */
export const showRepeatProgress = (uid, summary) => {
    enqueue(() => setActivityRepeat(uid, summary));
    enqueue(async() => {
        const text = await getString('courseai_template_repeat_count', 'local_coursegen', {
            done: summary.done,
            total: summary.total,
        });
        const ratio = summary.total > 0 ? Math.min(1, summary.done / summary.total) : 0;
        for (const item of document.querySelectorAll(`.courseai-checklist-list [data-progress-uid="${uid}"]`)) {
            const box = item.querySelector('.courseai-checklist-repeat');
            if (!box) {
                continue;
            }
            box.hidden = false;
            box.style.setProperty('--cg-repeat', String(ratio));
            const countNode = box.querySelector('.courseai-checklist-repeat-count');
            const lastNode = box.querySelector('.courseai-checklist-repeat-last');
            if (countNode) {
                countNode.textContent = text;
            }
            if (lastNode) {
                lastNode.textContent = summary.last || '';
            }
        }
    });
};

/**
 * Show the activities a finished run wrote, section by section, for a conversation replayed after a reload.
 *
 * @param {Array<Object>} written [{name, activities: [{title, type}]}]
 */
export const showWrittenChecklist = (written) => {
    const total = (written || []).reduce((count, section) => count + (section.activities || []).length, 0);
    if (total === 0) {
        return;
    }
    enqueue(async() => {
        for (const checklist of nodesOf(CARD_IDS)) {
            checklist.classList.remove('hidden');
        }
        await showWrittenSections(document.getElementById(SECTION_LIST_ID), written);
        // The panel of the top bar lists them one by one, already written.
        for (const section of written) {
            for (const activity of section.activities || []) {
                const context = {
                    uid: '',
                    name: activity.title || '',
                    typelabel: activity.type || '',
                    sectionname: section.name || '',
                };
                // eslint-disable-next-line no-await-in-loop
                const {html} = await Templates.renderForPromise(ITEM_TEMPLATE, context);
                for (const list of nodesOf(LIST_IDS)) {
                    list.insertAdjacentHTML('beforeend', html);
                    list.lastElementChild?.classList.replace('is-loading', 'is-done');
                }
            }
        }
        await paintCount({done: total, total});
    });
};
