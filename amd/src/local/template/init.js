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
 * Template configuration — state management for the single-screen flow.
 *
 * The admin picks a base course, then everything else — the template name
 * and the course structure review, where each activity stays intact or is
 * modified by the AI with an optional instruction — renders on the SAME page
 * immediately, no further navigation required before Save.
 *
 * @module     local_coursegen/local/template/init
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderStepSections, resetSectionsRender} from 'local_coursegen/local/template/step_sections';
import {saveTemplate} from 'local_coursegen/local/template/save_payload';
import {bindCoursePicker, updateSelectedBanner} from 'local_coursegen/local/template/course_picker_binding';
import Selectors from 'local_coursegen/local/template/selectors';
import {CLASS, EVENT} from 'local_coursegen/local/template/constants';

/** @type {Object} Editor state. */
const state = {
    selectedCourseId: null, selectedCourse: null,
    templateName: '', templateDesc: '', templateId: 0,
    // True once the review of the selected course is rendered and bound.
    sectionsReady: false,
    // What the AI does with each activity of the rendered review, keyed by course module id.
    activityAction: {}, activityInstruction: {},
};
/** @type {HTMLElement} Root element. */
let root = null;
/**
 * Whether the review is the one the server rendered for the CURRENT course
 * (true right after init(), from the initial page load) — set false as soon
 * as it has been bound, so a course change fetches a new render instead.
 * @type {boolean}
 */
let sectionsFreshFromPageLoad = false;

/** @returns {Object} Current state. */
export const getState = () => state;

/** @returns {HTMLElement} Root editor element. */
export const getRoot = () => root;

/**
 * Show/hide and populate the configuration region below the course picker,
 * based on whether a course is currently selected. This is the ONLY thing
 * that changes what's on screen — there is no step/page navigation.
 */
const renderConfigRegion = async() => {
    const region = root.querySelector(Selectors.regions.config);
    if (!state.selectedCourseId) {
        region.classList.add(CLASS.HIDDEN);
        return;
    }
    region.classList.remove(CLASS.HIDDEN);

    if (!state.sectionsReady) {
        resetSectionsRender();
        state.activityAction = {};
        state.activityInstruction = {};
        state.sectionsReady = true;
    }

    // Only the very first render can reuse the markup the server already
    // sent for this exact course; every later one is a course switch.
    const isFreshFromPageLoad = sectionsFreshFromPageLoad;
    sectionsFreshFromPageLoad = false;
    const structurePanel = region.querySelector(Selectors.regions.structure);
    await renderStepSections(structurePanel, state, isFreshFromPageLoad);
};

/**
 * @param {Object} updates Properties to merge into state.
 */
export const setState = (updates) => {
    const coursechanged = Object.prototype.hasOwnProperty.call(updates, 'selectedCourseId');
    Object.assign(state, updates);
    if (coursechanged) {
        updateSelectedBanner(root, state);
        renderConfigRegion();
    }
};

/**
 * Save the template when the Save button is clicked.
 */
const handleSaveClick = () => {
    saveTemplate(state, root);
};

/**
 * Initialise the template configuration screen.
 * @param {Object} config
 * @param {number} config.templateid Template being edited, 0 for a new one.
 * @param {number} config.initialcourseid Course the page was opened with, 0 for none.
 * @param {string} config.initialcoursename Full name of that course.
 * @param {string} config.initialcourseshortname Short name of that course.
 */
export const init = (config) => {
    root = document.querySelector(Selectors.regions.wizard);
    if (!root) {
        return;
    }

    state.templateId = config.templateid || 0;

    const initialCourseId = config.initialcourseid || 0;
    const initialCourseName = config.initialcoursename || '';
    const initialCourseShort = config.initialcourseshortname || '';
    if (initialCourseId > 0) {
        state.selectedCourseId = initialCourseId;
        state.selectedCourse = {id: initialCourseId, fullname: initialCourseName, shortname: initialCourseShort};
        // The page already server-rendered the review for this exact course,
        // so the first render should bind to it, not reload it.
        sectionsFreshFromPageLoad = true;
    }

    const saveButton = root.querySelector(Selectors.actions.save);
    saveButton.addEventListener(EVENT.CLICK, handleSaveClick);
    const coursePickerPanel = root.querySelector(Selectors.regions.coursePickerPanel);
    bindCoursePicker(coursePickerPanel, state, setState);

    renderConfigRegion();
};
