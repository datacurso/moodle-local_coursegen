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
 * The editor of a template: shows or hides the instruction of each activity, picks the course and saves.
 *
 * @module     local_coursegen/template/editor
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Autocomplete from 'core/form-autocomplete';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {saveTemplate} from 'local_coursegen/repository/template';
import * as State from 'local_coursegen/template/state';

const PREFIX = 'local_coursegen/template/';
const ROW = '[data-region="' + PREFIX + 'activity"]';
const SET_ACTION = '[data-action="' + PREFIX + 'set-action"]';
const SAVE_ACTION = '[data-action="' + PREFIX + 'save"]';
const CANCEL_ACTION = '[data-action="' + PREFIX + 'cancel"]';
const INSTRUCTION_BOX = '[data-region="' + PREFIX + 'instruction"]';
const INSTRUCTION_TEXT = '[data-region="' + PREFIX + 'instruction-text"]';
const ERROR_STRINGS = {name: 'template_name_required', course: 'template_course_required'};

/**
 * The text of a field of the editor.
 *
 * @param {HTMLElement} root The editor.
 * @param {string} name The name of the region, for example "name".
 * @return {string}
 */
function fieldValue(root, name) {
    const field = root.querySelector('[data-region="' + PREFIX + name + '"]');
    if (!field) {
        return '';
    }

    return field.value;
}

/**
 * What the page shows for an activity: its id, the chosen action and the typed instruction.
 *
 * @param {HTMLElement} row The row of the activity.
 * @return {{cmid: number, action: string, instruction: string}}
 */
export function readRow(row) {
    const checked = row.querySelector('input[type="radio"]:checked');
    const textarea = row.querySelector(INSTRUCTION_TEXT);
    let action = State.KEEP;
    if (checked) {
        action = checked.value;
    }

    let instruction = '';
    if (textarea) {
        instruction = textarea.value;
    }

    return {cmid: parseInt(row.dataset.cmid, 10), action, instruction};
}

/**
 * Show or hide the instruction of an activity according to its chosen action.
 *
 * @param {HTMLElement} row The row of the activity.
 */
function syncInstruction(row) {
    const state = readRow(row);
    const visible = State.showsInstruction(state.action);
    const box = row.querySelector(INSTRUCTION_BOX);
    const aiRadio = row.querySelector('input[type="radio"][value="' + State.AI + '"]');
    if (box) {
        box.classList.toggle('d-none', !visible);
    }

    if (aiRadio) {
        const expanded = String(visible);
        aiRadio.setAttribute('aria-expanded', expanded);
    }
}

/**
 * The editor of one template.
 */
class Editor {
    /**
     * @param {HTMLElement} root The root of the editor.
     */
    constructor(root) {
        this.root = root;
        this.saving = false;
        this.leaving = false;
        this.onChange = this.onChange.bind(this);
        this.onClick = this.onClick.bind(this);
        this.onBeforeUnload = this.onBeforeUnload.bind(this);
        this.onCourseChange = this.onCourseChange.bind(this);
        const model = this.readModel();
        this.initialSignature = State.signature(model);
    }

    /**
     * Read everything the page shows into a model.
     *
     * @return {{templateid: number, courseid: number, name: string, description: string, rows: Array}}
     */
    readModel() {
        const rows = [];
        for (const element of this.root.querySelectorAll(ROW)) {
            rows.push(readRow(element));
        }

        return {
            templateid: parseInt(this.root.dataset.templateid, 10),
            courseid: parseInt(this.root.dataset.courseid, 10),
            name: fieldValue(this.root, 'name'),
            description: fieldValue(this.root, 'description'),
            rows,
        };
    }

    /**
     * Start listening and turn the course select into an autocomplete.
     */
    async start() {
        this.root.addEventListener('change', this.onChange);
        this.root.addEventListener('click', this.onClick);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        await this.enhanceCoursePicker();
    }

    /**
     * Turn the course select into an autocomplete that searches the courses of the chosen category.
     */
    async enhanceCoursePicker() {
        const select = this.root.querySelector('[data-region="' + PREFIX + 'course"]');
        if (!select) {
            return;
        }

        const placeholder = await getString('template_course_placeholder', 'local_coursegen');
        const noMatches = await getString('template_course_nomatches', 'local_coursegen');
        await Autocomplete.enhance(select, false, PREFIX + 'course_selector', placeholder, false, true, noMatches, true);
        select.addEventListener('change', this.onCourseChange);
    }

    /**
     * Handle a change of any field.
     *
     * @param {Event} event The change.
     */
    onChange(event) {
        const radio = event.target.closest(SET_ACTION);
        if (!radio) {
            return;
        }

        const row = radio.closest(ROW);
        syncInstruction(row);
    }

    /**
     * Handle the clicks of the buttons.
     *
     * @param {Event} event The click.
     */
    onClick(event) {
        if (event.target.closest(SAVE_ACTION)) {
            event.preventDefault();
            this.save();
            return;
        }

        if (event.target.closest(CANCEL_ACTION)) {
            this.leaving = true;
        }
    }

    /**
     * Whether the admin changed something that was not saved.
     *
     * @return {boolean}
     */
    hasUnsavedChanges() {
        const model = this.readModel();

        return State.isDirty(this.initialSignature, model);
    }

    /**
     * Warn before leaving the page with changes that were not saved.
     *
     * @param {Event} event The event of the browser.
     */
    onBeforeUnload(event) {
        if (this.saving || this.leaving) {
            return;
        }

        if (this.hasUnsavedChanges()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    /**
     * Load the structure of the course that was chosen.
     */
    async onCourseChange() {
        const select = this.root.querySelector('[data-region="' + PREFIX + 'course"]');
        const courseid = parseInt(select.value, 10);
        if (!(courseid > 0)) {
            return;
        }

        if (this.hasUnsavedChanges()) {
            const title = await getString('template_unsaved', 'local_coursegen');
            const label = await getString('continue', 'core');
            await Notification.saveCancelPromise(title, title, label);
        }

        this.openCourse(courseid);
    }

    /**
     * Open the editor again with another course.
     *
     * @param {number} courseid The course to show, for example 42.
     */
    openCourse(courseid) {
        const templateid = parseInt(this.root.dataset.templateid, 10);
        const url = new URL(this.root.dataset.editurl, window.location.href);
        if (templateid > 0) {
            const idText = String(templateid);
            url.searchParams.set('id', idText);
        }

        const courseText = String(courseid);
        url.searchParams.set('courseid', courseText);
        this.leaving = true;
        const address = url.toString();
        window.location.assign(address);
    }

    /**
     * Say what is missing before the template can be saved.
     *
     * @param {string[]} errors Keys of what is missing.
     */
    async showErrors(errors) {
        const messages = [];
        for (const key of errors) {
            const message = await getString(ERROR_STRINGS[key], 'local_coursegen');
            messages.push(message);
        }

        const box = this.root.querySelector('[data-region="' + PREFIX + 'error"]');
        box.textContent = messages.join(' ');
        box.classList.remove('d-none');
    }

    /**
     * Save the template and go back to the list.
     */
    async save() {
        if (this.saving) {
            return;
        }

        const model = this.readModel();
        const errors = State.validate(model);
        if (errors.length > 0) {
            await this.showErrors(errors);
            return;
        }

        this.saving = true;
        await this.showStatus('template_saving');
        try {
            const payload = State.buildPayload(model);
            await saveTemplate(payload);
            this.initialSignature = State.signature(model);
            window.location.assign(this.root.dataset.listurl + '?saved=1');
        } catch (error) {
            this.saving = false;
            await this.showStatus('template_save_error');
            Notification.exception(error);
        }
    }

    /**
     * Show a short message next to the buttons.
     *
     * @param {string} key The key of the string, for example "template_saving".
     */
    async showStatus(key) {
        const message = await getString(key, 'local_coursegen');
        const status = this.root.querySelector('[data-region="' + PREFIX + 'status"]');
        status.textContent = message;
    }
}

/**
 * Start the editor.
 *
 * @param {string} rootId The id of the root of the editor.
 */
export function init(rootId) {
    const root = document.getElementById(rootId);
    if (!root) {
        return;
    }

    const editor = new Editor(root);
    editor.start();
}
