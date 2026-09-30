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
 * Builds the save payload from the live wizard state (plus, for virtual
 * instances, the DOM itself — there is no JS-side state for those, same as
 * any other plain form field) and performs the actual save. Split out of
 * init.js so that module stays focused on state/region orchestration.
 *
 * @module     local_coursegen/local/template/save_payload
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {collectVirtualRowsForSection} from 'local_coursegen/local/template/template_instance_rows';
import {resetSectionsDirtyState} from 'local_coursegen/local/template/sections_events';
import * as Repository from 'local_coursegen/local/template/repository';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {resetAllFormDirtyStates} from 'core_form/changechecker';
import {notifyFormSubmittedByJavascript, eventTypes} from 'core_form/events';

/**
 * Remember that the name form reported an invalid field.
 *
 * qf_errorHandler (lib/formslib.php) fires the event for every checked
 * field regardless of outcome, with an empty message on success — only a
 * non-empty one is an actual failure.
 *
 * @param {{hasError: boolean}} result Where the failure is recorded.
 * @param {CustomEvent} e The field validation event.
 */
const recordFieldError = (result, e) => {
    if (e.detail?.message) {
        result.hasError = true;
    }
};

/**
 * Run the name form's own client-side validation (classes/form/
 * template_name_form.php's "required" rule) exactly as if it had been
 * submitted for real — the same red border and inline error text any
 * other mform shows, not a hand-rolled substitute for it. This wizard
 * never actually submits that mform (its "Save template" button reads
 * field values directly and posts a webservice call instead), so nothing
 * ever fires this on its own.
 *
 * @param {HTMLElement} root The wizard root element.
 * @returns {boolean} False when the form reported at least one invalid field.
 */
const nameFormIsValid = (root) => {
    const nameField = root.querySelector('#id_templatename');
    const form = nameField?.closest('form');
    if (!form) {
        return true;
    }
    const result = {hasError: false};
    const onFieldError = recordFieldError.bind(null, result);
    form.addEventListener(eventTypes.formFieldValidationFailed, onFieldError);
    notifyFormSubmittedByJavascript(form);
    form.removeEventListener(eventTypes.formFieldValidationFailed, onFieldError);
    return !result.hasError;
};

/**
 * Scrape one section's currently rendered virtual rows.
 *
 * @param {HTMLElement} root The wizard root element.
 * @param {number} sectionid
 * @returns {Object} {instances, spaces}
 */
const collectSectionVirtualRows = (root, sectionid) => {
    const sectionEl = root.querySelector('[data-for="section"][data-id="' + sectionid + '"]');
    if (!sectionEl) {
        return {instances: [], spaces: []};
    }
    return collectVirtualRowsForSection(sectionEl);
};

/**
 * Build the payload of one activity from current state.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {Object} activity An activity of the loaded course structure.
 * @returns {Object}
 */
const buildActivityPayload = (state, activity) => {
    const id = activity.id;
    return {
        cmid: id,
        action: state.activityAction[id] || 'keep',
        useasreference: state.activityRef[id] !== false,
        prompt: state.activityPrompt[id] || '',
        templatescope: state.activityScope[id] || 'course',
        spacerequired: state.activitySpace[id]?.required !== false,
        spaceinstruction: state.activitySpace[id]?.instruction || '',
    };
};

/**
 * Build the payload of every activity of a section from current state.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {Array} activities The activities of a section of the loaded course structure.
 * @returns {Array}
 */
const buildActivityPayloads = (state, activities) => {
    const payloads = [];
    for (const activity of activities) {
        const payload = buildActivityPayload(state, activity);
        payloads.push(payload);
    }
    return payloads;
};

/**
 * Build the payload of one section from current state.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {HTMLElement} root The wizard root element.
 * @param {Object} section A section of the loaded course structure.
 * @returns {Object}
 */
const buildSectionPayload = (state, root, section) => {
    const virtualRows = collectSectionVirtualRows(root, section.id);
    const activities = buildActivityPayloads(state, section.activities);
    return {
        sectionid: section.id,
        sectionnum: section.num,
        behavior: state.sectionBehavior[section.id] || 'aimodify',
        instances: virtualRows.instances,
        spaces: virtualRows.spaces,
        activities,
    };
};

/**
 * Build the section payload from current state.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {HTMLElement} root The wizard root element.
 * @returns {Array}
 */
const buildSections = (state, root) => {
    const sections = [];
    for (const section of state.courseStructure) {
        const payload = buildSectionPayload(state, root, section);
        sections.push(payload);
    }
    return sections;
};

/**
 * Save the template via the repository, then redirect back to the manage
 * screen; shows a warning instead when no course has been picked yet.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {HTMLElement} root The wizard root element.
 */
export const saveTemplate = async(state, root) => {
    if (!state.selectedCourseId || !state.courseStructure) {
        const msg = await getString('template_select_course_first', 'local_coursegen');
        Notification.addNotification({message: msg, type: 'warning'});
        return;
    }
    if (!nameFormIsValid(root)) {
        return;
    }
    try {
        const nameVal = root.querySelector('#id_templatename')?.value || state.templateName;
        const descVal = root.querySelector('#id_templatedesc')?.value || state.templateDesc;
        state.templateName = nameVal;
        state.templateDesc = descVal;
        const sections = buildSections(state, root);
        await Repository.saveTemplate({
            id: state.templateId, name: nameVal,
            description: descVal, courseid: state.selectedCourseId,
            maxsections: state.maxSections, nolimit: state.noLimit,
            namingpattern: state.namingPattern, namingstart: state.namingStart,
            sections,
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
