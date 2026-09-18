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
 * The attached template: the lists that pick it and what picking it does.
 *
 * There is no template "mode" the professor enters. A template is attached
 * from the composer, the way a syllabus is, and attaching one is what opens
 * the template layout: the native picker's <select> (the value
 * template_mode.js listens to) is set and told it changed, the workspace
 * gets the `is-template` class, the free hero hides and the template column
 * shows, with the professor's text carried over. Detaching reverses all of it.
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

/** The lists a template can be picked from: their <ul> and their search box. */
const LISTS = [
    {list: 'templateList', search: 'templateSearch'},
    {list: 'templateListCompact', search: 'templateSearchCompact'},
    {list: 'templateListTpl', search: 'templateSearchTpl'},
];

/** The chips that show the attached template: chip element and its name span. */
const CHIPS = [
    {chip: 'chipTemplate', name: 'chipTemplateName', row: 'chipsRow'},
    {chip: 'compactChipTemplate', name: 'compactChipTemplateName', row: 'compactChipsRow'},
    {chip: 'tplChipTemplate', name: 'tplChipTemplateName', row: 'tplChipsRow'},
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
 * Show or hide a chips row depending on whether any chip in it is visible.
 *
 * @param {string} rowId
 */
const syncChipsRow = (rowId) => {
    const row = document.getElementById(rowId);
    if (!row) {
        return;
    }
    const visible = row.querySelector('.chip:not(.hidden)');
    row.style.display = visible ? 'flex' : 'none';
};

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
 *   getSelectedTemplate: Function
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
     * Show the attached template in every chip and in the template card.
     */
    const refreshTemplateChrome = () => {
        const template = getSelectedTemplate();
        CHIPS.forEach(({chip, name, row}) => {
            const chipEl = document.getElementById(chip);
            const nameEl = document.getElementById(name);
            if (nameEl) {
                nameEl.textContent = template ? template.name : '';
            }
            if (chipEl) {
                chipEl.classList.toggle('hidden', !template);
            }
            syncChipsRow(row);
        });
        const thumb = document.getElementById('tplCardThumb');
        const nameEl = document.getElementById('tplCardName');
        const courseEl = document.getElementById('tplCardCourse');
        const statsEl = document.getElementById('tplCardStats');
        if (thumb) {
            const initials = (template?.name || '').replace(/[^\p{L}\p{N}]/gu, '').slice(0, 3).toUpperCase();
            thumb.textContent = initials;
        }
        if (nameEl) {
            nameEl.textContent = template ? template.name : '';
        }
        if (courseEl) {
            courseEl.textContent = template ? (template.coursefullname || '') : '';
        }
        if (statsEl && !template) {
            statsEl.textContent = '';
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
            document.getElementById('tplPromptInput')?.focus();
        } else {
            carryPrompt('tplPromptInput', 'promptInput');
            document.getElementById('promptInput')?.focus();
        }
    };

    /**
     * Tell the native picker which template is attached; template_mode.js
     * listens to its 'change' and loads or clears the structure.
     *
     * @param {string} value
     */
    const setPickerValue = (value) => {
        const select = document.getElementById('id_templateid');
        if (!select) {
            return;
        }
        if (select.value === value) {
            return;
        }
        select.value = value;
        select.dispatchEvent(new Event('change', {bubbles: true}));
    };

    /**
     * Attach a template (or detach it, when it is the one already attached).
     *
     * @param {string|number} id
     */
    const selectTemplate = (id) => {
        const strId = String(id);
        const currentId = state.selectedTemplateId !== null && state.selectedTemplateId !== undefined
            ? String(state.selectedTemplateId) : null;
        if (currentId === strId) {
            detachTemplate();
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
    };

    /**
     * Detach the template and return to free creation.
     */
    const detachTemplate = () => {
        state.selectedTemplateId = null;
        refreshTemplateChrome();
        renderTemplateLists();
        setPickerValue('');
        setTemplateLayout(false);
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

    return {renderTemplateLists, selectTemplate, detachTemplate, getSelectedTemplate, closeTemplatePopovers};
};
