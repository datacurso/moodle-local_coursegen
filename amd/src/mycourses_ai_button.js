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
 * Inject the "Create with AI" button into the My courses page.
 *
 * Core renders its course action buttons in a different container depending
 * on the Moodle version, so the button is placed into the first container
 * found from an ordered list of candidates:
 *
 * 1. The page header button group (Moodle 4.5 / 5.0).
 * 2. The course actions row of the Course overview block, right after core's
 *    "Create course" form (Moodle 5.2+).
 * 3. The empty-state action bar shown when the user has no courses (all versions).
 *
 * @module     local_coursegen/mycourses_ai_button
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

const TEMPLATE = 'local_coursegen/add_ai_course_button';

const SELECTORS = {
    // Rendered by the button template; used to guard against double insertion.
    aiCourseForm: '#local_coursegen_aicourseform',
    // Moodle 4.5 / 5.0: my/templates/dropdown.mustache page header button group.
    headerActions: '.my-action-buttons.my-action-buttons-right',
    // Moodle 5.2+: core "Create course" form inside block_myoverview/main.mustache.
    blockCreateCourseForm: '[data-region="myoverview"] form[action*="/course/edit.php"]',
    // All versions: block_myoverview/zero-state.mustache action bar.
    zeroStateActionBar: '#action_bar',
};

/**
 * Resolve where the button has to be inserted.
 *
 * @returns {{container: Element, after: (Element|null), wrapperClass: (string|null)}|null}
 *     Insertion target, or null when no known container is on the page.
 */
const findInsertionTarget = () => {
    const headerActions = document.querySelector(SELECTORS.headerActions);
    if (headerActions) {
        return {container: headerActions, after: null, wrapperClass: null};
    }

    const createCourseForm = document.querySelector(SELECTORS.blockCreateCourseForm);
    if (createCourseForm && createCourseForm.parentElement) {
        return {container: createCourseForm.parentElement, after: createCourseForm, wrapperClass: null};
    }

    const zeroStateActionBar = document.querySelector(SELECTORS.zeroStateActionBar);
    if (zeroStateActionBar) {
        return {container: zeroStateActionBar, after: null, wrapperClass: 'singlebutton'};
    }

    return null;
};

/**
 * Turn rendered template HTML into the node to insert.
 *
 * @param {string} html Rendered template HTML.
 * @param {string|null} wrapperClass Optional class of a wrapping div.
 * @returns {DocumentFragment|Element} Node ready to be inserted.
 */
const buildNode = (html, wrapperClass) => {
    const template = document.createElement('template');
    template.innerHTML = html.trim();

    if (!wrapperClass) {
        return template.content;
    }

    const wrapper = document.createElement('div');
    wrapper.className = wrapperClass;
    wrapper.appendChild(template.content);
    return wrapper;
};

/**
 * Render the button and insert it into the page.
 *
 * @param {Object} config Module configuration.
 * @param {string} config.url URL of the AI course creation page.
 * @returns {Promise<void>}
 */
export const init = async({url}) => {
    if (document.querySelector(SELECTORS.aiCourseForm)) {
        return;
    }

    const target = findInsertionTarget();
    if (!target) {
        return;
    }

    const {html, js} = await Templates.renderForPromise(TEMPLATE, {url});

    // The page may have changed while the template was rendering.
    if (document.querySelector(SELECTORS.aiCourseForm)) {
        return;
    }

    const node = buildNode(html, target.wrapperClass);
    if (target.after) {
        target.after.after(node);
    } else {
        target.container.appendChild(node);
    }

    Templates.runTemplateJS(js);
};
