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
 * Compact toolbar mirroring controls for the Course AI context section.
 *
 * @module     local_coursegen/local/courseai/context/compact
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wire the compact chat toolbar controls so they remain functional in phases 2 and 3.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {HTMLElement|null} params.langSelect
 * @param {HTMLElement|null} params.btnWithImages
 * @param {HTMLElement|null} params.imgToggleWrap
 * @param {Function} params.showFilePicker
 * @param {Function} params.renderCompactGuidelineList
 * @param {Function} params.showGuidelinePreview
 * @param {Function} params.refreshGuidelineChip
 * @param {Function} params.refreshChipsRow
 * @param {Function} params.refreshCompactChipsRow
 * @param {Function} params.bindToggleWrap
 * @returns {void}
 */
export const wireCompactControls = ({
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
}) => {
    const btnCompactSyllabus = document.getElementById('btnCompactSyllabus');
    if (btnCompactSyllabus) {
        btnCompactSyllabus.addEventListener('click', async() => {
            await showFilePicker();
        });
    }

    const btnCompactDirectrices = document.getElementById('btnCompactDirectrices');
    const compactGuidelinesPopover = document.getElementById('guidelinesPopoverCompact');
    const compactGuidelineSearch = document.getElementById('guidelineSearchCompact');
    const compactGuidelineList = document.getElementById('guidelineListCompact');

    if (btnCompactDirectrices && compactGuidelinesPopover) {
        btnCompactDirectrices.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = !compactGuidelinesPopover.classList.contains('open');
            state.guidelinePopoverOpen = willOpen;
            compactGuidelinesPopover.classList.toggle('open', willOpen);
            btnCompactDirectrices.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen && compactGuidelineSearch) {
                compactGuidelineSearch.value = '';
                state.guidelineSearchQuery = '';
                renderCompactGuidelineList();
                compactGuidelineSearch.focus();
            }
        });
    }

    if (compactGuidelineSearch) {
        compactGuidelineSearch.addEventListener('input', () => {
            state.guidelineSearchQuery = compactGuidelineSearch.value;
            renderCompactGuidelineList();
        });
    }

    if (compactGuidelineList) {
        compactGuidelineList.addEventListener('click', (e) => {
            const item = e.target.closest('.pop-item');
            if (!item) {
                return;
            }
            const id = item.getAttribute('data-id');
            // Close the compact popover
            compactGuidelinesPopover.classList.remove('open');
            state.guidelinePopoverOpen = false;
            // Handle selection via the same shared state
            const guideline = state.guidelines.find((g) => g.id === id);
            if (guideline) {
                state.selectedGuidelineId = id;
                state.selectedGuidelineName = guideline.name;
                refreshGuidelineChip();
                refreshChipsRow();
            }
        });
    }

    // Close compact popover on outside click. Guard on THIS panel's own .open class
    // (not the shared state flag) so it never clobbers the main popover's state.
    if (document.body && compactGuidelinesPopover) {
        document.body.addEventListener('click', (e) => {
            if (!compactGuidelinesPopover.classList.contains('open')) {
                return;
            }
            if (
                !compactGuidelinesPopover.contains(e.target) &&
                e.target !== btnCompactDirectrices &&
                !btnCompactDirectrices?.contains(e.target)
            ) {
                compactGuidelinesPopover.classList.remove('open');
                state.guidelinePopoverOpen = false;
                if (btnCompactDirectrices) {
                    btnCompactDirectrices.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Close on Escape and return focus to the trigger (accessibility).
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && compactGuidelinesPopover.classList.contains('open')) {
                compactGuidelinesPopover.classList.remove('open');
                state.guidelinePopoverOpen = false;
                if (btnCompactDirectrices) {
                    btnCompactDirectrices.setAttribute('aria-expanded', 'false');
                    btnCompactDirectrices.focus();
                }
            }
        });
    }

    const compactLangSelectEl = document.getElementById('compactLangSelect');
    if (compactLangSelectEl) {
        compactLangSelectEl.addEventListener('change', () => {
            state.lang = compactLangSelectEl.value;
            // Keep main in sync
            if (langSelect) {
                langSelect.value = compactLangSelectEl.value;
            }
        });
    }

    if (elements.btnCompactWithImages) {
        bindToggleWrap(elements.compactImgToggleWrap, elements.btnCompactWithImages);
        elements.btnCompactWithImages.addEventListener('change', () => {
            state.withImages = elements.btnCompactWithImages.checked;
            if (elements.compactImgToggleWrap) {
                elements.compactImgToggleWrap.classList.toggle('on', state.withImages);
            }
            // Keep main in sync
            if (btnWithImages) {
                btnWithImages.checked = state.withImages;
            }
            if (imgToggleWrap) {
                imgToggleWrap.classList.toggle('on', state.withImages);
            }
        });
    }

    // Compact guideline eye button: preview the currently selected guideline
    const compactChipGuidelineEyeBtn = document.getElementById('compactChipGuidelineEyeBtn');
    if (compactChipGuidelineEyeBtn) {
        compactChipGuidelineEyeBtn.addEventListener('click', () => {
            if (state.selectedGuidelineId) {
                showGuidelinePreview(state.selectedGuidelineId);
            }
        });
    }

    // Suppress unused-variable lint: refreshCompactChipsRow is available for callers.
    void refreshCompactChipsRow;
};
