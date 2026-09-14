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
 * Replaces the previous 5-step wizard (course / preview / sections / limits
 * / save, each its own screen with Next/Prev navigation): the admin picks a
 * base course, then everything else — overall limits, per-type defaults,
 * and the course structure review — renders on the SAME page immediately,
 * no further navigation required before Save.
 *
 * @module     local_coursegen/local/template/init
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderStepSections, resetSectionsRender} from './step_sections';
import {renderStepLimits} from './step_limits';
import {resetSectionsDirtyState} from './sections_events';
import {defaultActionForModname} from './type_action_sync';
import * as Repository from './repository';
import DynamicForm from 'core_form/dynamicform';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {resetAllFormDirtyStates} from 'core_form/changechecker';

/** @type {Object} Wizard state. */
const state = {
    selectedCourseId: null, selectedCourse: null,
    courseStructure: null, templateName: '', templateDesc: '', templateId: 0,
    sectionBehavior: {}, activityAction: {}, activityRef: {}, activityPrompt: {},
    // Saved per-section/per-activity configuration when editing an existing
    // template (see edit_template.php) — seeds the maps above so a re-save
    // round-trips values that have no visible controls (useasreference,
    // prompt). The rendered selects already preselect the saved actions
    // server-side.
    savedSections: {}, savedActivities: {},
    maxSections: 0, noLimit: false, allowedTypes: [],
    namingPattern: 'Unidad {N} — {nombre}', namingStart: 1, categories: [],
};
/** @type {HTMLElement} Root element. */
let root = null;
/** @type {DynamicForm} The type-defaults/limits/allowed-types dynamic form (see init()). */
let configForm = null;
/**
 * Whether configForm's container already holds a real, server-rendered form
 * for the CURRENT course (true right after init(), from the initial page
 * load) — set false as soon as any course change requires an actual
 * DynamicForm.load() instead of just binding to what's already there.
 * @type {boolean}
 */
let configFormIsFreshFromPageLoad = false;

/** @returns {Object} Current state. */
export const getState = () => state;

/**
 * @param {Object} updates Properties to merge into state.
 */
export const setState = (updates) => {
    const coursechanged = Object.prototype.hasOwnProperty.call(updates, 'selectedCourseId');
    Object.assign(state, updates);
    if (coursechanged) {
        updateSelectedBanner();
        renderConfigRegion();
    }
};

/** @returns {HTMLElement} Root wizard element. */
export const getRoot = () => root;

/**
 * Reflect the currently selected course in the "selected course" banner.
 */
const updateSelectedBanner = () => {
    const banner = root.querySelector('[data-region="selected-banner"]');
    if (!banner) {
        return;
    }
    banner.classList.toggle('d-none', !state.selectedCourseId);
    if (!state.selectedCourseId) {
        return;
    }
    const nameEl = banner.querySelector('[data-region="selected-name"]');
    const shortEl = banner.querySelector('[data-region="selected-short"]');
    const linkEl = banner.querySelector('[data-region="selected-link"]');
    if (nameEl) {
        nameEl.textContent = state.selectedCourse?.fullname || '';
    }
    if (shortEl) {
        shortEl.textContent = state.selectedCourse?.shortname || '';
    }
    if (linkEl) {
        linkEl.href = M.cfg.wwwroot + '/course/view.php?id=' + state.selectedCourseId;
    }
};

/**
 * Bind the category/course autocomplete pair rendered by
 * classes/form/course_picker_form.php. Neither field is ever submitted —
 * their standard Moodle IDs (id_category, id_courseid) are just read
 * directly, the same way template name/description are read elsewhere in
 * this module.
 *
 * @param {HTMLElement} panel The step-1 panel containing the rendered form.
 */
const bindCoursePicker = (panel) => {
    const categoryField = panel.querySelector('#id_category');
    const courseField = panel.querySelector('#id_courseid');
    if (!categoryField || !courseField) {
        return;
    }

    // Picking a different category invalidates whatever course was chosen
    // for the previous one — the course autocomplete's own AJAX transport
    // re-scopes to the new category on the next keystroke, but a
    // previously chosen course from the old category must not linger.
    categoryField.addEventListener('change', () => {
        if (state.selectedCourseId) {
            setState({selectedCourseId: null, selectedCourse: null, courseStructure: null});
        }
    });

    courseField.addEventListener('change', () => {
        const id = parseInt(courseField.value, 10);
        if (!id) {
            return;
        }
        const label = courseField.options[courseField.selectedIndex]?.text || '';
        // Label is "Fullname (Shortname)" (see form_course_selector.js);
        // split it back out so the banner can show/link them separately.
        const match = label.match(/^(.*)\s\(([^)]*)\)$/);
        const fullname = match ? match[1] : label;
        const shortname = match ? match[2] : '';
        setState({
            selectedCourseId: id,
            selectedCourse: {id, fullname, shortname},
            courseStructure: null,
        });
    });
};

/**
 * Show/hide and populate the configuration region below the course picker,
 * based on whether a course is currently selected. This is the ONLY thing
 * that changes what's on screen — there is no step/page navigation.
 */
const renderConfigRegion = async() => {
    const region = root.querySelector('[data-region="config"]');
    if (!state.selectedCourseId) {
        region.classList.add('d-none');
        return;
    }
    region.classList.remove('d-none');

    try {
        if (!state.courseStructure) {
            resetSectionsRender();
            state.courseStructure = await Repository.getCourseStructure(state.selectedCourseId);
            initSectionState();
        }
    } catch (e) {
        Notification.exception(e);
        return;
    }

    // Type-defaults, limits and allowed-types all live in ONE dynamic form
    // now (see classes/form/template_config_form.php) — reloaded via
    // core_form/dynamicform whenever the selected course changes, instead
    // of a custom external function shuttling its HTML around. The very
    // first call after page load can skip reloading: edit_template.php
    // already server-rendered this exact form for this exact course, so
    // reloading it again would just be a redundant round-trip.
    const isFreshFromPageLoad = configFormIsFreshFromPageLoad;
    if (!isFreshFromPageLoad) {
        await configForm.load({courseid: state.selectedCourseId, templateid: state.templateId});
    }
    configFormIsFreshFromPageLoad = false;

    // renderStepSections needs the SAME "is this genuinely the initial
    // server-render for this exact course" fact — captured above before the
    // flag resets, since it answers the same question configForm.load()
    // just did for its own container, for the structure panel's own
    // container instead of inferring it from whatever markup happens to
    // already be sitting there (which a course switch would get wrong).
    const structurePanel = region.querySelector('[data-region="structure"]');
    await renderStepSections(structurePanel, state, isFreshFromPageLoad);

    // Limits, allowed-types and the naming pattern all live inside the
    // config form's own container now (see template_config_form.php) —
    // scope directly to it instead of the whole region.
    renderStepLimits(configForm.container, state);
};

/**
 * Seed section/activity state from a freshly loaded course structure.
 *
 * Each activity's initial action comes from its type's sensible default
 * (see type_action_sync.js) instead of hardcoding "modify" for everything —
 * an admin reviewing a real ~28-activity course should see mostly-correct
 * defaults already applied, not "modify" everywhere regardless of whether
 * the generator can even produce that type of content.
 *
 * When editing an existing template, its saved configuration wins over the
 * type defaults for every section/activity it still has a row for —
 * activities added to the base course since the template was saved keep
 * their type default, and saved rows for since-deleted cmids simply never
 * match anything.
 */
const initSectionState = () => {
    state.sectionBehavior = {};
    state.activityAction = {};
    state.activityRef = {};
    state.activityPrompt = {};

    const actTypes = new Set();
    state.courseStructure.forEach(s => {
        state.sectionBehavior[s.id] = state.savedSections[s.id] || 'custom';
        s.activities.forEach(a => {
            const saved = state.savedActivities[a.id];
            state.activityAction[a.id] = saved?.action || defaultActionForModname(a.modname);
            state.activityRef[a.id] = saved ? saved.useasreference !== false : true;
            state.activityPrompt[a.id] = saved?.prompt || '';
            actTypes.add(a.modname);
        });
    });
    // maxSections counts EXTRA sections the teacher may add on top of the
    // template's own — 0 until the allow-add-sections checkbox is ticked
    // (see step_limits.js), never the base course's own section count.
    state.maxSections = 0;
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
        cmid: a.id, action: state.activityAction[a.id] || 'keep',
        useasreference: state.activityRef[a.id] !== false,
        prompt: state.activityPrompt[a.id] || '',
    })),
}));

/**
 * Save template via repository and notify user.
 */
const saveTemplate = async() => {
    if (!state.selectedCourseId || !state.courseStructure) {
        const msg = await getString('template_select_course_first', 'local_coursegen');
        Notification.addNotification({message: msg, type: 'warning'});
        return;
    }
    try {
        const nameVal = root.querySelector('#id_templatename')?.value || state.templateName;
        const descVal = root.querySelector('#id_templatedesc')?.value || state.templateDesc;
        state.templateName = nameVal;
        state.templateDesc = descVal;
        await Repository.saveTemplate({
            id: state.templateId, name: nameVal,
            description: descVal, courseid: state.selectedCourseId,
            maxsections: state.maxSections, nolimit: state.noLimit,
            allowedtypes: JSON.stringify(state.allowedTypes),
            namingpattern: state.namingPattern, namingstart: state.namingStart,
            sections: buildSections(),
        });
        // A real, successful save — the course picker, config form and name
        // form all stay watched for changes (see their own definition()),
        // so without this the native "changes you made may not be saved"
        // warning would misfire on this very redirect.
        resetAllFormDirtyStates();
        // The sections controls live OUTSIDE any watched form: their dirty
        // protection is sections_events' own raw beforeunload listener,
        // which resetAllFormDirtyStates() above cannot see — it must be
        // dropped explicitly or the browser dialog fires on this redirect.
        // A failed save throws before reaching here, keeping the protection.
        resetSectionsDirtyState();
        window.location.href = M.cfg.wwwroot + '/local/coursegen/manage_templates.php';
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Initialise the template configuration screen.
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
    state.savedSections = config.savedsections || {};
    state.savedActivities = config.savedactivities || {};

    const initialCourseId = config.initialcourseid || 0;
    const initialCourseName = config.initialcoursename || '';
    const initialCourseShort = config.initialcourseshortname || '';
    if (initialCourseId > 0) {
        state.selectedCourseId = initialCourseId;
        state.selectedCourse = {id: initialCourseId, fullname: initialCourseName, shortname: initialCourseShort};
        // edit_template.php already server-rendered template_config_form for
        // this exact course — the first renderConfigRegion() call should
        // bind to it, not reload it.
        configFormIsFreshFromPageLoad = true;
    }

    configForm = new DynamicForm(
        root.querySelector('[data-region="config-form"]'),
        'local_coursegen\\form\\template_config_form'
    );
    // This form has no submit button — its fields feed the template-wide
    // Save action instead (see saveTemplate()) — but DynamicForm still
    // intercepts a native form submit (e.g. pressing Enter in a text field)
    // and, by default, empties the container once process_dynamic_submission()
    // returns. Prevent that: an accidental Enter keypress must not wipe the
    // rendered fields out from under the admin.
    configForm.addEventListener(configForm.events.FORM_SUBMITTED, e => e.preventDefault());

    root.querySelector('[data-action="save"]').addEventListener('click', saveTemplate);
    bindCoursePicker(root.querySelector('[data-region="step-panel"][data-step="1"]'));

    renderConfigRegion();
};
