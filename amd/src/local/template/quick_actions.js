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
 * Quick actions dropdown for bulk activity configuration per section.
 *
 * @module     local_coursegen/local/template/quick_actions
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';

const ACT_COLORS = {modify: '#0f6cbf', keep: '#28a745', reference: '#6f42c1', exclude: '#6c757d'};

/**
 * Inject a quick actions dropdown into a section.
 *
 * @param {HTMLElement} section The section DOM element.
 * @param {number} sectionId The section DB id.
 * @param {Object} state Wizard state.
 * @param {HTMLElement} container The full config container (for re-rendering dropdowns).
 */
export const injectQuickActions = (section, sectionId, state, container) => {
    const cmitems = section.querySelectorAll('[data-for="cmitem"]');
    if (!cmitems.length) {
        return;
    }

    // Detect activity types present in this section.
    const typeMap = {};
    cmitems.forEach(cm => {
        const cmid = parseInt(cm.dataset.id);

        // Try to get module type from the rendered HTML.
        let modname = '';
        const img = cm.querySelector('img[src]');
        if (img) {
            const match = img.src.match(/\/mod\/(\w+)\//);
            if (match) {
                modname = match[1];
            }
        }
        if (!modname) {
            // Fallback: check the structure data.
            const structure = state.courseStructure || [];
            for (const sec of structure) {
                const act = sec.activities?.find(a => a.id === cmid);
                if (act) {
                    modname = act.modname;
                    break;
                }
            }
        }
        if (modname && cmid) {
            if (!typeMap[modname]) {
                typeMap[modname] = [];
            }
            typeMap[modname].push(cmid);
        }
    });

    const types = Object.keys(typeMap);
    if (!types.length) {
        return;
    }

    // Find where to insert — after the section title bar.
    const titleBar = section.querySelector('[data-for="section_title"]');
    if (!titleBar) {
        return;
    }

    const qaWrap = document.createElement('div');
    qaWrap.setAttribute('data-tpl-control', 'qa-' + sectionId);
    qaWrap.className = 'dropdown d-inline-block ml-2';

    let menuHtml = '<div class="dropdown-menu dropdown-menu-right">';

    // Global actions.
    menuHtml += '<h6 class="dropdown-header">All activities</h6>';
    menuHtml += buildBulkItem('keep-all', 'Keep all intact', 'all', ACT_COLORS.keep);
    menuHtml += buildBulkItem('modify-all', 'Modify all', 'all', ACT_COLORS.modify);
    menuHtml += buildBulkItem('reference-all', 'Reference all', 'all', ACT_COLORS.reference);

    // Per-type actions (only if more than one type).
    if (types.length > 1) {
        menuHtml += '<div class="dropdown-divider"></div>';
        menuHtml += '<h6 class="dropdown-header">By activity type</h6>';
        types.sort().forEach(type => {
            const label = type.charAt(0).toUpperCase() + type.slice(1);
            const count = typeMap[type].length;
            menuHtml += buildBulkItem('modify-' + type, `${label} → Modify`, type, ACT_COLORS.modify, count);
            menuHtml += buildBulkItem('keep-' + type, `${label} → Keep`, type, ACT_COLORS.keep, count);
            menuHtml += buildBulkItem('reference-' + type, `${label} → Reference`, type, ACT_COLORS.reference, count);
        });
    }

    menuHtml += '</div>';

    qaWrap.innerHTML = `<button class="btn btn-sm btn-link text-muted p-0 ml-2" data-toggle="dropdown"
        title="Quick actions for all activities in this section">
        <i class="fa fa-bolt fa-fw"></i></button>${menuHtml}`;

    // Insert after the section dropdown.
    const sectionDropdown = titleBar.querySelector('.dropdown');
    if (sectionDropdown) {
        sectionDropdown.after(qaWrap);
    } else {
        titleBar.appendChild(qaWrap);
    }

    // Bind events.
    qaWrap.querySelectorAll('[data-bulk-action]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const action = item.dataset.bulkAction;
            const type = item.dataset.bulkType;
            const parts = action.split('-');
            const newAction = parts[0]; // modify, keep, reference

            let cmids = [];
            if (type === 'all') {
                cmitems.forEach(cm => { cmids.push(parseInt(cm.dataset.id)); });
            } else {
                cmids = typeMap[type] || [];
            }

            cmids.forEach(cmid => {
                state.activityAction[cmid] = newAction;
                if (newAction === 'modify') {
                    state.activityRef[cmid] = true;
                }
            });
            setState(state);

            // Update the visual state of affected activities.
            cmids.forEach(cmid => {
                // Update dropdown text.
                const dropdownBtn = container.querySelector(
                    `[data-for="cmitem"][data-id="${cmid}"] .dropdown-toggle`
                );
                if (dropdownBtn) {
                    const labels = {modify: 'Modify', keep: 'Keep', reference: 'Reference', exclude: 'Exclude'};
                    dropdownBtn.textContent = labels[newAction];
                    dropdownBtn.style.color = ACT_COLORS[newAction];
                }
                // Toggle prompt visibility.
                const promptWrap = container.querySelector(`[data-tpl-prompt-wrap="${cmid}"]`);
                if (promptWrap) {
                    promptWrap.style.display = newAction === 'modify' ? '' : 'none';
                }
                // Update opacity.
                const cmEl = container.querySelector(`[data-for="cmitem"][data-id="${cmid}"]`);
                if (cmEl) {
                    cmEl.style.opacity = newAction === 'exclude' ? '0.35' : '1';
                }
            });
        });
    });
};

/**
 * Build a single dropdown item for bulk action.
 *
 * @param {string} action
 * @param {string} label
 * @param {string} type
 * @param {string} color
 * @param {number} count
 * @returns {string}
 */
const buildBulkItem = (action, label, type, color, count) => {
    const badge = count ? `<span class="badge badge-light ml-1">${count}</span>` : '';
    return `<a class="dropdown-item small" href="#" data-bulk-action="${action}" data-bulk-type="${type}">
        <span style="color:${color}">${label}</span>${badge}</a>`;
};
