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

import {setState} from './init';

let bound = false;

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

    if (!bound) {
        bound = true;
        // Sync moodleform inputs back to state.
        nameInput?.addEventListener('input', (e) => setState({templateName: e.target.value}));
        descInput?.addEventListener('input', (e) => setState({templateDesc: e.target.value}));

        // Prevent moodleform submit.
        const form = panel.querySelector('#tpl-name-form');
        form?.addEventListener('submit', (e) => e.preventDefault());
    }
};

/**
 * Build the configuration summary HTML.
 *
 * @param {Object} state
 * @returns {string}
 */
const buildSummaryHtml = (state) => {
    const structure = state.courseStructure || [];
    const course = state.selectedCourse;
    const stats = {modify: 0, keep: 0, reference: 0, exclude: 0, noPrompt: 0};
    const secStats = {keep: 0, exclude: 0};

    Object.values(state.sectionBehavior).forEach(v => {
        if (v === 'keep') { secStats.keep++; }
        if (v === 'exclude') { secStats.exclude++; }
    });
    Object.entries(state.activityAction).forEach(([id, v]) => {
        stats[v] = (stats[v] || 0) + 1;
        if (v === 'modify' && !state.activityPrompt[id]) { stats.noPrompt++; }
    });

    let html = '<div class="card p-3">';
    html += '<div class="row mb-3 pb-3 border-bottom">';
    html += col('Base course', course?.fullname || '-');
    html += col('Max sections', state.noLimit ? 'No limit' : state.maxSections);
    html += col('Allowed types', state.allowedTypes.length + ' types');
    html += col('Naming', state.namingPattern);
    html += '</div>';

    html += '<div class="d-flex flex-wrap mb-3 small">';
    html += `<span class="mr-3">${stats.modify} modifiable</span>`;
    html += `<span class="mr-3">${stats.keep + secStats.keep} intact</span>`;
    html += `<span class="mr-3">${stats.reference} ref only</span>`;
    html += `<span class="mr-3">${stats.exclude + secStats.exclude} excluded</span>`;
    html += '</div>';

    if (stats.noPrompt > 0) {
        html += `<div class="alert alert-warning small py-2 mb-3">`;
        html += `${stats.noPrompt} activity(ies) marked as "Modify" without a prompt.`;
        html += '</div>';
    }

    html += buildTreeHtml(structure, state);
    html += '</div>';
    return html;
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
 * Build the section/activity tree.
 *
 * @param {Array} structure
 * @param {Object} state
 * @returns {string}
 */
const buildTreeHtml = (structure, state) => {
    let html = '';
    structure.forEach(s => {
        const beh = state.sectionBehavior[s.id] || 'custom';
        const label = {custom: 'Customised', keep: 'Intact', exclude: 'Excluded'}[beh];
        html += `<div class="d-flex align-items-center py-1 small">`;
        html += `<i class="icon fa fa-folder-o fa-fw mr-1"></i>`;
        html += `<span class="flex-grow-1">${s.name}</span>`;
        html += `<span class="badge badge-secondary badge-pill">${label}</span></div>`;

        if (beh !== 'custom') { return; }
        s.activities.forEach(a => {
            const act = state.activityAction[a.id] || 'modify';
            const lbl = {modify: 'Modify', keep: 'Intact', reference: 'Ref', exclude: 'Exclude'}[act];
            html += `<div class="d-flex align-items-center py-1 pl-4 small text-muted">`;
            html += `<i class="icon fa fa-puzzle-piece fa-fw mr-1"></i>`;
            html += `<span class="flex-grow-1">${a.name}</span>`;
            html += `<span class="badge badge-secondary badge-pill">${lbl}</span></div>`;
        });
    });
    return html;
};
