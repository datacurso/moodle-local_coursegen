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
 *   isLocked: Function
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
     * Render every template list that exists on the page.
     */
    const renderTemplateLists = () => {
        const query = (state.templateSearchQuery || '').toLowerCase();
        const filtered = (state.templates || []).filter((t) =>
            !query ||
            (t.name || '').toLowerCase().includes(query) ||
            (t.coursefullname || '').toLowerCase().includes(query)
        );
        LISTS.forEach(({list}) => {
            const el = document.getElementById(list);
            if (!el) {
                return;
            }
            if (filtered.length === 0) {
                el.innerHTML = `<li class="pop-empty">${escapeHtml(texts.courseai_no_results || '')}</li>`;
                return;
            }
            el.innerHTML = filtered.map((t) => {
                const isSelected = String(state.selectedTemplateId) === String(t.id);
                return `
                    <li class="pop-item${isSelected ? ' selected' : ''}" data-id="${t.id}">
                        <button class="pop-select-btn" data-select="${t.id}" type="button"
                                role="option" aria-selected="${isSelected}">
                            <div class="pop-radio"><div class="pop-dot"></div></div>
                            <div class="pop-item-text">
                                <span class="pop-item-name">${escapeHtml(t.name)}</span>
                                <span class="pop-item-cat">${escapeHtml(t.coursefullname || '')}</span>
                            </div>
                        </button>
                    </li>
                `;
            }).join('');
            el.querySelectorAll('.pop-select-btn').forEach((btn) => {
                btn.addEventListener('click', () => selectTemplate(btn.getAttribute('data-select')));
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
    };
};
