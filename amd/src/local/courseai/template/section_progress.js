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
 * The progress of a template generation in the left column, written the way free creation writes its plan: one item
 * per section, with a spinner that becomes a check once all its activities are written, and under its name the text
 * of its activities, clamped with a "Show more" toggle.
 *
 * The sections and their activities are read from the structure on the right. The item of a section is kept in step
 * as the stream says which activity started and which finished, and how far the repeat blocks of an activity are.
 *
 * @module     local_coursegen/local/courseai/template/section_progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {renderMarkdown} from 'local_coursegen/local/courseai/ui/markdown';
import {clampDetail} from 'local_coursegen/local/courseai/ui/plan-transcript';
import {sectionMarkdown} from 'local_coursegen/local/courseai/template/section_markdown';

const SPINNER = '<span class="courseai-checklist-check">'
    + '<svg class="spinner-icon" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
    + '<svg class="check-icon" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>';

/** @type {Array<Object>} One entry per section: {name, activities, item, detail}. */
let sections = [];
let labelsPromise = null;

/**
 * The words the activity text uses, fetched once.
 *
 * @returns {Promise<Object>}
 */
const getLabels = () => {
    if (!labelsPromise) {
        labelsPromise = getStrings([
            {key: 'courseai_template_repeat_count', component: 'local_coursegen', param: {done: '{DONE}', total: '{TOTAL}'}},
            {key: 'courseai_template_repeat_includes', component: 'local_coursegen', param: '{NAMES}'},
        ]).then(([count, includes]) => ({
            count: (done, total) => count.replace('{DONE}', done).replace('{TOTAL}', total),
            includes: (names) => includes.replace('{NAMES}', names),
        }));
    }
    return labelsPromise;
};

/**
 * The text of a node, trimmed.
 *
 * @param {Element|null} node
 * @returns {string}
 */
const textOf = (node) => (node ? node.textContent.trim() : '');

/**
 * The sections of the structure on the right with the activities that are going to be written.
 *
 * @returns {Array<Object>}
 */
const readStructure = () => {
    const found = [];
    if (typeof document.querySelectorAll !== 'function') {
        return found;
    }
    for (const section of document.querySelectorAll('li.section')) {
        const activities = [];
        for (const row of section.querySelectorAll('li.activity[data-generation-uid]')) {
            activities.push({
                uid: row.getAttribute('data-generation-uid'),
                title: textOf(row.querySelector('.instancename')),
                type: textOf(row.querySelector('.cg-activity-desc')),
                state: 'pending',
                repeat: null,
            });
        }
        if (activities.length > 0) {
            found.push({name: textOf(section.querySelector('.sectionname span')), activities, item: null, detail: null});
        }
    }
    return found;
};

/**
 * The item of one section, in the markup free creation gives its section items.
 *
 * @param {Object} section
 * @returns {HTMLElement}
 */
const buildItem = (section) => {
    const item = document.createElement('li');
    item.className = 'courseai-checklist-item is-loading';
    item.setAttribute('data-remaining', String(section.activities.length));
    item.innerHTML = '<div class="courseai-checklist-head">' + SPINNER
        + '<span class="courseai-checklist-name"></span></div>'
        + '<div class="courseai-checklist-detail cg-log-md"></div>';
    item.querySelector('.courseai-checklist-name').textContent = section.name;
    return item;
};

/**
 * Write the text of one section.
 *
 * @param {Object} section
 * @returns {Promise<void>}
 */
const paintSection = async(section) => {
    if (!section.detail) {
        return;
    }
    const labels = await getLabels();
    section.detail.innerHTML = renderMarkdown(sectionMarkdown(section.activities, labels));
};

/**
 * The section that holds an activity.
 *
 * @param {string} uid
 * @returns {Object|null}
 */
const sectionOf = (uid) => sections.find((section) => section.activities.some((activity) => activity.uid === uid)) || null;

/**
 * Empty the sections, for a run that starts over.
 *
 * @param {Element|null} list The list the items were added to.
 */
export const resetSections = (list) => {
    sections = [];
    if (list) {
        list.innerHTML = '';
    }
};

/**
 * Build the item of every section into the list, from the structure on the right.
 *
 * @param {Element|null} list
 * @returns {Promise<void>}
 */
export const openSections = async(list) => {
    resetSections(list);
    if (!list) {
        return;
    }
    sections = readStructure();
    for (const section of sections) {
        section.item = buildItem(section);
        section.detail = section.item.querySelector('.courseai-checklist-detail');
        list.appendChild(section.item);
        await paintSection(section);
    }
};

/**
 * An activity started: it carries the name the AI gave it.
 *
 * @param {string} uid
 * @param {string} name
 * @returns {Promise<void>}
 */
export const startActivity = async(uid, name) => {
    const section = sectionOf(uid);
    if (!section) {
        return;
    }
    const activity = section.activities.find((candidate) => candidate.uid === uid);
    activity.state = 'running';
    if (name) {
        activity.title = name;
    }
    await paintSection(section);
};

/**
 * How far the repeat blocks of an activity are.
 *
 * @param {string} uid
 * @param {Object} summary {done, total, last, names}
 * @returns {Promise<void>}
 */
export const setActivityRepeat = async(uid, summary) => {
    const section = sectionOf(uid);
    if (!section) {
        return;
    }
    section.activities.find((candidate) => candidate.uid === uid).repeat = summary;
    await paintSection(section);
};

/**
 * An activity is written; the section turns into a check once every one of its activities is.
 *
 * @param {string} uid
 * @returns {Promise<void>}
 */
export const finishActivity = async(uid) => {
    const section = sectionOf(uid);
    if (!section) {
        return;
    }
    const activity = section.activities.find((candidate) => candidate.uid === uid);
    activity.state = 'done';
    if (activity.repeat) {
        activity.repeat = {...activity.repeat, done: activity.repeat.total};
    }
    await paintSection(section);
    const pending = section.activities.filter((candidate) => candidate.state !== 'done').length;
    section.item.setAttribute('data-remaining', String(pending));
    if (pending === 0) {
        section.item.classList.remove('is-loading');
        section.item.classList.add('is-done');
        clampDetail(section.detail);
    }
};
