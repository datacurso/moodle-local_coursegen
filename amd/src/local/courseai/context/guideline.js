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
 * All markup lives in Mustache templates under templates/local/courseai/;
 * this module only prepares the template context and injects the result.
 *
 * @module     local_coursegen/local/courseai/context/guideline
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import Templates from 'core/templates';

/**
 * Template names rendered by this module.
 *
 * @type {{preview: string, list: string, compactList: string}}
 */
const TEMPLATES = {
    preview: 'local_coursegen/local/courseai/guideline_preview',
    list: 'local_coursegen/local/courseai/guideline_list',
    compactList: 'local_coursegen/local/courseai/guideline_list_compact',
};

/**
 * Delegated click targets inside the main guideline list.
 *
 * @type {{selectButton: string, previewButton: string}}
 */
const SELECTORS = {
    selectButton: '[data-select]',
    previewButton: '[data-preview]',
};

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

    // Render sequence counters: a render that finishes after a newer one was
    // requested (e.g. while the user types in the search box) is dropped.
    let listRenderSeq = 0;
    let compactRenderSeq = 0;

    /**
     * Show the guideline preview modal for a given guideline id.
     *
     * Uses core/modal so the dialogue works on both Moodle 4.5 (Bootstrap 4) and
     * Moodle 5.0 (Bootstrap 5) without touching the jQuery Bootstrap plugin.
     * The title is passed through the modal template context so core/modal
     * escapes it the same way it escapes its own titles.
     *
     * @param {string} id
     * @returns {Promise<void>}
     */
    const showGuidelinePreview = async(id) => {
        const guideline = state.guidelines.find((g) => g.id === id);
        if (!guideline) {
            return;
        }

        const body = Templates.render(TEMPLATES.preview, {
            category: guideline.category || texts.courseai_category_general,
            fullcontexttext: texts.courseai_modal_fullcontext,
            description: guideline.description || '',
        });

        try {
            await Modal.create({
                body,
                show: true,
                removeOnClose: true,
                templateContext: {
                    classes: 'local-coursegen-guideline-preview',
                    title: guideline.name,
                },
            });
        } catch (error) {
            Notification.exception(error);
        }
    };

    /**
     * Render the main guideline list inside the popover.
     *
     * @returns {Promise<void>}
     */
    const renderGuidelineList = async() => {
        if (!guidelineList) {
            return;
        }

        const seq = ++listRenderSeq;
        const query = state.guidelineSearchQuery.toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            g.name.toLowerCase().includes(query) ||
            (g.category && g.category.toLowerCase().includes(query))
        );

        const context = {
            hasitems: filtered.length > 0,
            items: filtered.map((g) => ({
                id: g.id,
                name: g.name,
                category: g.category || texts.courseai_category_general,
                selected: state.selectedGuidelineId === g.id,
            })),
            viewtitle: texts.courseai_chip_view_guideline,
            emptytext: texts.courseai_no_results,
        };

        try {
            const {html, js} = await Templates.renderForPromise(TEMPLATES.list, context);
            if (seq !== listRenderSeq) {
                return;
            }
            Templates.replaceNodeContents(guidelineList, html, js);
        } catch (error) {
            Notification.exception(error);
        }
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
     * @returns {Promise<void>}
     */
    const renderCompactGuidelineList = async() => {
        const compactGuidelineList = document.getElementById('guidelineListCompact');
        if (!compactGuidelineList) {
            return;
        }

        const seq = ++compactRenderSeq;
        const query = (state.guidelineSearchQuery || '').toLowerCase();
        const filtered = state.guidelines.filter((g) =>
            !query || (g.name || '').toLowerCase().includes(query)
        );

        const context = {
            items: filtered.map((g) => ({
                id: g.id,
                name: g.name,
                selected: g.id === state.selectedGuidelineId,
            })),
        };

        try {
            const {html, js} = await Templates.renderForPromise(TEMPLATES.compactList, context);
            if (seq !== compactRenderSeq) {
                return;
            }
            Templates.replaceNodeContents(compactGuidelineList, html, js);
        } catch (error) {
            Notification.exception(error);
        }

        // Suppress unused-variable lint: refreshChipsRow and refreshCompactChipsRow
        // are available for callers that use this factory in different contexts.
        void refreshChipsRow;
        void refreshCompactChipsRow;
    };

    // Delegate clicks on the list container once, so re-rendering the items
    // (which happens asynchronously) never needs to re-bind per-item handlers.
    if (guidelineList) {
        guidelineList.addEventListener('click', (e) => {
            const previewBtn = e.target.closest(SELECTORS.previewButton);
            if (previewBtn && guidelineList.contains(previewBtn)) {
                e.stopPropagation();
                showGuidelinePreview(previewBtn.getAttribute('data-preview'));
                return;
            }

            const selectBtn = e.target.closest(SELECTORS.selectButton);
            if (selectBtn && guidelineList.contains(selectBtn)) {
                selectGuideline(selectBtn.getAttribute('data-select'));
            }
        });
    }

    return {renderGuidelineList, renderCompactGuidelineList, showGuidelinePreview, selectGuideline};
};
