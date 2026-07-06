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
 * Context step controls for the Course AI page.
 *
 * @module     local_coursegen/local/courseai/context_section
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createGuidelineHandlers} from 'local_coursegen/local/courseai/context/guideline';
import {wireCompactControls} from 'local_coursegen/local/courseai/context/compact';
import {bindToggleWrap, showFilePicker as openFilePicker} from 'local_coursegen/local/courseai/context/filepicker';
import {refreshGuidelineChip as doRefreshGuidelineChip} from 'local_coursegen/local/courseai/context/chip';

/**
 * Setup context step interactions.
 *
 * @param {Object} deps
 * @returns {{
 *   updateGenerateButton: Function,
 *   refreshGuidelineChip: Function,
 *   refreshChipsRow: Function,
 *   renderGuidelineList: Function
 * }}
 */
export const setupContextSection = (deps) => {
    const {
        state,
        languages,
        defaultLang,
        elements,
        Notification,
        CourseaiRepository,
        YUI,
        texts,
    } = deps;

    const {
        promptInput,
        btnGenerate,
        btnSyllabus,
        btnDirectrices,
        guidelinesPopover,
        guidelineSearch,
        langSelect,
        btnWithImages,
        imgToggleWrap,
        btnWithSubsections,
        subToggleWrap,
    } = elements;

    // ─── Core helpers ────────────────────────────────────────────────────────

    const updateGenerateButton = () => {
        if (btnGenerate && promptInput) {
            btnGenerate.disabled = promptInput.value.trim().length < 10;
        }
    };

    const refreshCompactChipsRow = () => {
        const compactChipsRow = document.getElementById('compactChipsRow');
        const compactChipSyllabus = document.getElementById('compactChipSyllabus');
        const compactChipGuideline = document.getElementById('compactChipGuideline');
        if (!compactChipsRow) {
            return;
        }
        const hasSyllabus = compactChipSyllabus && !compactChipSyllabus.classList.contains('hidden');
        const hasGuideline = compactChipGuideline && !compactChipGuideline.classList.contains('hidden');
        compactChipsRow.style.display = (hasSyllabus || hasGuideline) ? 'flex' : 'none';
    };

    const refreshChipsRow = () => {
        const chipsRow = document.getElementById('chipsRow');
        const chipSyllabus = document.getElementById('chipSyllabus');
        const chipGuideline = document.getElementById('chipGuideline');

        if (!chipsRow) {
            return;
        }

        const hasSyllabus = chipSyllabus && !chipSyllabus.classList.contains('hidden');
        const hasGuideline = chipGuideline && !chipGuideline.classList.contains('hidden');
        chipsRow.style.display = (hasSyllabus || hasGuideline) ? 'flex' : 'none';
    };

    const closeGuidelinePopover = () => {
        state.guidelinePopoverOpen = false;
        if (guidelinesPopover) {
            guidelinesPopover.classList.remove('open');
        }
    };

    const refreshGuidelineChip = () => doRefreshGuidelineChip({state, refreshChipsRow, refreshCompactChipsRow});

    // showFilePicker bound with the deps it needs
    const showFilePicker = () => openFilePicker({
        state, CourseaiRepository, Notification, YUI, texts, refreshChipsRow, refreshCompactChipsRow,
    });

    // ─── Lang selects ────────────────────────────────────────────────────────

    const optionsHtml = languages.length > 0
        ? languages.map((lang) =>
            `<option value="${lang.code}" ${lang.code === defaultLang ? 'selected' : ''}>🌐 ${lang.code.toUpperCase()}</option>`
        ).join('')
        : null;

    if (optionsHtml) {
        if (langSelect) {
            langSelect.innerHTML = optionsHtml;
        }
        const compactLangSelect = document.getElementById('compactLangSelect');
        if (compactLangSelect) {
            compactLangSelect.innerHTML = optionsHtml;
        }
    }

    // ─── Guideline handlers ───────────────────────────────────────────────────

    const {renderGuidelineList, renderCompactGuidelineList, showGuidelinePreview, selectGuideline} =
        createGuidelineHandlers({state, elements, texts, refreshGuidelineChip, refreshChipsRow, refreshCompactChipsRow});

    // ─── Main context controls ────────────────────────────────────────────────

    if (btnDirectrices && guidelinesPopover) {
        btnDirectrices.addEventListener('click', (e) => {
            e.stopPropagation();
            state.guidelinePopoverOpen = !state.guidelinePopoverOpen;
            guidelinesPopover.classList.toggle('open', state.guidelinePopoverOpen);

            if (state.guidelinePopoverOpen && guidelineSearch) {
                guidelineSearch.value = '';
                state.guidelineSearchQuery = '';
                renderGuidelineList();
                guidelineSearch.focus();
            }
        });
    }

    document.addEventListener('click', (e) => {
        if (state.guidelinePopoverOpen &&
            guidelinesPopover &&
            !guidelinesPopover.contains(e.target) &&
            e.target !== btnDirectrices) {
            closeGuidelinePopover();
        }
    });

    if (guidelineSearch) {
        guidelineSearch.addEventListener('input', () => {
            state.guidelineSearchQuery = guidelineSearch.value;
            renderGuidelineList();
        });
    }

    if (btnSyllabus) {
        btnSyllabus.addEventListener('click', async() => {
            await showFilePicker();
        });
    }

    if (langSelect) {
        langSelect.addEventListener('change', () => {
            state.lang = langSelect.value;
            const compactLangSelect = document.getElementById('compactLangSelect');
            if (compactLangSelect) {
                compactLangSelect.value = langSelect.value;
            }
        });
    }

    if (btnWithImages && imgToggleWrap) {
        bindToggleWrap(imgToggleWrap, btnWithImages);
        btnWithImages.addEventListener('change', () => {
            state.withImages = btnWithImages.checked;
            imgToggleWrap.classList.toggle('on', state.withImages);
            if (elements.btnCompactWithImages) {
                elements.btnCompactWithImages.checked = state.withImages;
            }
            if (elements.compactImgToggleWrap) {
                elements.compactImgToggleWrap.classList.toggle('on', state.withImages);
            }
        });
    }

    if (btnWithSubsections && subToggleWrap) {
        bindToggleWrap(subToggleWrap, btnWithSubsections);
        btnWithSubsections.addEventListener('change', () => {
            state.withSubsections = btnWithSubsections.checked;
            subToggleWrap.classList.toggle('on', state.withSubsections);
            if (elements.btnCompactWithSubsections) {
                elements.btnCompactWithSubsections.checked = state.withSubsections;
            }
            if (elements.compactSubToggleWrap) {
                elements.compactSubToggleWrap.classList.toggle('on', state.withSubsections);
            }
        });
    }

    // ─── Compact toolbar mirroring ────────────────────────────────────────────

    wireCompactControls({
        state,
        elements,
        langSelect,
        btnWithImages,
        imgToggleWrap,
        showFilePicker,
        renderCompactGuidelineList,
        showGuidelinePreview,
        refreshGuidelineChip,
        refreshChipsRow,
        refreshCompactChipsRow,
        bindToggleWrap,
    });

    // selectGuideline is used inside guideline.js event listeners; keep reference available.
    void selectGuideline;

    return {
        updateGenerateButton,
        refreshGuidelineChip,
        refreshChipsRow,
        renderGuidelineList,
    };
};
