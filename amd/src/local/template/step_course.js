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
 * Step 1: Course selection with category tree browser.
 *
 * @module     local_coursegen/local/template/step_course
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getState, setState} from './init';
import {setCourses, renderCourses} from './course_table';
import Ajax from 'core/ajax';
import Notification from 'core/notification';

let cattree = [];
let expanded = new Set();
let selectedCatId = null;
let bound = false;

/**
 * Build a nested tree from a flat list of categories.
 *
 * @param {Array} flat
 * @returns {Array}
 */
const buildTree = (flat) => {
    const map = {};
    flat.forEach(c => { map[c.id] = {...c, children: []}; });
    const tree = [];
    Object.values(map).forEach(c => {
        if (c.parent === 0 || !map[c.parent]) {
            tree.push(c);
        } else {
            map[c.parent].children.push(c);
        }
    });
    return tree;
};

/**
 * Render step 1: bind events on server-rendered layout.
 *
 * @param {HTMLElement} panel The step panel element.
 */
export const renderStepCourse = async(panel) => {
    if (bound) {
        return;
    }
    bound = true;

    // Load categories via AJAX.
    try {
        const flat = await Ajax.call([{
            methodname: 'local_coursegen_get_category_tree',
            args: {},
        }])[0];
        cattree = buildTree(flat);
    } catch (e) {
        Notification.exception(e);
        return;
    }

    renderTree(panel, '');
    bindGlobalEvents(panel);

    // Restore selected course banner if returning to this step.
    const state = getState();
    if (state.selectedCourseId && state.selectedCourse) {
        const banner = panel.querySelector('[data-region="selected-banner"]');
        banner.classList.remove('d-none');
        panel.querySelector('[data-region="selected-name"]').textContent = state.selectedCourse.fullname;
        panel.querySelector('[data-region="selected-short"]').textContent = state.selectedCourse.shortname || '';
        const viewLink = banner.querySelector('[data-region="selected-link"]');
        if (viewLink) {
            viewLink.href = M.cfg.wwwroot + '/course/view.php?id=' + state.selectedCourseId;
        }
    }
};

/**
 * Render category tree.
 *
 * @param {HTMLElement} panel
 * @param {string} filter Search filter.
 */
const renderTree = (panel, filter) => {
    const container = panel.querySelector('[data-region="category-tree"]');
    const html = buildTreeHtml(cattree, 0, filter);
    container.innerHTML = html || '<div class="text-muted p-3 text-center">No categories</div>';

    // Click on chevron toggles expand/collapse only.
    container.querySelectorAll('[data-toggle-cat]').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(el.dataset.toggleCat);
            if (expanded.has(id)) {
                expanded.delete(id);
            } else {
                expanded.add(id);
            }
            renderTree(panel, panel.querySelector('[data-action="global-search"]')?.value || '');
        });
    });

    // Click on the row selects the category and loads courses (expands if has children).
    container.querySelectorAll('[data-catid]').forEach(el => {
        el.addEventListener('click', () => {
            const id = parseInt(el.dataset.catid);
            if (el.dataset.haschildren === '1') {
                expanded.add(id);
            }
            selectedCatId = id;
            renderTree(panel, panel.querySelector('[data-action="global-search"]')?.value || '');
            loadCourses(panel, id);
        });
    });
};

/**
 * Build HTML for the category tree recursively.
 *
 * @param {Array} cats
 * @param {number} depth
 * @param {string} filter
 * @returns {string}
 */
const buildTreeHtml = (cats, depth, filter) => {
    let html = '';
    cats.forEach(cat => {
        if (filter && !catMatches(cat, filter)) {
            return;
        }
        const hasKids = cat.children && cat.children.length > 0;
        const isOpen = expanded.has(cat.id) || !!filter;
        const isActive = selectedCatId === cat.id;
        const pl = 0.75 + depth * 0.75;
        const count = countCourses(cat);
        const badge = isActive ? 'badge-light' : 'badge-secondary';
        let chevronHtml = '<span class="mr-1 mt-1 small flex-shrink-0" style="width:16px"></span>';
        if (hasKids) {
            const dir = isOpen ? 'down' : 'right';
            chevronHtml = `<span class="mr-1 mt-1 small flex-shrink-0" style="width:16px;cursor:pointer"
                data-toggle-cat="${cat.id}"><i class="fa fa-chevron-${dir} fa-fw"></i></span>`;
        }

        html += `<div class="list-group-item list-group-item-action d-flex align-items-start py-2`;
        html += ` ${isActive ? 'active' : ''}" data-catid="${cat.id}" data-haschildren="${hasKids ? 1 : 0}"`;
        html += ` style="padding-left:${pl}rem;cursor:pointer">`;
        html += chevronHtml;
        html += `<span class="flex-grow-1" style="word-break:break-word">${cat.name}</span>`;
        html += `<span class="badge badge-pill ${badge} ml-1">${count}</span></div>`;

        if (hasKids && isOpen) {
            html += buildTreeHtml(cat.children, depth + 1, filter);
        }
    });
    return html;
};

/**
 * Check if a category matches search filter.
 *
 * @param {Object} cat
 * @param {string} filter
 * @returns {boolean}
 */
const catMatches = (cat, filter) => {
    const f = filter.toLowerCase();
    if (cat.name.toLowerCase().includes(f)) {
        return true;
    }
    return cat.children ? cat.children.some(ch => catMatches(ch, f)) : false;
};

/**
 * Get direct course count for a category (no recursive sum).
 *
 * @param {Object} cat
 * @returns {number}
 */
const countCourses = (cat) => cat.coursecount || 0;

/**
 * Load courses for a category via AJAX.
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
 * Bind global search, deselect, and toggle-all events.
 *
 * @param {HTMLElement} panel
 */
const bindGlobalEvents = (panel) => {
    const search = panel.querySelector('[data-action="global-search"]');
    search?.addEventListener('input', () => {
        const q = search.value.trim();
        renderTree(panel, q.length >= 2 ? q : '');
    });

    panel.querySelector('[data-action="clear-search"]')?.addEventListener('click', () => {
        search.value = '';
        search.dispatchEvent(new Event('input'));
    });

    panel.querySelector('[data-action="deselect"]')?.addEventListener('click', () => {
        setState({selectedCourseId: null, selectedCourse: null, courseStructure: null});
        panel.querySelector('[data-region="selected-banner"]').classList.add('d-none');
        renderCourses(panel);
    });

    panel.querySelector('[data-action="toggle-all"]')?.addEventListener('click', () => {
        const allIds = [];
        const collect = (cats) => cats.forEach(c => {
            allIds.push(c.id);
            if (c.children) { collect(c.children); }
        });
        collect(cattree);
        if (expanded.size >= allIds.length) { expanded.clear(); }
        else { allIds.forEach(id => expanded.add(id)); }
        renderTree(panel, search?.value?.trim().length >= 2 ? search.value : '');
    });
};
