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
 * Guideline popover and list handlers for the context section.
 *
 * @module     local_coursegen/local/courseai/context/guideline
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {escapeHtml} from 'local_coursegen/local/courseai/utils';

/**
 * Create guideline interaction handlers.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {Object} params.texts
 * @param {Function} params.refreshGuidelineChip
 * @param {Function} params.refreshChipsRow
 * @param {Function} params.refreshCompactChipsRow
 * @returns {{
 *   renderGuidelineList: Function,
 *   renderCompactGuidelineList: Function,
 *   showGuidelinePreview: Function,
 *   selectGuideline: Function
 * }}
 */
export const createGuidelineHandlers = (
    {state, elements, texts, refreshGuidelineChip, refreshChipsRow, refreshCompactChipsRow}
) => {
    const {guidelineList} = elements;

    /**
     * Show the guideline preview modal for a given guideline id.
     *
     * @param {string} id
     * @returns {void}
     */
    const showGuidelinePreview = (id) => {
        const guideline = state.guidelines.find((g) => g.id === id);
        if (!guideline) {
            return;
        }

        const modalLabel = document.getElementById('previewModalLabel');
        const modalCategory = document.getElementById('previewModalCategory');
        const modalBody = document.getElementById('previewModalBody');

        if (modalLabel) {
            modalLabel.textContent = guideline.name;
        }
        if (modalCategory) {
            modalCategory.textContent = guideline.category || texts.courseai_category_general;
        }
        if (modalBody) {
            modalBody.textContent = guideline.description || '';
        }

        if (window.$ && window.$('#guidelinePreviewModal').length) {
            window.$('#guidelinePreviewModal').modal('show');
        }
    };

    /**
     * Render the main guideline list inside the popover.
     *
     * @returns {void}
     */
    const renderGuidelineList = () => {
        if (!guidelineList) {
            return;
        }

        const query = state.guidelineSearchQuery.toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            g.name.toLowerCase().includes(query) ||
            (g.category && g.category.toLowerCase().includes(query))
        );

        if (filtered.length === 0) {
            guidelineList.innerHTML = `<li class="pop-empty">${escapeHtml(texts.courseai_no_results)}</li>`;
            return;
        }

        guidelineList.innerHTML = filtered.map((g) => {
            const isSelected = state.selectedGuidelineId === g.id;
            return `
                <li class="pop-item${isSelected ? ' selected' : ''}" data-id="${g.id}">
                    <button class="pop-select-btn" data-select="${g.id}" type="button">
                        <div class="pop-radio"><div class="pop-dot"></div></div>
                        <div class="pop-item-text">
                            <span class="pop-item-name">${escapeHtml(g.name)}</span>
                            <span class="pop-item-cat">${escapeHtml(g.category || texts.courseai_category_general)}</span>
                        </div>
                    </button>
                    <button
                    class="pop-eye-btn"
                    data-preview="${g.id}" type="button" title="${escapeHtml(texts.courseai_chip_view_guideline)}">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </li>
            `;
        }).join('');

        guidelineList.querySelectorAll('.pop-select-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-select');
                selectGuideline(id);
            });
        });

        guidelineList.querySelectorAll('.pop-eye-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = btn.getAttribute('data-preview');
                showGuidelinePreview(id);
            });
        });
    };

    /**
     * Select or deselect a guideline by id.
     *
     * @param {string} id
     * @returns {void}
     */
    const selectGuideline = (id) => {
        if (state.selectedGuidelineId === id) {
            state.selectedGuidelineId = null;
        } else {
            state.selectedGuidelineId = id;
        }
        refreshGuidelineChip();
        renderGuidelineList();
    };

    /**
     * Render the compact toolbar guideline list.
     *
     * @returns {void}
     */
    const renderCompactGuidelineList = () => {
        const compactGuidelineList = document.getElementById('guidelineListCompact');
        if (!compactGuidelineList) {
            return;
        }
        const query = (state.guidelineSearchQuery || '').toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            !query || (g.name || '').toLowerCase().includes(query)
        );
        compactGuidelineList.innerHTML = filtered.map((g) =>
            `<li class="pop-item${g.id === state.selectedGuidelineId ? ' active' : ''}"
                 role="option" data-id="${g.id}" tabindex="-1">
               <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                 <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                 <polyline points="9 12 11 14 15 10"/>
               </svg>
               <span class="pop-item-name">${g.name}</span>
               ${g.id === state.selectedGuidelineId ? '<span class="pop-item-check">✓</span>' : ''}
             </li>`
        ).join('');

        // Suppress unused-variable lint: refreshChipsRow and refreshCompactChipsRow
        // are available for callers that use this factory in different contexts.
        void refreshChipsRow;
        void refreshCompactChipsRow;
    };

    return {renderGuidelineList, renderCompactGuidelineList, showGuidelinePreview, selectGuideline};
};
