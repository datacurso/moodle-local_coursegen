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
 * Step 3: Course preview and template naming.
 *
 * @module     local_coursegen/local/template/step_preview
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setState} from './init';
import {get_string as getString} from 'core/str';

/**
 * Build HTML for a single activity row within a section.
 *
 * @param {Object} activity
 * @returns {string} HTML string.
 */
const buildActivityHtml = (activity) => {
    return `<div class="d-flex align-items-center py-1 small">
        <i class="icon fa fa-puzzle-piece fa-fw mr-1 text-muted"></i>
        <span class="tpl-badge mr-1">${activity.modname}</span>
        <span class="text-truncate">${activity.name}</span>
    </div>`;
};

/**
 * Build HTML for a single section card including its activities.
 *
 * @param {Object} section
 * @returns {string} HTML string.
 */
const buildSectionHtml = (section) => {
    const activitiesHtml = section.activities.map(buildActivityHtml).join('');

    return `<div class="tpl-section-card mb-2">
        <div class="tpl-section-header d-flex align-items-center">
            <i class="icon fa fa-folder-o fa-fw mr-1"></i>
            <span class="small font-weight-bold">${section.name}</span>
            <span class="small text-muted ml-2">${section.activities.length} activities</span>
        </div>
        <div class="px-3 py-1">
            ${activitiesHtml}
        </div>
    </div>`;
};

/**
 * Build the name and description form HTML.
 *
 * @param {string} nameLbl
 * @param {string} namePh
 * @param {string} descLbl
 * @param {string} descPh
 * @param {string} templateName
 * @param {string} templateDesc
 * @returns {string} HTML string.
 */
const buildFormHtml = (nameLbl, namePh, descLbl, descPh, templateName, templateDesc) => {
    return `<div class="card p-3 mt-3">
        <div class="form-group">
            <label class="small font-weight-bold">
                ${nameLbl} <span class="text-danger">*</span>
            </label>
            <input type="text" class="form-control form-control-sm" data-field="template-name"
                   placeholder="${namePh}" value="${templateName}">
        </div>
        <div class="form-group mb-0">
            <label class="small font-weight-bold">${descLbl}</label>
            <textarea class="form-control form-control-sm" rows="2" data-field="template-desc"
                      placeholder="${descPh}">${templateDesc}</textarea>
        </div>
    </div>`;
};

/**
 * Render step 3 panel.
 *
 * @param {HTMLElement} panel
 * @param {Object} state
 */
export const renderStepPreview = async(panel, state) => {
    const [heading, nameLbl, namePh, descLbl, descPh] = await Promise.all([
        getString('template_course_preview', 'local_coursegen'),
        getString('template_name', 'local_coursegen'),
        getString('template_name_placeholder', 'local_coursegen'),
        getString('template_description', 'local_coursegen'),
        getString('template_description_placeholder', 'local_coursegen'),
    ]);

    const course = state.selectedCourse;
    const structure = state.courseStructure || [];
    const totalActs = structure.reduce((sum, s) => sum + s.activities.length, 0);

    const courseInfo = course
        ? `${course.fullname} (${course.shortname}) — ${structure.length} sections, ${totalActs} activities`
        : '';

    const sectionsHtml = structure.map(buildSectionHtml).join('');
    const formHtml = buildFormHtml(
        nameLbl, namePh, descLbl, descPh,
        state.templateName, state.templateDesc
    );

    panel.innerHTML = `
        <h3 class="h5 mb-1">${heading}</h3>
        <p class="small text-muted mb-3">${courseInfo}</p>
        ${sectionsHtml}
        ${formHtml}`;

    // Bind input events.
    panel.querySelector('[data-field="template-name"]').addEventListener('input', (e) => {
        setState({templateName: e.target.value});
    });

    panel.querySelector('[data-field="template-desc"]').addEventListener('input', (e) => {
        setState({templateDesc: e.target.value});
    });
};
