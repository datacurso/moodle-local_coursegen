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
 * Course table rendering and selection for the template wizard.
 *
 * @module     local_coursegen/local/template/course_table
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getState, setState} from './init';

const PER_PAGE = 15;
let currentPage = 0;
let courses = [];

/**
 * Set the course list and reset pagination.
 *
 * @param {Array} list Course array from AJAX.
 */
export const setCourses = (list) => {
    courses = list;
    currentPage = 0;
};

/**
 * Get the current course list.
 *
 * @returns {Array}
 */
export const getCourses = () => courses;

/**
 * Render the course table into the panel.
 *
 * @param {HTMLElement} panel
 */
export const renderCourses = (panel) => {
    const state = getState();
    const container = panel.querySelector('[data-region="course-list"]');
    const total = courses.length;
    const totalPages = Math.ceil(total / PER_PAGE);
    if (currentPage >= totalPages && totalPages > 0) {
        currentPage = totalPages - 1;
    }
    const page = courses.slice(currentPage * PER_PAGE, (currentPage + 1) * PER_PAGE);

    panel.querySelector('[data-region="course-count"]').textContent = total + ' course' + (total !== 1 ? 's' : '');

    if (!page.length) {
        container.innerHTML = '<div class="text-center text-muted py-4">No courses in this category</div>';
        panel.querySelector('[data-region="course-pagination"]').classList.add('d-none');
        return;
    }

    let html = '<table class="generaltable table table-striped table-hover mb-0">';
    html += '<thead><tr><th style="width:40px"></th><th>Full name</th>';
    html += '<th>Short name</th><th>Category</th><th>Sections</th></tr></thead><tbody>';
    page.forEach(c => {
        const sel = state.selectedCourseId === c.id;
        html += `<tr data-courseid="${c.id}" class="${sel ? 'table-primary' : ''}" style="cursor:pointer">`;
        html += '<td><div class="custom-control custom-radio">';
        html += `<input type="radio" class="custom-control-input" name="tplcourse" id="tc${c.id}" ${sel ? 'checked' : ''}>`;
        html += `<label class="custom-control-label" for="tc${c.id}">`;
        html += `<span class="sr-only">${c.fullname}</span></label></div></td>`;
        html += `<td>${c.fullname}</td><td>${c.shortname}</td>`;
        html += `<td class="text-muted">${c.categoryname}</td>`;
        html += `<td class="text-center">${c.numsections || 0}</td></tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;

    renderPagination(panel, totalPages, total);
    bindEvents(panel);
};

/**
 * Render pagination controls.
 *
 * @param {HTMLElement} panel
 * @param {number} totalPages
 * @param {number} total
 */
const renderPagination = (panel, totalPages, total) => {
    const el = panel.querySelector('[data-region="course-pagination"]');
    if (totalPages <= 1) {
        el.classList.add('d-none');
        return;
    }
    el.classList.remove('d-none');
    let html = `<span class="text-muted small">${currentPage * PER_PAGE + 1}`;
    html += `–${Math.min((currentPage + 1) * PER_PAGE, total)} of ${total}</span>`;
    html += '<nav class="d-inline ml-2"><ul class="pagination pagination-sm mb-0">';
    for (let i = 0; i < totalPages; i++) {
        html += `<li class="page-item ${i === currentPage ? 'active' : ''}">`;
        html += `<a class="page-link" href="#" data-page="${i}">${i + 1}</a></li>`;
    }
    html += '</ul></nav>';
    el.innerHTML = html;
};

/**
 * Bind click events on course rows and pagination.
 *
 * @param {HTMLElement} panel
 */
const bindEvents = (panel) => {
    panel.querySelectorAll('[data-courseid]').forEach(row => {
        row.addEventListener('click', () => {
            const id = parseInt(row.dataset.courseid);
            const c = courses.find(cc => cc.id === id);
            if (!c) {
                return;
            }
            setState({selectedCourseId: id, selectedCourse: {id, fullname: c.fullname}, courseStructure: null});

            // Update banner.
            const banner = panel.querySelector('[data-region="selected-banner"]');
            banner.classList.remove('d-none');
            panel.querySelector('[data-region="selected-name"]').textContent = c.fullname;
            panel.querySelector('[data-region="selected-short"]').textContent = c.shortname;
            const viewLink = banner.querySelector('[data-region="selected-link"]');
            if (viewLink) {
                viewLink.href = M.cfg.wwwroot + '/course/view.php?id=' + c.id;
            }

            // Update highlight.
            panel.querySelectorAll('[data-courseid]').forEach(r => {
                const rid = parseInt(r.dataset.courseid);
                r.classList.toggle('table-primary', rid === id);
                const radio = r.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = rid === id;
                }
            });
        });
    });

    panel.querySelectorAll('[data-page]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            currentPage = parseInt(link.dataset.page);
            renderCourses(panel);
        });
    });
};
