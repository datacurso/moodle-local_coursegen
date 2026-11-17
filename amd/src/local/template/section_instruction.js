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
 * The instruction of a template section: the site's editor inside the section's card.
 *
 * A section whose behavior is "Template section" shows the editor above its activities. The editor is a
 * dynamic form (local_coursegen\form\section_instruction_form) loaded into the card and never submitted on
 * its own: the template's save reads its text and its draft area (see save_payload.js).
 *
 * @module     local_coursegen/local/template/section_instruction
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Notification from 'core/notification';

/** The behavior value of a template section. */
export const TEMPLATE_BEHAVIOR = 'template';

/** The form class of the editor. */
const FORM_CLASS = 'local_coursegen\\form\\section_instruction_form';

/** The name of the editor field, as the form posts it. */
const FIELD = 'instruction_editor';

/** The forms already loaded, by section id. */
const forms = new Map();

/**
 * The region of a section's instruction.
 *
 * @param {HTMLElement} root
 * @param {number} sid
 * @returns {HTMLElement|null}
 */
const regionOf = (root, sid) => root.querySelector(`[data-region="section-instruction"][data-sid="${sid}"]`);

/**
 * Load the editor into the section's card, once.
 *
 * @param {HTMLElement} region
 * @param {number} sid
 * @param {number} templateid
 * @returns {Promise<void>}
 */
const loadForm = async(region, sid, templateid) => {
    if (forms.has(sid)) {
        return;
    }
    const container = region.querySelector('[data-region="section-instruction-form"]');
    if (!container) {
        return;
    }
    const form = new DynamicForm(container, FORM_CLASS);
    forms.set(sid, form);
    try {
        await form.load({sectionid: sid, templateid});
    } catch (error) {
        forms.delete(sid);
        Notification.exception(error);
    }
};

/**
 * Show or hide a section's instruction, loading the editor the first time it shows.
 *
 * @param {HTMLElement} root The rendered course sections review.
 * @param {number} sid
 * @param {string} behavior The section's behavior.
 * @param {number} templateid
 * @returns {Promise<void>}
 */
export const showInstructionFor = async(root, sid, behavior, templateid) => {
    const region = regionOf(root, sid);
    if (!region) {
        return;
    }
    const shown = behavior === TEMPLATE_BEHAVIOR;
    region.hidden = !shown;
    if (shown) {
        await loadForm(region, sid, templateid);
    }
};

/**
 * Load the editor of every section that is already a template section.
 *
 * @param {HTMLElement} root
 * @param {number} templateid
 */
export const bindSectionInstructions = (root, templateid) => {
    forms.clear();
    root.querySelectorAll('[data-region="section-instruction"]:not([hidden])').forEach((region) => {
        loadForm(region, parseInt(region.dataset.sid, 10), templateid);
    });
};

/**
 * Write what the editor holds into its field, so the field says what the person sees.
 *
 * @param {HTMLTextAreaElement} field
 * @returns {Promise<void>}
 */
const syncEditor = async(field) => {
    try {
        const tiny = await import('editor_tiny/editor');
        tiny.getInstanceForElementId(field.id)?.save();
    } catch (error) {
        // Another editor, or none: the field already holds the text.
    }
};

/**
 * The instruction of a section, as the save sends it; empty values when it has none.
 *
 * @param {HTMLElement} root
 * @param {number} sid
 * @returns {Promise<{instruction: string, instructionformat: number, instructiondraftid: number}>}
 */
export const instructionOf = async(root, sid) => {
    const empty = {instruction: '', instructionformat: 1, instructiondraftid: 0};
    const region = regionOf(root, sid);
    const text = region?.querySelector(`textarea[name="${FIELD}[text]"]`);
    if (!region || region.hidden || !text) {
        return empty;
    }
    await syncEditor(text);
    const format = region.querySelector(`[name="${FIELD}[format]"]`);
    const draft = region.querySelector(`[name="${FIELD}[itemid]"]`);
    return {
        instruction: text.value,
        instructionformat: parseInt(format?.value || '1', 10) || 1,
        instructiondraftid: parseInt(draft?.value || '0', 10) || 0,
    };
};
