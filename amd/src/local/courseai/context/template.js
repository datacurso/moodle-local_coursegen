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
 * The chosen template and what choosing it does.
 *
 * "From a template" is a starting point chosen on the page's first screen
 * (start_path.js), and this module is what happens inside it. The template is
 * chosen in Moodle's own autocomplete field (the native picker form, whose
 * <select> template_mode.js listens to): picking one there loads the
 * structure, and the field's own × clears it. What this module adds is the
 * rest of the column following that value: the composer stays locked until
 * there is a template, and the page can attach one itself (?templateid=).
 * Opening and closing the template layout (the `is-template` class, the
 * shared ids, the professor's text carried between composers) lives here
 * too, for start_path.js to drive.
 *
 * The two layouts share the ids of their thread and decision elements
 * (data-shared-id): only the column that is active carries them, so every
 * module that looks an id up finds the element that is on screen.
 *
 * @module     local_coursegen/local/courseai/context/template
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Give the shared ids to the column that is active and take them from the other.
 *
 * @param {boolean} templateActive
 */
const claimSharedIds = (templateActive) => {
    const templateColumn = document.getElementById('templateModeView');
    document.querySelectorAll('[data-shared-id]').forEach((el) => {
        const inTemplate = !!templateColumn && templateColumn.contains(el);
        if (inTemplate === templateActive) {
            el.id = el.dataset.sharedId;
        } else {
            el.removeAttribute('id');
        }
    });
};

/**
 * Create template interaction handlers.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.texts
 * @returns {{
 *   selectTemplate: Function,
 *   getSelectedTemplate: Function,
 *   setTemplateLayout: Function,
 *   focusTemplatePicker: Function
 * }}
 */
export const createTemplateHandlers = ({state, texts}) => {
    const picker = () => document.getElementById('id_templateid');

    const getSelectedTemplate = () => {
        if (state.selectedTemplateId === null || state.selectedTemplateId === undefined) {
            return null;
        }
        return (state.templates || []).find((t) => String(t.id) === String(state.selectedTemplateId)) || null;
    };

    /**
     * Let the composer be used only once there is a template: before that,
     * the column's whole job is to pick it.
     */
    const refreshTemplateChrome = () => {
        const template = getSelectedTemplate();
        const promptInput = document.getElementById('tplPromptInput');
        const plusBtn = document.getElementById('tplBtnPlusMenu');
        if (promptInput) {
            if (!promptInput.dataset.placeholderReady) {
                promptInput.dataset.placeholderReady = promptInput.placeholder;
            }
            promptInput.disabled = !template;
            promptInput.placeholder = template
                ? promptInput.dataset.placeholderReady
                : (texts.courseai_template_prompt_locked || promptInput.dataset.placeholderReady);
        }
        if (plusBtn) {
            plusBtn.disabled = !template;
        }
    };

    /**
     * Carry the professor's text from one composer to the other.
     *
     * @param {string} fromId
     * @param {string} toId
     */
    const carryPrompt = (fromId, toId) => {
        const from = document.getElementById(fromId);
        const to = document.getElementById(toId);
        if (!from || !to) {
            return;
        }
        if (from.value.trim() !== '' || to.value.trim() === '') {
            to.value = from.value;
        }
        to.dispatchEvent(new Event('input', {bubbles: true}));
    };

    /**
     * Open or close the template layout.
     *
     * @param {boolean} on
     */
    const setTemplateLayout = (on) => {
        const workspace = document.getElementById('courseaiWorkspace');
        if (!workspace) {
            return;
        }
        const wasOn = workspace.classList.contains('is-template');
        if (on === wasOn) {
            return;
        }
        claimSharedIds(on);
        workspace.classList.toggle('is-template', on);
        if (on) {
            carryPrompt('promptInput', 'tplPromptInput');
        } else {
            carryPrompt('tplPromptInput', 'promptInput');
        }
    };

    /**
     * Put the cursor in the picker's search box.
     *
     * Core enhances the field a moment after the page arrives, so the box is
     * looked up when asked for, not once at start.
     */
    const focusTemplatePicker = () => {
        document.querySelector('#fitem_id_templateid .form-autocomplete-input input')?.focus();
    };

    /**
     * Attach a template the page named itself (?templateid=). The form has
     * already given the select that value, so the field already shows it; what
     * is left is to tell template_mode.js, which loads the structure on the
     * select's change.
     *
     * @param {string|number} id
     */
    const selectTemplate = (id) => {
        const select = picker();
        const template = (state.templates || []).find((t) => String(t.id) === String(id));
        if (!select || !template) {
            return;
        }
        state.selectedTemplateId = template.id;
        refreshTemplateChrome();
        select.value = String(id);
        select.dispatchEvent(new Event('change', {bubbles: true}));
        setTemplateLayout(true);
    };

    // Whatever the professor does in the field, picking or clearing with the
    // tag's ×, arrives as the select's change; the composer follows it. The
    // cursor moves on to what comes next: the composer once there is a
    // template, the search box again once there is none (core leaves the
    // focus on the tag it just removed).
    picker()?.addEventListener('change', () => {
        const value = picker().value;
        state.selectedTemplateId = value === '' ? null : value;
        refreshTemplateChrome();
        if (value !== '') {
            document.getElementById('tplPromptInput')?.focus();
        } else {
            focusTemplatePicker();
        }
    });

    // The composer starts locked: nothing to adapt until a template is chosen.
    refreshTemplateChrome();

    return {
        selectTemplate,
        getSelectedTemplate,
        setTemplateLayout,
        focusTemplatePicker,
    };
};
