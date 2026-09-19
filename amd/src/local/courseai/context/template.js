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
 * template layout (the `is-template` class, the shared ids, the professor's
 * text carried between composers) lives here too, for start_path.js to drive.
 *
 * The two layouts share the ids of their thread and decision elements
 * (data-shared-id): only the column that is active carries them, so every
 * module that looks an id up finds the element that is on screen.
 *
 * @module     local_coursegen/local/courseai/context/template
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {escapeHtml} from 'local_coursegen/local/courseai/utils';

/** The list a template is picked from: its <ul> and its search box. */
const LISTS = [
    {list: 'templateListTpl', search: 'templateSearchTpl'},
];

/** Index of the keyboard-highlighted row within the currently filtered list. */
let activeIndex = -1;

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
 * Whether the starting point is fixed: planning has started in the free path,
 * or a generation is running in the template path.
 *
 * @returns {boolean}
 */
const isLocked = () => (document.getElementById('courseaiWorkspace')?.classList.contains('is-planning') ?? false)
    || document.body.classList.contains('cg-generating');

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

    /**
     * The templates the current search query matches, in list order.
     *
     * @returns {Array}
     */
    const getFilteredTemplates = () => {
        const query = (state.templateSearchQuery || '').toLowerCase();
        return (state.templates || []).filter((t) =>
            !query ||
            (t.name || '').toLowerCase().includes(query) ||
            (t.coursefullname || '').toLowerCase().includes(query)
        );
    };

    /**
     * Move the keyboard-highlighted row.
     *
     * @param {number} delta +1 or -1
     */
    const moveActive = (delta) => {
        const filtered = getFilteredTemplates();
        if (filtered.length === 0) {
            return;
        }
        activeIndex = ((activeIndex < 0 ? -1 : activeIndex) + delta + filtered.length) % filtered.length;
        renderTemplateLists();
    };

    /**
     * Choose whichever row the keyboard is currently on.
     */
    const pickActive = () => {
        const filtered = getFilteredTemplates();
        const template = filtered[activeIndex] || filtered[0];
        if (template) {
            selectTemplate(template.id);
        }
    };

    /**
     * Render every template list that exists on the page: one line per
     * template, the chosen one carrying a check instead of a radio.
     */
    const renderTemplateLists = () => {
        const filtered = getFilteredTemplates();
        LISTS.forEach(({list}) => {
            const el = document.getElementById(list);
            if (!el) {
                return;
            }
            if (filtered.length === 0) {
                el.innerHTML = `<li class="tpl-combo-empty">${escapeHtml(texts.courseai_no_results || '')}</li>`;
                return;
            }
            el.innerHTML = filtered.map((t, index) => {
                const isSelected = String(state.selectedTemplateId) === String(t.id);
                const isActive = index === activeIndex;
                return `
                    <li class="tpl-combo-item${isSelected ? ' selected' : ''}${isActive ? ' is-active' : ''}"
                        id="tplComboItem-${t.id}" data-select="${t.id}"
                        role="option" aria-selected="${isSelected}">
                        <span class="tpl-combo-item-name">${escapeHtml(t.name)}</span>
                        <span class="tpl-combo-item-course">${escapeHtml(t.coursefullname || '')}</span>
                        <svg class="tpl-combo-item-check" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <path d="M5 12.5l4.5 4.5L19 7.5"/>
                        </svg>
                    </li>
                `;
            }).join('');
            el.querySelectorAll('.tpl-combo-item[data-select]').forEach((row) => {
                row.addEventListener('click', () => selectTemplate(row.getAttribute('data-select')));
            });
        });
    };

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
            promptInput.placeholder = template
                ? promptInput.dataset.placeholderReady
                : (texts.courseai_template_prompt_locked || promptInput.dataset.placeholderReady);
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
            picker.title = template ? [template.name, template.coursefullname].filter(Boolean).join(' · ') : '';
        }
        if (nameEl) {
            nameEl.textContent = template ? template.name : '';
        }
        if (courseEl) {
            courseEl.textContent = template ? (template.coursefullname || '') : '';
        }
        if (clearBtn) {
            clearBtn.hidden = !template;
        }
    };

    /**
     * Switch the picker line between its two mutually-exclusive states: the
     * button naming the choice, or the search box the list is filtered
     * from. Closing restores the label and forgets whatever was typed, so
     * the next open starts fresh.
     *
     * @param {boolean} open
     * @param {Object} [options]
     * @param {boolean} [options.focus=true] Give the search box the keyboard focus.
     *                  Only for an open a professor's own click asked for - a popover
     *                  the page opens by itself (landing on ?mode=template) must not
     *                  steal focus nobody asked to give it.
     */
    const setPickerOpen = (open, {focus = true} = {}) => {
        const shell = document.getElementById('tplPickerShell');
        const picker = document.getElementById('tplPicker');
        const search = document.getElementById('templateSearchTpl');
        if (!shell || !picker || !search) {
            return;
        }
        shell.classList.toggle('is-open', open);
        picker.hidden = open;
        picker.setAttribute('aria-expanded', open ? 'true' : 'false');
        search.hidden = !open;
        if (open) {
            search.value = '';
            state.templateSearchQuery = '';
            activeIndex = -1;
            if (focus) {
                search.focus();
            }
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
        // Always align the shared ids with what's being asked for: this runs
        // once at boot too, to settle a page the server already rendered
        // into the template layout, and claimSharedIds() only ever looks at
        // where each element currently sits, so repeating it is harmless.
        claimSharedIds(on);
        const wasOn = workspace.classList.contains('is-template');
        if (on === wasOn) {
            return;
        }
        workspace.classList.toggle('is-template', on);
        if (on) {
            carryPrompt('promptInput', 'tplPromptInput');
        } else {
            carryPrompt('tplPromptInput', 'promptInput');
        }
    };

    /**
     * Tell the native picker which template is attached; template_mode.js
     * listens to its 'change' and loads or clears the structure. The change
     * is always dispatched: for a template named in the address the server
     * has already given the select that value, and the structure still has
     * to load.
     *
     * @param {string} value
     */
    const setPickerValue = (value) => {
        const select = document.getElementById('id_templateid');
        if (!select) {
            return;
        }
        select.value = value;
        select.dispatchEvent(new Event('change', {bubbles: true}));
    };

    /**
     * Choose a template. Choosing the one already chosen just closes the list.
     *
     * @param {string|number} id
     */
    const selectTemplate = (id) => {
        const strId = String(id);
        const currentId = state.selectedTemplateId !== null && state.selectedTemplateId !== undefined
            ? String(state.selectedTemplateId) : null;
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
        document.getElementById('tplPromptInput')?.focus();
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
     * Close every template popover and reset its trigger.
     */
    const closeTemplatePopovers = () => {
        document.querySelectorAll('.popover-panel[id^="templatesPopover"].open').forEach((panel) => {
            panel.classList.remove('open');
        });
        document.querySelectorAll('[aria-controls^="templatesPopover"]').forEach((btn) => {
            btn.setAttribute('aria-expanded', 'false');
        });
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
