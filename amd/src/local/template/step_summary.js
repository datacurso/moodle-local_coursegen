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
 * Step 6: Template summary before saving.
 *
 * @module     local_coursegen/local/template/step_summary
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/** @type {Object<string, string>} Icon map per activity action. */
const ACTION_ICON = {modify: '&#10024;', keep: '&#128274;', reference: '&#128218;', exclude: '&#128683;'};

/** @type {Object<string, string>} Label map per activity action. */
const ACTION_LABEL = {modify: 'Modify', keep: 'Intact', reference: 'Ref only', exclude: 'Exclude'};

/** @type {Object<string, string>} CSS badge class map per activity action. */
const ACTION_BADGE = {
    modify: 'tpl-badge-modify',
    reference: 'tpl-badge-reference',
    keep: 'tpl-badge-keep',
    exclude: 'tpl-badge-exclude',
};

/**
 * Compute aggregate statistics from state.
 *
 * @param {Object} state
 * @returns {{stats: Object, sectionStats: Object}}
 */
const computeStats = (state) => {
    const stats = {modify: 0, keep: 0, reference: 0, exclude: 0, withRef: 0, noPrompt: 0};
    const sectionStats = {keep: 0, exclude: 0};

    Object.entries(state.sectionBehavior).forEach(([, v]) => {
        if (v === 'keep') {
            sectionStats.keep++;
        }
        if (v === 'exclude') {
            sectionStats.exclude++;
        }
    });

    Object.entries(state.activityAction).forEach(([id, v]) => {
        stats[v] = (stats[v] || 0) + 1;
        if (state.activityRef[id]) {
            stats.withRef++;
        }
        if (v === 'modify' && !state.activityPrompt[id]) {
            stats.noPrompt++;
        }
    });

    return {stats, sectionStats};
};

/**
 * Build the section/activity tree HTML.
 *
 * @param {Array} structure
 * @param {Object} state
 * @returns {string}
 */
const buildTreeHtml = (structure, state) => {
    let html = '';

    structure.forEach(s => {
        const behavior = state.sectionBehavior[s.id] || 'custom';
        const icon = behavior === 'keep' ? '&#128274;'
            : behavior === 'exclude' ? '&#128683;' : '&#127919;';
        const label = behavior === 'keep' ? 'Intact'
            : behavior === 'exclude' ? 'Excluded' : 'Customised';
        const badgeCls = behavior === 'keep' ? 'tpl-badge-keep'
            : behavior === 'exclude' ? 'tpl-badge-exclude' : '';

        html += `<div class="d-flex align-items-center py-1 small">
            <i class="icon fa fa-folder-o fa-fw mr-1"></i>
            <span class="flex-grow-1 text-truncate">${s.name}</span>
            <span class="tpl-badge ${badgeCls}">${icon} ${label}</span>
        </div>`;

        if (behavior !== 'custom') {
            return;
        }

        s.activities.forEach(a => {
            const action = state.activityAction[a.id] || 'modify';
            const ref = state.activityRef[a.id] !== false;
            const hasPrompt = !!state.activityPrompt[a.id];

            html += `<div class="d-flex align-items-center py-1 pl-4 small text-muted">
                <i class="icon fa fa-puzzle-piece fa-fw mr-1"></i>
                <span class="flex-grow-1 text-truncate">${a.name}</span>
                <span class="tpl-badge ${ACTION_BADGE[action]} mr-1">
                    ${ACTION_ICON[action]} ${ACTION_LABEL[action]}
                </span>
                ${action !== 'exclude' && ref
                    ? '<span class="tpl-badge small">&#128269; ref</span>' : ''}
                ${action === 'modify' && !hasPrompt
                    ? '<span class="tpl-warn ml-1">No prompt</span>' : ''}
            </div>`;
        });
    });

    return html;
};

/**
 * Render step 6 panel — read-only summary of the entire template configuration.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepSummary = (panel, state) => {
    const structure = state.courseStructure || [];
    const course = state.selectedCourse;
    const {stats, sectionStats} = computeStats(state);
    const treeHtml = buildTreeHtml(structure, state);

    panel.innerHTML = `
        <h3 class="h5 mb-1">Template summary</h3>
        <p class="small text-muted mb-3">Review everything before saving</p>

        <div class="card p-3 mb-3">
            <div class="row mb-3 pb-3 border-bottom">
                <div class="col-6">
                    <p class="small text-muted mb-0">Name</p>
                    <p class="small font-weight-bold mb-0">
                        ${state.templateName || '(unnamed)'}
                    </p>
                </div>
                <div class="col-6">
                    <p class="small text-muted mb-0">Base course</p>
                    <p class="small font-weight-bold mb-0">${course?.fullname || '-'}</p>
                </div>
                <div class="col-6 mt-2">
                    <p class="small text-muted mb-0">Max sections</p>
                    <p class="small font-weight-bold mb-0">
                        ${state.noLimit ? 'No limit' : state.maxSections}
                    </p>
                </div>
                <div class="col-6 mt-2">
                    <p class="small text-muted mb-0">Allowed types</p>
                    <p class="small font-weight-bold mb-0">
                        ${state.allowedTypes.length} types
                    </p>
                </div>
                <div class="col-6 mt-2">
                    <p class="small text-muted mb-0">Naming pattern</p>
                    <p class="small font-weight-bold mb-0">${state.namingPattern}</p>
                </div>
            </div>

            <p class="small font-weight-bold text-muted mb-2">Configuration summary</p>
            <div class="d-flex flex-wrap mb-3 small">
                <span class="mr-3">&#10024; ${stats.modify} modifiable</span>
                <span class="mr-3">&#128274; ${stats.keep + sectionStats.keep} intact</span>
                <span class="mr-3">&#128218; ${stats.reference} ref only</span>
                <span class="mr-3">&#128683; ${stats.exclude + sectionStats.exclude} excluded</span>
                <span class="tpl-badge">&#128269; ${stats.withRef} as ref</span>
            </div>

            ${stats.noPrompt > 0 ? `<div class="alert alert-warning small py-2 mb-3">
                ${stats.noPrompt} activity(ies) marked as "Modify" without a prompt.
                AI will use structure as reference.
            </div>` : ''}

            ${treeHtml}
        </div>

        <div class="tpl-summary-preview p-3">
            <p class="small font-weight-bold mb-1">User preview</p>
            <p class="small text-muted mb-2">
                When someone uses this template, they will see:
            </p>
            <div class="d-flex align-items-center small bg-white p-2 rounded border">
                <span>Template:
                    <strong>${state.templateName || '(unnamed)'}</strong>
                </span>
                <span class="tpl-badge ml-2">${structure.length} sections</span>
                <span class="tpl-badge ml-1">${stats.modify} modifiable</span>
            </div>
        </div>`;
};
