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
 * chosen from the list the column's one-line picker (#tplPicker) opens below
 * itself: picking one names it on that line, tells the hidden native select
 * (which template_mode.js listens to, loading the structure), and unlocks the
 * composer; the line's × clears it and the column offers the list again. The
 * page can attach one itself too (?templateid=). Opening and closing the
 * template layout itself lives in template_layout.js; the list the picker
 * opens below itself lives in template_list.js. This module is what ties a
 * chosen template's id to both of those and to the picker line's own chrome.
 *
 * @module     local_coursegen/local/courseai/context/template
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createTemplateList} from 'local_coursegen/local/courseai/context/template_list';
import {isLocked, setTemplateLayout, setPickerValue} from 'local_coursegen/local/courseai/context/template_layout';

/**
 * Create template interaction handlers.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.texts
 * @returns {{
 *   renderTemplateLists: Function,
 *   selectTemplate: Function,
 *   detachTemplate: Function,
 *   getSelectedTemplate: Function,
 *   setTemplateLayout: Function,
 *   closeTemplatePopovers: Function,
 *   isLocked: Function,
 *   setPickerOpen: Function,
 *   moveActive: Function,
 *   pickActive: Function
 * }}
 */
export const createTemplateHandlers = ({state, texts}) => {
    const getSelectedTemplate = () => {
        if (state.selectedTemplateId === null || state.selectedTemplateId === undefined) {
            return null;
        }
        return (state.templates || []).find((t) => String(t.id) === String(state.selectedTemplateId)) || null;
    };

    const {renderTemplateLists, moveActive, pickActive, resetActive} = createTemplateList({
        state,
        texts,
        onSelect: (id) => selectTemplate(id),
    });

    /**
     * Name the chosen template on the picker line, and let the composer be
     * used only once there is one: before that, the column's whole job is to
     * pick it.
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
            let placeholder = texts.courseai_template_prompt_locked || promptInput.dataset.placeholderReady;
            if (template) {
                placeholder = promptInput.dataset.placeholderReady;
            }
            promptInput.placeholder = placeholder;
        }
        if (plusBtn) {
            plusBtn.disabled = !template;
        }
        const picker = document.getElementById('tplPicker');
        const nameEl = document.getElementById('tplPickerName');
        const courseEl = document.getElementById('tplPickerCourse');
        const clearBtn = document.getElementById('tplPickerClear');
        if (picker) {
            picker.classList.toggle('has-value', !!template);
            let pickerTitle = '';
            if (template) {
                pickerTitle = [template.name, template.coursefullname].filter(Boolean).join(' · ');
            }
            picker.title = pickerTitle;
        }
        if (nameEl) {
            let name = '';
            if (template) {
                name = template.name;
            }
            nameEl.textContent = name;
        }
        if (courseEl) {
            let courseName = '';
            if (template) {
                courseName = template.coursefullname || '';
            }
            courseEl.textContent = courseName;
        }
        if (clearBtn) {
            clearBtn.hidden = !template;
        }
    };

    /**
     * Switch the picker line between its two mutually-exclusive states: the
     * button naming the choice, or the search box the list is filtered
     * from. Opening focuses and clears the box; closing restores the label
     * and forgets whatever was typed, so the next open starts fresh. Only
     * the professor's own click on the button opens this - see
     * context_section.js - so the focus that comes with it is always wanted.
     *
     * @param {boolean} open
     */
    const setPickerOpen = (open) => {
        const shell = document.getElementById('tplPickerShell');
        const picker = document.getElementById('tplPicker');
        const search = document.getElementById('templateSearchTpl');
        if (!shell || !picker || !search) {
            return;
        }
        shell.classList.toggle('is-open', open);
        picker.hidden = open;
        picker.setAttribute('aria-expanded', String(open));
        search.hidden = !open;
        if (open) {
            search.value = '';
            state.templateSearchQuery = '';
            resetActive();
            search.focus();
        }
    };

    /**
     * Choose a template. Choosing the one already chosen just closes the list.
     *
     * @param {string|number} id
     * @param {Object} [options]
     * @param {boolean} [options.focus=true] Give the composer focus once the template
     *                  is attached. Off for the one automatic call - a template already
     *                  named in the address (?templateid=) on page load - where nobody
     *                  clicked a row to get here.
     */
    const selectTemplate = (id, {focus = true} = {}) => {
        const strId = String(id);
        let currentId = null;
        if (state.selectedTemplateId !== null && state.selectedTemplateId !== undefined) {
            currentId = String(state.selectedTemplateId);
        }
        if (currentId === strId) {
            closeTemplatePopovers();
            return;
        }
        const template = (state.templates || []).find((t) => String(t.id) === strId);
        if (!template) {
            return;
        }
        state.selectedTemplateId = template.id;
        refreshTemplateChrome();
        renderTemplateLists();
        setPickerValue(strId);
        setTemplateLayout(true);
        closeTemplatePopovers();
        if (focus) {
            document.getElementById('tplPromptInput')?.focus();
        }
    };

    /**
     * Remove the chosen template. The path stays "from a template": the line
     * reads "Choose template" again and the column offers the list.
     */
    const detachTemplate = () => {
        if (isLocked()) {
            return;
        }
        state.selectedTemplateId = null;
        refreshTemplateChrome();
        renderTemplateLists();
        setPickerValue('');
        closeTemplatePopovers();
    };

    /**
     * Close every open template popover panel.
     */
    const closeTemplatePopoverPanels = () => {
        document.querySelectorAll('.popover-panel[id^="templatesPopover"].open').forEach((panel) => {
            panel.classList.remove('open');
        });
    };

    /**
     * Reset every template popover trigger's expanded state.
     */
    const resetTemplatePopoverTriggers = () => {
        document.querySelectorAll('[aria-controls^="templatesPopover"]').forEach((btn) => {
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    /**
     * Close every template popover and reset its trigger.
     */
    const closeTemplatePopovers = () => {
        closeTemplatePopoverPanels();
        resetTemplatePopoverTriggers();
        setPickerOpen(false);
    };

    // The composer starts locked: nothing to adapt until a template is chosen.
    refreshTemplateChrome();

    return {
        renderTemplateLists,
        selectTemplate,
        detachTemplate,
        getSelectedTemplate,
        setTemplateLayout,
        closeTemplatePopovers,
        isLocked,
        setPickerOpen,
        moveActive,
        pickActive,
    };
};
