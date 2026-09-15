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

import {collectInstancesForSection} from 'local_coursegen/local/template/template_instance_rows';
import {resetSectionsDirtyState} from 'local_coursegen/local/template/sections_events';
import * as Repository from 'local_coursegen/local/template/repository';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {resetAllFormDirtyStates} from 'core_form/changechecker';

/**
 * Scrape one section's currently rendered virtual instances.
 *
 * @param {HTMLElement} root The wizard root element.
 * @param {number} sectionid
 * @returns {Array}
 */
const collectSectionInstances = (root, sectionid) => {
    const sectionEl = root.querySelector('[data-for="section"][data-id="' + sectionid + '"]');
    if (!sectionEl) {
        return [];
    }
    return collectInstancesForSection(sectionEl);
};

/**
 * Build the section payload from current state.
 *
 * @param {Object} state The live wizard state from init.js.
 * @param {HTMLElement} root The wizard root element.
 * @returns {Array}
 */
const buildSections = (state, root) => state.courseStructure.map(s => ({
    sectionid: s.id, sectionnum: s.num,
    behavior: state.sectionBehavior[s.id] || 'custom',
    instances: collectSectionInstances(root, s.id),
    activities: s.activities.map(a => ({
        cmid: a.id, action: state.activityAction[a.id] || 'keep',
        useasreference: state.activityRef[a.id] !== false,
        prompt: state.activityPrompt[a.id] || '',
        templatescope: state.activityScope[a.id] || 'course',
    })),
}));

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
    const nameInput = root.querySelector('#id_templatename');
    const nameVal = nameInput?.value || state.templateName || '';
    if (nameVal.trim() === '') {
        // template_name_form.php's own "required" rule never actually runs
        // (it is client-only, and this save never submits that mform) —
        // this is the only thing that actually stops a blank name from
        // reaching saveTemplate() at all (external/save_template.php
        // rejects one too, but only after this round trip).
        const msg = await getString('template_name_required', 'local_coursegen');
        Notification.addNotification({message: msg, type: 'warning'});
        nameInput?.focus();
        return;
    }
    try {
        const descVal = root.querySelector('#id_templatedesc')?.value || state.templateDesc;
        state.templateName = nameVal;
        state.templateDesc = descVal;
        await Repository.saveTemplate({
            id: state.templateId, name: nameVal,
            description: descVal, courseid: state.selectedCourseId,
            maxsections: state.maxSections, nolimit: state.noLimit,
            allowedtypes: JSON.stringify(state.allowedTypes),
            namingpattern: state.namingPattern, namingstart: state.namingStart,
            sections: buildSections(state, root),
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
