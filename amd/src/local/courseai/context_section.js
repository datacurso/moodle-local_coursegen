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
import {wireGuidelinePopover} from 'local_coursegen/local/courseai/context/guideline_popover';
import {wireTemplatePopover} from 'local_coursegen/local/courseai/context/template_popover';
import {wireCompactControls} from 'local_coursegen/local/courseai/context/compact';
import {wireMirroredToggle} from 'local_coursegen/local/courseai/context/mirrored_toggle';
import {bindToggleWrap, showFilePicker as openFilePicker} from 'local_coursegen/local/courseai/context/filepicker';
import {wirePlusMenu} from 'local_coursegen/local/courseai/context/plus-menu';
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
        let display = 'none';
        if (hasSyllabus || hasGuideline) {
            display = 'flex';
        }
        compactChipsRow.style.display = display;
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
        let display = 'none';
        if (hasSyllabus || hasGuideline) {
            display = 'flex';
        }
        chipsRow.style.display = display;
    };

    const closeGuidelinePopover = ({returnFocus = false} = {}) => {
        state.guidelinePopoverOpen = false;
        if (guidelinesPopover) {
            guidelinesPopover.classList.remove('open');
        }
        if (btnDirectrices) {
            btnDirectrices.setAttribute('aria-expanded', 'false');
            if (returnFocus) {
                btnDirectrices.focus();
            }
        }
    };

    const refreshGuidelineChip = () => doRefreshGuidelineChip({state, refreshChipsRow, refreshCompactChipsRow});

    // showFilePicker bound with the deps it needs
    const showFilePicker = () => openFilePicker({
        state, CourseaiRepository, Notification, YUI, texts, refreshChipsRow, refreshCompactChipsRow,
    });

    // ─── Lang selects ────────────────────────────────────────────────────────

    const langOptionHtml = (lang) => {
        let selected = '';
        if (lang.code === defaultLang) {
            selected = 'selected';
        }
        return `<option value="${lang.code}" ${selected}>🌐 ${lang.code.toUpperCase()}</option>`;
    };

    let optionsHtml = null;
    if (languages.length > 0) {
        optionsHtml = languages.map(langOptionHtml).join('');
    }

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

    wireGuidelinePopover({
        state, btnDirectrices, guidelinesPopover, guidelineSearch, closeGuidelinePopover, renderGuidelineList,
    });

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

    wireMirroredToggle({
        toggle: btnWithImages,
        wrap: imgToggleWrap,
        bindToggleWrap,
        compactToggle: elements.btnCompactWithImages,
        compactWrap: elements.compactImgToggleWrap,
        onChange: (checked) => {
            state.withImages = checked;
        },
    });

    // "+" options menu: presentation layer over the controls wired above.
    // Wired AFTER the language options are populated so the flyout lists them.
    // Opening the menu closes the guidelines popover (and vice versa via the
    // menu item), so both panels never overlap around the same anchor.
    wirePlusMenu({
        button: elements.btnPlusMenu,
        panel: elements.plusMenuPanel,
        langItem: elements.pmLangItem,
        langValue: elements.pmLangValue,
        langPopover: elements.langPopover,
        langSearch: elements.langSearch,
        langList: elements.langList,
        langCloseBtn: elements.langPopoverClose,
        langSelect,
        languages: state.languages || languages || [],
        onOpen: closeGuidelinePopover,
    });

    // ─── Templates: the list the template column picks from ─────────────────
    const {
        selectTemplate, detachTemplate, setTemplateLayout, openTemplatePopover, closeTemplatePopovers,
    } = wireTemplatePopover({state, texts, closeGuidelinePopover});

    wireMirroredToggle({
        toggle: btnWithSubsections,
        wrap: subToggleWrap,
        bindToggleWrap,
        compactToggle: elements.btnCompactWithSubsections,
        compactWrap: elements.compactSubToggleWrap,
        onChange: (checked) => {
            state.withSubsections = checked;
        },
    });

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
        selectTemplate,
        detachTemplate,
        setTemplateLayout,
        openTemplatePopover,
        closeTemplatePopovers,
    };
};
