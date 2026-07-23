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
 * Step 1: Course selection — events on server-rendered category tree.
 *
 * @module     local_coursegen/local/template/step_course
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getState, setState} from './init';
import {setCourses, renderCourses} from './course_table';
import Ajax from 'core/ajax';
import Notification from 'core/notification';

let bound = false;

/**
 * Bind events on the server-rendered step 1.
 *
 * @param {HTMLElement} panel The step panel element.
 */
export const renderStepCourse = (panel) => {
    if (bound) {
        return;
    }
    bound = true;

    // Children are already hidden via mustache (d-none on depth > 0).

    bindCategoryEvents(panel);
    bindGlobalEvents(panel);

    // Restore selected course banner.
    const state = getState();
    if (state.selectedCourseId && state.selectedCourse) {
        const banner = panel.querySelector('[data-region="selected-banner"]');
        if (banner) {
            banner.classList.remove('d-none');
            panel.querySelector('[data-region="selected-name"]').textContent = state.selectedCourse.fullname;
            panel.querySelector('[data-region="selected-short"]').textContent = state.selectedCourse.shortname || '';
            const viewLink = banner.querySelector('[data-region="selected-link"]');
            if (viewLink) {
                viewLink.href = M.cfg.wwwroot + '/course/view.php?id=' + state.selectedCourseId;
            }
        }
    }
};

/**
 * Bind click events on server-rendered category items.
 *
 * @param {HTMLElement} panel
 */
const bindCategoryEvents = (panel) => {
    const container = panel.querySelector('[data-region="category-tree"]');
    if (!container) {
        return;
    }

    container.querySelectorAll('[data-catid]').forEach(el => {
        el.addEventListener('click', () => {
            const catId = parseInt(el.dataset.catid);
            const hasChildren = el.dataset.haschildren === '1';

            // Toggle children visibility.
            if (hasChildren) {
                toggleChildren(container, catId, el);
            }

            // Select this category and load its courses.
            container.querySelectorAll('[data-catid]').forEach(item => item.classList.remove('active'));
            el.classList.add('active');
            loadCourses(panel, catId);
        });
    });
};

/**
 * Toggle visibility of direct child categories.
 *
 * @param {HTMLElement} container
 * @param {number} parentId
 * @param {HTMLElement} parentEl
 */
const toggleChildren = (container, parentId, parentEl) => {
    const chevron = parentEl.querySelector('.fa-chevron-right, .fa-chevron-down');
    const isExpanded = chevron && chevron.classList.contains('fa-chevron-down');

    // Find children by checking next siblings with greater depth.
    const parentDepth = getDepth(parentEl);
    let sibling = parentEl.nextElementSibling;
    while (sibling && sibling.dataset.catid) {
        const sibDepth = getDepth(sibling);
        if (sibDepth <= parentDepth) {
            break;
        }
        if (isExpanded) {
            // Collapse: hide all descendants.
            sibling.classList.add('d-none');
            const subChevron = sibling.querySelector('.fa-chevron-down');
            if (subChevron) {
                subChevron.classList.remove('fa-chevron-down');
                subChevron.classList.add('fa-chevron-right');
            }
        } else if (sibDepth === parentDepth + 1) {
            // Expand: show only direct children.
            sibling.classList.remove('d-none');
        }
        sibling = sibling.nextElementSibling;
    }

    // Toggle chevron.
    if (chevron) {
        chevron.classList.toggle('fa-chevron-right', isExpanded);
        chevron.classList.toggle('fa-chevron-down', !isExpanded);
    }
};

/**
 * Get depth level from data attribute.
 *
 * @param {HTMLElement} el
 * @returns {number}
 */
const getDepth = (el) => parseInt(el.dataset.depth || '0');

/**
 * Load courses for the selected category via AJAX.
 *
 * @param {HTMLElement} panel
 * @param {number} catId
 */
const loadCourses = async(panel, catId) => {
    try {
        const result = await Ajax.call([{
            methodname: 'local_coursegen_get_courses_by_category',
            args: {categoryid: catId, recursive: false},
        }])[0];
        setCourses(result);
        renderCourses(panel);
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Bind global events: search, deselect, toggle-all.
 *
 * @param {HTMLElement} panel
 */
const bindGlobalEvents = (panel) => {
    const search = panel.querySelector('[data-action="global-search"]');
    search?.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        panel.querySelectorAll('[data-catid]').forEach(el => {
            if (q.length < 2) {
                // Reset: show only root level.
                const depth = getDepth(el);
                el.classList.toggle('d-none', depth > 0);
                return;
            }
            const name = el.querySelector('.flex-grow-1')?.textContent.toLowerCase() || '';
            el.classList.toggle('d-none', !name.includes(q));
        });
    });

    panel.querySelector('[data-action="search-btn"]')?.addEventListener('click', () => {
        search?.dispatchEvent(new Event('input'));
    });

    panel.querySelector('[data-action="deselect"]')?.addEventListener('click', () => {
        setState({selectedCourseId: null, selectedCourse: null, courseStructure: null});
        panel.querySelector('[data-region="selected-banner"]')?.classList.add('d-none');
        renderCourses(panel);
    });

    panel.querySelector('[data-action="toggle-all"]')?.addEventListener('click', () => {
        const allHidden = panel.querySelectorAll('[data-catid].d-none').length > 0;
        panel.querySelectorAll('[data-catid]').forEach(el => {
            el.classList.toggle('d-none', !allHidden);
        });
        panel.querySelectorAll('.fa-chevron-right, .fa-chevron-down').forEach(ch => {
            ch.classList.toggle('fa-chevron-right', !allHidden);
            ch.classList.toggle('fa-chevron-down', allHidden);
        });
    });
};
