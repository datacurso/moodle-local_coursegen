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
 * Template mode — handles mode switching and template form interactions.
 *
 * @module     local_coursegen/local/courseai/template_mode
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Wire mode switching and template form.
 *
 * @param {Object} state
 */
export const wireTemplateMode = (state) => {
    const freeBtn = document.getElementById('courseaiModeFree');
    const tplBtn = document.getElementById('courseaiModeTemplate');
    const freeView = document.getElementById('contextView');
    const tplView = document.getElementById('templateModeView');
    const tplSelect = document.getElementById('tplModeSelect');
    const sidebar = document.getElementById('courseaiSidebar');
    const collapseBtn = document.getElementById('courseaiSidebarCollapse');
    const expandBtn = document.getElementById('courseaiSidebarExpand');

    // Sidebar collapse/expand.
    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', () => { sidebar.classList.add('collapsed'); });
    }
    if (expandBtn && sidebar) {
        expandBtn.addEventListener('click', () => { sidebar.classList.remove('collapsed'); });
    }

    if (!freeBtn || !tplBtn || !freeView || !tplView) {
        return;
    }

    /**
     * Switch between free and template mode.
     *
     * @param {string} mode
     */
    const switchMode = (mode) => {
        freeView.style.display = mode === 'free' ? '' : 'none';
        tplView.style.display = mode === 'template' ? '' : 'none';
        freeBtn.classList.toggle('active', mode === 'free');
        tplBtn.classList.toggle('active', mode === 'template');
    };

    freeBtn.addEventListener('click', () => switchMode('free'));
    tplBtn.addEventListener('click', () => switchMode('template'));

    // Template selection — load structure.
    if (tplSelect) {
        tplSelect.addEventListener('change', () => {
            const tplId = parseInt(tplSelect.value);
            if (tplId > 0) {
                loadTemplateStructure(tplId, state);
            } else {
                clearStructure();
            }
        });
    }
};

/**
 * Load template structure and render the dynamic form.
 *
 * @param {number} templateId
 * @param {Object} state
 */
const loadTemplateStructure = async(templateId, state) => {
    const container = document.getElementById('tplModeStructure');
    const genBtn = document.getElementById('tplModeGenerate');
    if (!container) {
        return;
    }

    // Find template data from state.
    const templates = state.templates || [];
    const tpl = templates.find(t => t.id === templateId);
    if (!tpl) {
        return;
    }

    // Load the course structure from the template's base course.
    try {
        const structure = await Ajax.call([{
            methodname: 'local_coursegen_get_course_structure',
            args: {courseid: tpl.courseid || 0},
        }])[0];

        renderStructure(container, structure);
        if (genBtn) {
            genBtn.disabled = false;
        }
        updateStats(container);
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Render the dynamic structure form.
 *
 * @param {HTMLElement} container
 * @param {Array} sections
 */
const renderStructure = (container, sections) => {
    let html = '<div class="d-flex align-items-center mb-2">';
    html += '<label class="font-weight-bold small mb-0">Course structure</label>';
    html += '<span class="text-muted small ml-auto" id="tplModeSectionCount"></span>';
    html += '</div>';

    sections.forEach((section, i) => {
        const isOpen = i === 0;
        html += `<div class="border rounded mb-2" data-tpl-section="${section.id}">`;
        html += `<div class="d-flex align-items-center p-2" style="background:#f8f9fa;cursor:pointer"
                      data-action="toggle-tpl-section">`;
        html += `<i class="fa fa-chevron-${isOpen ? 'down' : 'right'} mr-2 text-muted" style="font-size:.7rem"></i>`;
        html += `<strong style="font-size:.9rem" class="flex-grow-1">${section.name}</strong>`;
        html += `<span class="text-muted small">${section.activities.length} activities</span>`;
        html += '</div>';
        html += `<div class="p-2" style="${isOpen ? '' : 'display:none'}">`;

        section.activities.forEach(act => {
            html += `<div class="d-flex align-items-start py-1 border-bottom" style="border-color:#f5f5f5!important"
                          data-tpl-activity="${act.id}">`;
            html += `<div class="mr-2 mt-1 d-flex align-items-center justify-content-center rounded`
                + `" style="width:24px;height:24px;background:#e3f2fd;color:#1565c0;font-size:.6rem;flex-shrink:0">`;
            html += '<i class="fa fa-edit"></i></div>';
            html += '<div class="flex-grow-1">';
            html += `<div class="d-flex align-items-center">`;
            html += `<span class="small font-weight-bold">${act.name}</span>`;
            html += `<span class="text-muted small ml-2">${act.modname}</span>`;
            html += '</div>';
            html += `<input type="text" class="form-control form-control-sm mt-1"
                            data-tpl-act-prompt="${act.id}"
                            placeholder="Instructions for AI...">`;
            html += '</div></div>';
        });

        // Add activity button.
        html += '<div class="pt-2">';
        html += `<button class="btn btn-sm btn-link text-primary p-0" data-action="add-tpl-activity"
                         data-section="${section.id}" type="button">`;
        html += '<i class="fa fa-plus mr-1"></i>Add activity</button>';
        html += '</div></div></div>';
    });

    container.innerHTML = html;
    bindStructureEvents(container);
};

/**
 * Bind events on the rendered structure.
 *
 * @param {HTMLElement} container
 */
const bindStructureEvents = (container) => {
    // Section collapse/expand.
    container.querySelectorAll('[data-action="toggle-tpl-section"]').forEach(header => {
        header.addEventListener('click', () => {
            const body = header.nextElementSibling;
            const chevron = header.querySelector('.fa');
            const visible = body.style.display !== 'none';
            body.style.display = visible ? 'none' : '';
            chevron.classList.toggle('fa-chevron-down', !visible);
            chevron.classList.toggle('fa-chevron-right', visible);
        });
    });

    // Add activity.
    container.querySelectorAll('[data-action="add-tpl-activity"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-start py-1 border-bottom';
            row.style.borderColor = '#f5f5f5';
            row.innerHTML = `
                <div class="mr-2 mt-1 d-flex align-items-center justify-content-center rounded"
                     style="width:24px;height:24px;background:#e3f2fd;color:#1565c0;font-size:.6rem;flex-shrink:0">
                    <i class="fa fa-plus"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center mb-1">
                        <select class="custom-select custom-select-sm" style="width:auto;font-size:.75rem">
                            <option>book</option><option>quiz</option><option>assign</option>
                            <option>forum</option><option>page</option><option>lesson</option>
                        </select>
                        <input type="text" class="form-control form-control-sm ml-2"
                               placeholder="Activity name" style="flex:1">
                        <button class="btn btn-sm btn-link text-danger p-0 ml-2" type="button"
                                onclick="this.closest('.d-flex').remove()">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>
                    <input type="text" class="form-control form-control-sm" placeholder="Instructions for AI...">
                </div>`;
            btn.parentElement.before(row);
            updateStats(container);
        });
    });
};

/**
 * Update the stats display.
 *
 * @param {HTMLElement} container
 */
const updateStats = (container) => {
    const sections = container.querySelectorAll('[data-tpl-section]').length;
    const activities = container.querySelectorAll('[data-tpl-activity]').length
        + container.querySelectorAll('[data-action="add-tpl-activity"]').length - sections;
    const stats = document.getElementById('tplModeStats');
    if (stats) {
        stats.textContent = sections + ' sections · ' + activities + ' activities';
    }
};

/**
 * Clear the structure display.
 */
const clearStructure = () => {
    const container = document.getElementById('tplModeStructure');
    if (container) {
        container.innerHTML = '';
    }
    const genBtn = document.getElementById('tplModeGenerate');
    if (genBtn) {
        genBtn.disabled = true;
    }
    const stats = document.getElementById('tplModeStats');
    if (stats) {
        stats.textContent = '';
    }
};
