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
 * Step 4: Configure sections and activities.
 *
 * @module     local_coursegen/local/template/step_sections
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

/**
 * Render step 4 panel.
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepSections = (panel, state) => {
    const structure = state.courseStructure || [];
    panel.innerHTML = `<h3 class="h5 mb-1">Configure sections</h3>
        <p class="small text-muted mb-3">Decide what AI does with each part of the course</p>
        <div data-region="sections-config"></div>`;
    renderSectionsConfig(panel.querySelector('[data-region="sections-config"]'), state, structure);
};

/**
 * Render all section cards.
 * @param {HTMLElement} container
 * @param {Object} state
 * @param {Array} structure
 */
const renderSectionsConfig = (container, state, structure) => {
    let html = '';
    structure.forEach(s => {
        const behavior = state.sectionBehavior[s.id] || 'custom';
        const cardCls = behavior === 'keep' ? 'kept' : behavior === 'exclude' ? 'excluded' : '';

        html += `<div class="tpl-section-card ${cardCls}">`;
        html += renderSectionHeader(s, behavior);
        html += `<div class="tpl-section-body ${behavior === 'custom' ? 'open' : ''}">`;
        html += `<div class="px-3 py-2">`;

        if (behavior === 'custom') {
            html += `<div class="d-flex justify-content-end mb-2 position-relative">
                <button class="btn btn-outline-secondary btn-sm" data-action="toggle-quick" data-sid="${s.id}">
                    Quick actions
                </button>
                <div class="tpl-quick-menu d-none" data-region="quick-menu-${s.id}">
                    <button data-action="bulk" data-sid="${s.id}" data-val="keep">Keep all intact</button>
                    <button data-action="bulk" data-sid="${s.id}" data-val="modify">Modify all</button>
                    <button data-action="bulk" data-sid="${s.id}" data-val="reference">Reference only</button>
                </div>
            </div>`;
            s.activities.forEach(a => {
                html += renderActivityRow(a, state);
            });
        } else if (behavior === 'keep') {
            html += `<div class="py-3 text-center small text-muted">
                This section will be copied <strong>exactly as-is</strong> to the new course.
            </div>`;
        } else {
            html += `<div class="py-3 text-center small text-muted">
                This section <strong>will not appear</strong> in courses generated with this template.
            </div>`;
        }

        html += `</div></div></div>`;
    });
    container.innerHTML = html;
    bindSectionEvents(container, state, structure);
};
/**
 * Render a section header.
 * @param {Object} section
 * @param {string} behavior
 * @returns {string}
 */
const renderSectionHeader = (section, behavior) => {
    let badge = '';
    if (behavior === 'keep') {
        badge = '<span class="tpl-badge tpl-badge-keep ml-2">Intact</span>';
    } else if (behavior === 'exclude') {
        badge = '<span class="tpl-badge tpl-badge-exclude ml-2">Excluded</span>';
    }

    return `<div class="tpl-section-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <i class="icon fa fa-folder-o fa-fw mr-1"></i>
            <span class="small font-weight-bold">${section.name}</span>
            <span class="small text-muted ml-2">${section.activities.length} act.</span>
            ${badge}
        </div>
        <select class="custom-select custom-select-sm" style="width:auto"
                data-action="section-behavior" data-sid="${section.id}">
            <option value="custom" ${behavior === 'custom' ? 'selected' : ''}>Customised</option>
            <option value="keep" ${behavior === 'keep' ? 'selected' : ''}>Keep intact</option>
            <option value="exclude" ${behavior === 'exclude' ? 'selected' : ''}>Do not include</option>
        </select>
    </div>`;
};

/**
 * Render a single activity row.
 * @param {Object} activity
 * @param {Object} state
 * @returns {string}
 */
const renderActivityRow = (activity, state) => {
    const action = state.activityAction[activity.id] || 'modify';
    const ref = state.activityRef[activity.id] !== false;
    const prompt = state.activityPrompt[activity.id] || '';
    const refDisabled = action === 'modify' || action === 'reference';

    let actionBadge = '';
    if (action === 'reference') {
        actionBadge = '<span class="tpl-badge tpl-badge-reference ml-1">Ref only</span>';
    } else if (action === 'exclude') {
        actionBadge = '<span class="tpl-badge tpl-badge-exclude ml-1">Excluded</span>';
    }

    let html = `<div class="tpl-activity-row d-flex align-items-start small">
        <div class="flex-grow-1">
            <div class="d-flex align-items-center mb-1">
                <span class="tpl-badge mr-1">${activity.modname}</span>
                <span class="font-weight-bold text-truncate">${activity.name}</span>
                ${actionBadge}
            </div>
            <div class="d-flex align-items-center flex-wrap">
                <select class="custom-select custom-select-sm mr-2" style="width:auto;font-size:.75rem"
                        data-action="activity-action" data-aid="${activity.id}">
                    <option value="modify" ${action === 'modify' ? 'selected' : ''}>Modify with AI</option>
                    <option value="keep" ${action === 'keep' ? 'selected' : ''}>Keep intact</option>
                    <option value="reference" ${action === 'reference' ? 'selected' : ''}>Reference only</option>
                    <option value="exclude" ${action === 'exclude' ? 'selected' : ''}>Do not include</option>
                </select>`;

    if (action !== 'exclude') {
        html += `<label class="d-flex align-items-center small mb-0 ${refDisabled ? 'text-muted' : ''}">
                    <input type="checkbox" class="mr-1" data-action="activity-ref" data-aid="${activity.id}"
                           ${ref ? 'checked' : ''} ${refDisabled ? 'disabled' : ''}>
                    Use as reference
                </label>`;
    }

    html += `</div>`;

    if (action === 'modify') {
        html += `<div class="tpl-prompt-field tpl-fade-in">
            <textarea rows="1" data-action="activity-prompt" data-aid="${activity.id}"
                      placeholder="E.g.: Change content for consultative sales">${prompt}</textarea>
            ${!prompt
                ? '<p class="tpl-warn mb-0 mt-1">No prompt — AI will use structure as reference</p>'
                : ''}
        </div>`;
    } else if (action === 'reference') {
        html += `<div class="small p-2 rounded tpl-fade-in mt-1" style="background:#ede7f6">
            AI will use this activity as <strong>reference</strong> for tone and depth.
            Not copied to new course.
        </div>`;
    }

    html += `</div></div>`;
    return html;
};

/**
 * Bind events for section configuration.
 * @param {HTMLElement} container
 * @param {Object} state
 * @param {Array} structure
 */
const bindSectionEvents = (container, state, structure) => {
    container.querySelectorAll('[data-action="section-behavior"]').forEach(sel => {
        sel.addEventListener('change', () => {
            state.sectionBehavior[parseInt(sel.dataset.sid)] = sel.value;
            setState(state);
            renderSectionsConfig(container, state, structure);
        });
    });

    container.querySelectorAll('[data-action="activity-action"]').forEach(sel => {
        sel.addEventListener('change', () => {
            const aid = parseInt(sel.dataset.aid);
            state.activityAction[aid] = sel.value;
            if (sel.value === 'modify' || sel.value === 'reference') {
                state.activityRef[aid] = true;
            }
            if (sel.value === 'exclude') {
                state.activityRef[aid] = false;
            }
            setState(state);
            renderSectionsConfig(container, state, structure);
        });
    });

    container.querySelectorAll('[data-action="activity-ref"]').forEach(cb => {
        cb.addEventListener('change', () => {
            state.activityRef[parseInt(cb.dataset.aid)] = cb.checked;
            setState(state);
        });
    });

    container.querySelectorAll('[data-action="activity-prompt"]').forEach(ta => {
        ta.addEventListener('input', () => {
            state.activityPrompt[parseInt(ta.dataset.aid)] = ta.value;
            setState(state);
        });
    });

    container.querySelectorAll('[data-action="toggle-quick"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const menu = container.querySelector(`[data-region="quick-menu-${btn.dataset.sid}"]`);
            if (menu) {
                menu.classList.toggle('d-none');
            }
        });
    });

    container.querySelectorAll('[data-action="bulk"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sid = parseInt(btn.dataset.sid);
            const val = btn.dataset.val;
            const sec = structure.find(s => s.id === sid);
            if (sec) {
                sec.activities.forEach(a => {
                    state.activityAction[a.id] = val;
                    state.activityRef[a.id] = val === 'modify' || val === 'reference' || val === 'keep';
                    if (val !== 'modify') {
                        delete state.activityPrompt[a.id];
                    }
                });
                setState(state);
                renderSectionsConfig(container, state, structure);
            }
        });
    });
};
