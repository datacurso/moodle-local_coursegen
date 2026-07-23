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
 * Template popover and list handlers for the context section.
 *
 * @module     local_coursegen/local/courseai/context/template
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {escapeHtml} from 'local_coursegen/local/courseai/utils';

/**
 * Create template interaction handlers.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.texts
 * @param {Function} params.refreshTemplateChip
 * @param {Function} params.refreshChipsRow
 * @param {Function} params.refreshCompactChipsRow
 * @returns {{
 *   renderTemplateList: Function,
 *   renderCompactTemplateList: Function,
 *   selectTemplate: Function
 * }}
 */
export const createTemplateHandlers = (
    {state, texts, refreshTemplateChip, refreshChipsRow, refreshCompactChipsRow}
) => {
    /**
     * Render the main template list inside the popover.
     *
     * @returns {void}
     */
    const renderTemplateList = () => {
        const templateList = document.getElementById('templateList');
        if (!templateList) {
            return;
        }

        const query = (state.templateSearchQuery || '').toLowerCase();
        const filtered = (state.templates || []).filter((t) =>
            (t.name || '').toLowerCase().includes(query) ||
            (t.coursefullname || '').toLowerCase().includes(query)
        );

        if (filtered.length === 0) {
            templateList.innerHTML = `<li class="pop-empty">${escapeHtml(texts.courseai_no_results || '')}</li>`;
            return;
        }

        templateList.innerHTML = filtered.map((t) => {
            const isSelected = state.selectedTemplateId === t.id;
            return `
                <li class="pop-item${isSelected ? ' selected' : ''}" data-id="${t.id}">
                    <button class="pop-select-btn" data-select="${t.id}" type="button">
                        <div class="pop-radio"><div class="pop-dot"></div></div>
                        <div class="pop-item-text">
                            <span class="pop-item-name">${escapeHtml(t.name)}</span>
                            <span class="pop-item-cat">${escapeHtml(t.coursefullname || '')}</span>
                        </div>
                    </button>
                </li>
            `;
        }).join('');

        templateList.querySelectorAll('.pop-select-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-select');
                selectTemplate(id);
            });
        });
    };

    /**
     * Select or deselect a template by id.
     *
     * @param {string|number} id
     * @returns {void}
     */
    const selectTemplate = (id) => {
        // Convert to same type for comparison (data-select returns string).
        const strId = String(id);
        const currentId = state.selectedTemplateId !== null ? String(state.selectedTemplateId) : null;
        if (currentId === strId) {
            state.selectedTemplateId = null;
        } else {
            state.selectedTemplateId = id;
        }
        refreshTemplateChip();
        renderTemplateList();
        renderCompactTemplateList();
    };

    /**
     * Render the compact toolbar template list.
     *
     * @returns {void}
     */
    const renderCompactTemplateList = () => {
        const compactTemplateList = document.getElementById('templateListCompact');
        if (!compactTemplateList) {
            return;
        }
        const query = (state.templateSearchQuery || '').toLowerCase();
        const filtered = (state.templates || []).filter((t) =>
            !query ||
            (t.name || '').toLowerCase().includes(query) ||
            (t.coursefullname || '').toLowerCase().includes(query)
        );

        compactTemplateList.innerHTML = filtered.map((t) => {
            const isSelected = state.selectedTemplateId !== null &&
                String(t.id) === String(state.selectedTemplateId);
            return `<li class="pop-item${isSelected ? ' active' : ''}"
                 role="option" data-id="${t.id}" tabindex="-1">
               <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                 <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                 <line x1="8" y1="21" x2="16" y2="21"/>
                 <line x1="12" y1="17" x2="12" y2="21"/>
               </svg>
               <span class="pop-item-name">${escapeHtml(t.name)}</span>
               ${isSelected ? '<span class="pop-item-check">✓</span>' : ''}
             </li>`;
        }).join('');

        compactTemplateList.querySelectorAll('[data-id]').forEach((li) => {
            li.addEventListener('click', () => {
                const id = li.getAttribute('data-id');
                selectTemplate(id);
            });
        });

        // Suppress unused-variable lint: refreshChipsRow and refreshCompactChipsRow
        // are available for callers that use this factory in different contexts.
        void refreshChipsRow;
        void refreshCompactChipsRow;
    };

    return {renderTemplateList, renderCompactTemplateList, selectTemplate};
};
