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
 * Step 1: Template list.
 *
 * @module     local_coursegen/local/template/step_list
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {goToStep, getState, setState} from './init';
import * as Repository from './repository';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * Render step 1 panel.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepList = async(panel, state) => {
    const [title, searchPh, emptyTitle, emptySub, createLabel, deleteConfirm, deletedMsg] = await Promise.all([
        getString('managetemplates', 'local_coursegen'),
        getString('template_search_placeholder', 'local_coursegen'),
        getString('template_empty_title', 'local_coursegen'),
        getString('template_empty_subtitle', 'local_coursegen'),
        getString('template_create', 'local_coursegen'),
        getString('template_confirm_delete', 'local_coursegen'),
        getString('template_deleted', 'local_coursegen'),
    ]);

    let html = `<div class="d-flex align-items-center justify-content-between mb-3">
        <p class="text-muted small mb-0">${title}</p>
        <button class="btn btn-primary btn-sm" data-action="create-template">${createLabel}</button>
    </div>`;

    html += `<div class="tpl-list-card">`;

    if (!state.templates || state.templates.length === 0) {
        html += `<div class="p-5 text-center text-muted">
            <p class="h5 mb-2">${emptyTitle}</p>
            <p class="small">${emptySub}</p>
        </div>`;
    } else {
        // Search bar.
        html += `<div class="p-2 border-bottom bg-light">
            <input type="text" class="form-control form-control-sm" data-action="search-templates"
                   placeholder="${searchPh}">
        </div>`;
        html += `<div class="table-responsive"><table class="table table-striped mb-0">
            <thead><tr>
                <th>Name</th><th>Base course</th><th>Modified</th><th>Actions</th>
            </tr></thead><tbody>`;
        state.templates.forEach(t => {
            const date = new Date(t.timemodified * 1000).toLocaleDateString();
            html += `<tr data-template-id="${t.id}">
                <td>${t.name}</td>
                <td class="small text-muted">${t.coursefullname}</td>
                <td class="small text-muted">${date}</td>
                <td>
                    <button class="btn btn-sm btn-link p-0" data-action="delete-template" data-id="${t.id}"
                            title="Delete">
                        <i class="icon fa fa-trash fa-fw"></i>
                    </button>
                </td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }
    html += `</div>`;

    panel.innerHTML = html;

    // Event: Create template.
    panel.querySelector('[data-action="create-template"]')?.addEventListener('click', () => {
        // Reset state for new template.
        setState({
            templateId: 0,
            selectedCourseId: null,
            selectedCourse: null,
            courseStructure: null,
            templateName: '',
            templateDesc: '',
            sectionBehavior: {},
            activityAction: {},
            activityRef: {},
            activityPrompt: {},
        });
        goToStep(2);
    });

    // Event: Delete template.
    panel.querySelectorAll('[data-action="delete-template"]').forEach(btn => {
        btn.addEventListener('click', async(e) => {
            const id = parseInt(e.currentTarget.dataset.id);
            if (!confirm(deleteConfirm)) {
                return;
            }
            try {
                await Repository.deleteTemplate(id);
                Notification.addNotification({message: deletedMsg, type: 'success'});
                const templates = await Repository.getTemplates();
                setState({templates});
                renderStepList(panel, getState());
            } catch (err) {
                Notification.exception(err);
            }
        });
    });

    // Event: Search.
    panel.querySelector('[data-action="search-templates"]')?.addEventListener('input', (e) => {
        const filter = e.target.value.toLowerCase();
        panel.querySelectorAll('tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
};
