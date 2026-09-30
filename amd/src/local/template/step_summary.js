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
 * Step 5: Template summary + name form (moodleform rendered server-side).
 *
 * @module     local_coursegen/local/template/step_summary
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from 'local_coursegen/local/template/init';

let bound = false;

/** @type {Object} Section behavior -> badge label. */
const SECTION_LABELS = {custom: 'Customised', keep: 'Intact', exclude: 'Excluded'};

/** @type {Object} Activity action -> badge label. */
const ACTIVITY_LABELS = {modify: 'Modify', keep: 'Intact', reference: 'Ref', exclude: 'Exclude'};

/**
 * Keep the template name in state while it is typed.
 *
 * @param {InputEvent} e
 */
const handleNameInput = (e) => {
    setState({templateName: e.target.value});
};

/**
 * Keep the template description in state while it is typed.
 *
 * @param {InputEvent} e
 */
const handleDescInput = (e) => {
    setState({templateDesc: e.target.value});
};

/**
 * Stop the name form from being submitted natively.
 *
 * @param {SubmitEvent} e
 */
const preventSubmit = (e) => {
    e.preventDefault();
};

/**
 * Add a listener to an element, when it exists.
 *
 * @param {HTMLElement|null} element
 * @param {string} type The event type.
 * @param {Function} handler
 */
const listen = (element, type, handler) => {
    if (!element) {
        return;
    }
    element.addEventListener(type, handler);
};

/**
 * Count the sections kept intact and the ones excluded.
 *
 * @param {Object} state
 * @returns {{keep: number, exclude: number}}
 */
const countSectionBehaviors = (state) => {
    const secStats = {keep: 0, exclude: 0};
    const behaviors = Object.values(state.sectionBehavior);
    for (const behavior of behaviors) {
        if (behavior === 'keep') {
            secStats.keep++;
        }
        if (behavior === 'exclude') {
            secStats.exclude++;
        }
    }
    return secStats;
};

/**
 * Count the activities per action, and the modifiable ones without a prompt.
 *
 * @param {Object} state
 * @returns {Object}
 */
const countActivityActions = (state) => {
    const stats = {modify: 0, keep: 0, reference: 0, exclude: 0, noPrompt: 0};
    const entries = Object.entries(state.activityAction);
    for (const [id, action] of entries) {
        stats[action] = (stats[action] || 0) + 1;
        if (action === 'modify' && !state.activityPrompt[id]) {
            stats.noPrompt++;
        }
    }
    return stats;
};

/**
 * Build a summary column.
 *
 * @param {string} label
 * @param {*} value
 * @returns {string}
 */
const col = (label, value) =>
    `<div class="col-6 mb-2"><p class="small text-muted mb-0">${label}</p>` +
    `<p class="small font-weight-bold mb-0">${value}</p></div>`;

/**
 * The label of the maximum number of sections.
 *
 * @param {Object} state
 * @returns {string|number}
 */
const maxSectionsLabel = (state) => {
    if (state.noLimit) {
        return 'No limit';
    }
    return state.maxSections;
};

/**
 * The base course, limit and naming row of the summary.
 *
 * @param {Object} state
 * @returns {string}
 */
const summaryHeaderHtml = (state) => {
    const course = state.selectedCourse;
    const courseName = course?.fullname || '-';
    const maxSections = maxSectionsLabel(state);
    let html = '<div class="row mb-3 pb-3 border-bottom">';
    html += col('Base course', courseName);
    html += col('Max sections', maxSections);
    html += col('Naming', state.namingPattern);
    html += '</div>';
    return html;
};

/**
 * The counters line of the summary.
 *
 * @param {Object} stats Activities per action.
 * @param {{keep: number, exclude: number}} secStats Sections kept and excluded.
 * @returns {string}
 */
const summaryCountsHtml = (stats, secStats) => {
    let html = '<div class="d-flex flex-wrap mb-3 small">';
    html += `<span class="mr-3">${stats.modify} modifiable</span>`;
    html += `<span class="mr-3">${stats.keep + secStats.keep} intact</span>`;
    html += `<span class="mr-3">${stats.reference} ref only</span>`;
    html += `<span class="mr-3">${stats.exclude + secStats.exclude} excluded</span>`;
    html += '</div>';
    return html;
};

/**
 * The warning about modifiable activities without a prompt, if there are any.
 *
 * @param {Object} stats Activities per action.
 * @returns {string}
 */
const noPromptAlertHtml = (stats) => {
    if (stats.noPrompt <= 0) {
        return '';
    }
    let html = `<div class="alert alert-warning small py-2 mb-3">`;
    html += `${stats.noPrompt} activity(ies) marked as "Modify" without a prompt.`;
    html += '</div>';
    return html;
};

/**
 * One section row of the tree.
 *
 * @param {Object} section
 * @param {string} label The section's behavior badge.
 * @returns {string}
 */
const sectionRowHtml = (section, label) => {
    let html = `<div class="d-flex align-items-center py-1 small">`;
    html += `<i class="icon fa fa-folder-o fa-fw mr-1"></i>`;
    html += `<span class="flex-grow-1">${section.name}</span>`;
    html += `<span class="badge badge-secondary badge-pill">${label}</span></div>`;
    return html;
};

/**
 * One activity row of the tree.
 *
 * @param {Object} activity
 * @param {string} label The activity's action badge.
 * @returns {string}
 */
const activityRowHtml = (activity, label) => {
    let html = `<div class="d-flex align-items-center py-1 pl-4 small text-muted">`;
    html += `<i class="icon fa fa-puzzle-piece fa-fw mr-1"></i>`;
    html += `<span class="flex-grow-1">${activity.name}</span>`;
    html += `<span class="badge badge-secondary badge-pill">${label}</span></div>`;
    return html;
};

/**
 * The activity rows of one section.
 *
 * @param {Object} section
 * @param {Object} state
 * @returns {string}
 */
const activityRowsHtml = (section, state) => {
    let html = '';
    for (const activity of section.activities) {
        const action = state.activityAction[activity.id] || 'modify';
        const label = ACTIVITY_LABELS[action];
        html += activityRowHtml(activity, label);
    }
    return html;
};

/**
 * Build the section/activity tree.
 *
 * @param {Array} structure
 * @param {Object} state
 * @returns {string}
 */
const buildTreeHtml = (structure, state) => {
    let html = '';
    for (const section of structure) {
        const behavior = state.sectionBehavior[section.id] || 'aimodify';
        const label = SECTION_LABELS[behavior];
        html += sectionRowHtml(section, label);
        if (behavior !== 'aimodify') {
            continue;
        }
        html += activityRowsHtml(section, state);
    }
    return html;
};

/**
 * Build the configuration summary HTML.
 *
 * @param {Object} state
 * @returns {string}
 */
const buildSummaryHtml = (state) => {
    const structure = state.courseStructure || [];
    const secStats = countSectionBehaviors(state);
    const stats = countActivityActions(state);

    let html = '<div class="card p-3">';
    html += summaryHeaderHtml(state);
    html += summaryCountsHtml(stats, secStats);
    html += noPromptAlertHtml(stats);
    html += buildTreeHtml(structure, state);
    html += '</div>';
    return html;
};

/**
 * Render the summary into [data-region="save-summary"] and bind form events.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepSummary = (panel, state) => {
    const container = panel.querySelector('[data-region="save-summary"]');
    if (container) {
        container.innerHTML = buildSummaryHtml(state);
    }

    // Populate moodleform fields from state.
    const nameInput = panel.querySelector('#id_templatename');
    const descInput = panel.querySelector('#id_templatedesc');
    if (nameInput && state.templateName) {
        nameInput.value = state.templateName;
    }
    if (descInput && state.templateDesc) {
        descInput.value = state.templateDesc;
    }

    if (bound) {
        return;
    }
    bound = true;
    // Sync moodleform inputs back to state.
    listen(nameInput, 'input', handleNameInput);
    listen(descInput, 'input', handleDescInput);

    // Prevent moodleform submit.
    const form = panel.querySelector('#tpl-name-form');
    listen(form, 'submit', preventSubmit);
};
