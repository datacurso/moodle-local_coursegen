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
 * Builds the save payload from the live editor state and the rendered review,
 * and performs the actual save. Split out of init.js so that module stays
 * focused on state/region orchestration.
 *
 * @module     local_coursegen/local/template/save_payload
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildPayload} from 'local_coursegen/local/template/items_state';
import {resetSectionsDirtyState} from 'local_coursegen/local/template/sections_events';
import * as Repository from 'local_coursegen/local/template/repository';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {resetAllFormDirtyStates} from 'core_form/changechecker';
import {notifyFormSubmittedByJavascript, eventTypes} from 'core_form/events';
import Selectors from 'local_coursegen/local/template/selectors';
import {ACTION, COMPONENT} from 'local_coursegen/local/template/constants';

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
 * other mform shows, not a hand-rolled substitute for it. This screen
 * never actually submits that mform (its "Save template" button reads
 * field values directly and posts a webservice call instead), so nothing
 * ever fires this on its own.
 *
 * @param {HTMLElement} root The editor root element.
 * @returns {boolean} False when the form reported at least one invalid field.
 */
const nameFormIsValid = (root) => {
    const form = root.querySelector(Selectors.forms.nameForm);
    const result = {hasError: false};
    const onFieldError = recordFieldError.bind(null, result);
    form.addEventListener(eventTypes.formFieldValidationFailed, onFieldError);
    notifyFormSubmittedByJavascript(form);
    form.removeEventListener(eventTypes.formFieldValidationFailed, onFieldError);
    return !result.hasError;
};

/**
 * What the page shows for every activity, in the order of the page.
 *
 * @param {HTMLElement} root The editor root element.
 * @param {Object} state The live editor state from init.js.
 * @returns {Array} Rows {cmid, action, instruction}.
 */
const collectRows = (root, state) => {
    const rows = [];
    const activityRows = root.querySelectorAll(Selectors.rows.activity);
    for (const activityRow of activityRows) {
        const cmid = parseInt(activityRow.dataset.id, 10);
        rows.push({
            cmid,
            action: state.activityAction[cmid] || ACTION.KEEP,
            instruction: state.activityInstruction[cmid] || '',
        });
    }
    return rows;
};

/**
 * Save the template via the repository, then redirect back to the manage
 * screen; shows a warning instead when no course has been picked yet.
 *
 * @param {Object} state The live editor state from init.js.
 * @param {HTMLElement} root The editor root element.
 */
export const saveTemplate = async(state, root) => {
    if (!state.selectedCourseId) {
        const msg = await getString('template_select_course_first', COMPONENT);
        Notification.addNotification({message: msg, type: 'warning'});
        return;
    }
    if (!nameFormIsValid(root)) {
        return;
    }
    try {
        const nameField = root.querySelector(Selectors.regions.templateName);
        const descField = root.querySelector(Selectors.regions.templateDescription);
        state.templateName = nameField.value;
        state.templateDesc = descField.value;
        const payload = buildPayload({
            templateid: state.templateId,
            courseid: state.selectedCourseId,
            name: state.templateName,
            description: state.templateDesc,
            rows: collectRows(root, state),
        });
        await Repository.saveTemplate(payload);
        // A real, successful save — the course picker and the name form stay
        // watched for changes (see their own definition()), so without this
        // the native "changes you made may not be saved" warning would
        // misfire on this very redirect.
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
