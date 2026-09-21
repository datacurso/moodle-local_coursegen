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
 * The list of templates the picker's search filters and the keyboard moves
 * through. Rendering and keyboard navigation only - choosing a row calls
 * back into whoever owns what "choosing a template" actually does.
 *
 * @module     local_coursegen/local/courseai/context/template_list
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {escapeHtml} from 'local_coursegen/local/courseai/utils';

/** The list a template is picked from: its <ul> and its search box. */
const LISTS = [
    {list: 'templateListTpl', search: 'templateSearchTpl'},
];

/**
 * Create the template list's rendering and keyboard-navigation handlers.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.texts
 * @param {Function} params.onSelect Called with a template id when a row is chosen.
 * @returns {{
 *   renderTemplateLists: Function,
 *   moveActive: Function,
 *   pickActive: Function,
 *   resetActive: Function
 * }}
 */
export const createTemplateList = ({state, texts, onSelect}) => {
    /** Index of the keyboard-highlighted row within the currently filtered list. */
    let activeIndex = -1;

    /**
     * Forget which row the keyboard was on. Called whenever the list is
     * about to be shown fresh, so a reopened list never starts already
     * highlighting a row from a previous search.
     */
    const resetActive = () => {
        activeIndex = -1;
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
        let base = activeIndex;
        if (base < 0) {
            base = -1;
        }
        activeIndex = (base + delta + filtered.length) % filtered.length;
        renderTemplateLists();
    };

    /**
     * Choose whichever row the keyboard is currently on.
     */
    const pickActive = () => {
        const filtered = getFilteredTemplates();
        const template = filtered[activeIndex] || filtered[0];
        if (template) {
            onSelect(template.id);
        }
    };

    /**
     * One template's row markup for the combo list.
     *
     * @param {Object} t
     * @param {number} index
     * @returns {string}
     */
    const templateComboRowHtml = (t, index) => {
        const isSelected = String(state.selectedTemplateId) === String(t.id);
        const isActive = index === activeIndex;
        let rowClass = 'tpl-combo-item';
        if (isSelected) {
            rowClass += ' selected';
        }
        if (isActive) {
            rowClass += ' is-active';
        }
        return `
                    <li class="${rowClass}"
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
    };

    /**
     * Wire every row's click in one rendered combo list.
     *
     * @param {HTMLElement} el
     */
    const wireTemplateComboRows = (el) => {
        el.querySelectorAll('.tpl-combo-item[data-select]').forEach((row) => {
            row.addEventListener('click', () => onSelect(row.getAttribute('data-select')));
        });
    };

    /**
     * Render one template combo list element with the filtered templates.
     *
     * @param {Array} filtered
     * @param {string} listId
     */
    const renderOneTemplateList = (filtered, listId) => {
        const el = document.getElementById(listId);
        if (!el) {
            return;
        }
        if (filtered.length === 0) {
            el.innerHTML = `<li class="tpl-combo-empty">${escapeHtml(texts.courseai_no_results || '')}</li>`;
            return;
        }
        el.innerHTML = filtered.map(templateComboRowHtml).join('');
        wireTemplateComboRows(el);
    };

    /**
     * Render every template list that exists on the page: one line per
     * template, the chosen one carrying a check instead of a radio.
     */
    const renderTemplateLists = () => {
        const filtered = getFilteredTemplates();
        LISTS.forEach(({list}) => renderOneTemplateList(filtered, list));
    };

    return {renderTemplateLists, moveActive, pickActive, resetActive};
};
