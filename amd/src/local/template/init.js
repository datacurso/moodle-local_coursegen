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
 * Template wizard initialisation and state management.
 *
 * @module     local_coursegen/local/template/init
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderStepper} from './stepper';
import {renderStepCourse} from './step_course';
import {renderStepPreview} from './step_preview';
import {renderStepSections} from './step_sections';
import {renderStepLimits} from './step_limits';
import {renderStepSummary} from './step_summary';
import * as Repository from './repository';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/** @type {Object} Wizard state. */
const state = {
    currentStep: 1, selectedCourseId: null, selectedCourse: null,
    courseStructure: null, templateName: '', templateDesc: '', templateId: 0,
    sectionBehavior: {}, activityAction: {}, activityRef: {}, activityPrompt: {},
    maxSections: 0, noLimit: false, allowedTypes: [],
    namingPattern: 'Unidad {N} — {nombre}', namingStart: 1, categories: [],
};
/** @type {HTMLElement} Root element. */
let root = null;

/** @returns {Object} Current state. */
export const getState = () => state;

/**
 * @param {Object} updates Properties to merge into state.
 * @param {boolean} render Whether to re-render the current step.
 */
export const setState = (updates, render = false) => {
    Object.assign(state, updates);
    if (render) { showStep(state.currentStep); }
};

/** @returns {HTMLElement} Root wizard element. */
export const getRoot = () => root;

const STEPS = [
    {id: 1, label: 'template_step_course'},
    {id: 2, label: 'template_step_preview'},
    {id: 3, label: 'template_step_sections'},
    {id: 4, label: 'template_step_limits'},
    {id: 5, label: 'template_step_save'},
];

/**
 * Show a specific step.
 * @param {number} step
 */
const showStep = (step) => {
    state.currentStep = step;
    root.querySelectorAll('[data-region="step-panel"]').forEach(p => {
        p.classList.toggle('d-none', parseInt(p.dataset.step) !== step);
    });

    const prevBtn = root.querySelector('[data-action="prev"]');
    const nextBtn = root.querySelector('[data-action="next"]');
    const saveBtn = root.querySelector('[data-action="save"]');

    prevBtn.classList.toggle('d-none', step <= 1);
    nextBtn.classList.toggle('d-none', step === 5);
    saveBtn.classList.toggle('d-none', step !== 5);

    renderStepper(root.querySelector('[data-region="stepper"]'), STEPS, step);

    const panel = root.querySelector(`[data-region="step-panel"][data-step="${step}"]`);
    const renderers = {
        1: renderStepCourse,
        2: renderStepPreview,
        3: renderStepSections,
        4: renderStepLimits,
        5: renderStepSummary,
    };
    if (renderers[step]) {
        renderers[step](panel, state);
    }

    updateUrl();
};

/** Update the browser URL to reflect current wizard state without reloading. */
const updateUrl = () => {
    const params = new URLSearchParams(window.location.search);
    params.set('step', state.currentStep);
    if (state.templateId) { params.set('id', state.templateId); }
    if (state.selectedCourseId) { params.set('courseid', state.selectedCourseId); }
    else { params.delete('courseid'); }
    window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
};

/**
 * Go to next step with validation.
 */
const nextStep = async() => {
    if (state.currentStep === 1 && !state.selectedCourseId) {
        const msg = await getString('template_select_course_first', 'local_coursegen');
        Notification.addNotification({message: msg, type: 'warning'});
        return;
    }
    if (state.currentStep === 1 && !state.courseStructure) {
        try {
            state.courseStructure = await Repository.getCourseStructure(state.selectedCourseId);
            initSectionState();
        } catch (e) {
            Notification.exception(e);
            return;
        }
    }
    if (state.currentStep >= 5) {
        return;
    }
    showStep(state.currentStep + 1);
};

/** Go to previous step. */
const prevStep = () => { if (state.currentStep > 1) { showStep(state.currentStep - 1); } };

/**
 * Initialise section/activity state from course structure.
 */
const initSectionState = () => {
    state.sectionBehavior = {};
    state.activityAction = {};
    state.activityRef = {};
    state.activityPrompt = {};

    const actTypes = new Set();
    state.courseStructure.forEach(s => {
        state.sectionBehavior[s.id] = 'custom';
        s.activities.forEach(a => {
            state.activityAction[a.id] = 'modify';
            state.activityRef[a.id] = true;
            actTypes.add(a.modname);
        });
    });
    state.maxSections = state.courseStructure.length;
    state.allowedTypes = [...actTypes];
};

/**
 * Build the section payload from current state.
 * @returns {Array}
 */
const buildSections = () => state.courseStructure.map(s => ({
    sectionid: s.id, sectionnum: s.num,
    behavior: state.sectionBehavior[s.id] || 'custom',
    activities: s.activities.map(a => ({
        cmid: a.id, action: state.activityAction[a.id] || 'modify',
        useasreference: state.activityRef[a.id] !== false,
        prompt: state.activityPrompt[a.id] || '',
    })),
}));

/**
 * Save template via repository and notify user.
 */
const saveTemplate = async() => {
    try {
        // Read name/desc from moodleform inputs.
        const nameVal = root.querySelector('#id_templatename')?.value || state.templateName;
        const descVal = root.querySelector('#id_templatedesc')?.value || state.templateDesc;
        state.templateName = nameVal;
        state.templateDesc = descVal;
        const result = await Repository.saveTemplate({
            id: state.templateId, name: nameVal,
            description: descVal, courseid: state.selectedCourseId,
            maxsections: state.maxSections, nolimit: state.noLimit,
            allowedtypes: JSON.stringify(state.allowedTypes),
            namingpattern: state.namingPattern, namingstart: state.namingStart,
            sections: buildSections(),
        });
        const msg = await getString('template_saved', 'local_coursegen', result.name);
        Notification.addNotification({message: msg, type: 'success'});
        state.templateId = result.id;
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Navigate directly to a specific step.
 * @param {number} step
 */
export const goToStep = (step) => {
    showStep(step);
};

/**
 * Initialise the template wizard.
 * @param {Object} config
 * @param {Array} config.courses List of available courses.
 */
export const init = (config) => {
    root = document.getElementById('local-coursegen-template-wizard');
    if (!root) {
        return;
    }

    state.templateId = config.templateid || 0;
    state.categories = config.categories || [];

    // Restore state from URL parameters.
    const initialCourseId = config.initialcourseid || 0;
    const initialCourseName = config.initialcoursename || '';
    if (initialCourseId > 0) {
        state.selectedCourseId = initialCourseId;
        state.selectedCourse = {id: initialCourseId, fullname: initialCourseName};
    }

    root.querySelector('[data-action="next"]').addEventListener('click', nextStep);
    root.querySelector('[data-action="prev"]').addEventListener('click', prevStep);
    root.querySelector('[data-action="save"]').addEventListener('click', saveTemplate);

    // Start at the step from URL or default to 1.
    const initialStep = config.initialstep || 1;
    // If restoring to step > 1, we need course structure loaded first.
    if (initialStep > 1 && state.selectedCourseId && !state.courseStructure) {
        Repository.getCourseStructure(state.selectedCourseId).then(structure => {
            state.courseStructure = structure;
            initSectionState();
            showStep(initialStep);
        }).catch(() => {
            showStep(1);
        });
    } else {
        showStep(initialStep);
    }
};
