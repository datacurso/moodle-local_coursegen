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
 * Compact-chat visibility and control-state helper.
 *
 * @module     local_coursegen/local/courseai/planning/compact-chat
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Set compact chat visibility and control state.
 *
 * @param {Object} deps - Dependencies including state, elements, texts
 * @param {string} mode - 'hidden' | 'disabled' | 'enabled' | 'reset'
 */
export const setCompactChatState = (deps, mode) => {
    const {
        state,
        elements,
        texts,
    } = deps;

    const {
        compactChatCard,
        compactPromptInput,
        compactChipsRow,
        compactToolbarLeft,
        btnCompactRegenerate,
        compactLangSelect,
        btnCompactWithImages,
        btnCompactSyllabus,
        btnCompactDirectrices,
    } = elements;

    if (!compactChatCard) {
        return;
    }

    // Once the plan is approved the course is created and can no longer be edited
    // from this wizard, so the composer is gone for good — through generation and
    // after it completes. Every later state change (disabled/enabled/reset from the
    // generation stream, completion, failure or reload) collapses to 'hidden'.
    if (state && state.planApproved) {
        mode = 'hidden';
    }

    const upArrowIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M12 19V5M5 12l7-7 7 7"/></svg>';

    // The inline "still accept" bar is only meaningful right after Adjust; any other
    // composer state change clears it (the Adjust handler re-shows it after 'enabled').
    const acceptBar = document.getElementById('cgAcceptBar');
    if (acceptBar) {
        acceptBar.style.display = 'none';
    }

    switch (mode) {
        case 'hidden':
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
            }
            break;

        case 'disabled':
            compactChatCard.style.display = 'block';
            // NOTE: the card-level `compact-chat-card--disabled` (global opacity +
            // pointer-events:none) is intentionally NOT applied — it would also block
            // the Stop button living in the toolbar. Each control is disabled
            // individually below instead, leaving Stop/Resume usable.
            if (compactPromptInput) {
                compactPromptInput.classList.add('compact-controls--disabled');
                compactPromptInput.disabled = true;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.add('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.add('compact-controls--disabled');
            }
            // Disable form controls and toolbar buttons — keyboard + mouse
            if (compactLangSelect) {
                compactLangSelect.disabled = true;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = true;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = true;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = true;
            }
            // Disable Regenerar — actions.js re-enables it and switches label to Pausar
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = true;
            }
            if (state) {
                state.isStreaming = true;
            }
            break;

        case 'enabled': {
            // While the decision card owns the bottom slot (review state), keep the
            // composer hidden so the two input boxes never stack. Any caller that
            // requests 'enabled' during review (a buffered stream 'done', etc.) still
            // re-enables the controls, but the field stays hidden. It reappears when
            // "Adjust" hides the card first (the adjust handler hides the overlay
            // before calling 'enabled').
            const decisionOverlay = document.getElementById('cgDecisionOverlay');
            const decisionVisible = decisionOverlay
                && window.getComputedStyle(decisionOverlay).display !== 'none';
            compactChatCard.style.display = decisionVisible ? 'none' : 'block';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = upArrowIcon;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;
        }

        case 'reset':
        default:
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = upArrowIcon;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;
    }
};

/**
 * Wire a single input listener on #compactPromptInput that toggles the
 * `.is-ready` class on #btnCompactRegenerate when the textarea has non-empty
 * trimmed text. The button is accent-filled when ready. Safe to call multiple
 * times — guards against duplicate listeners with a dataset flag.
 *
 * @param {Object} elements - The courseai elements object (must include compactPromptInput,
 *                            btnCompactRegenerate).
 * @returns {void}
 */
export const wireReadyToggle = (elements) => {
    const {compactPromptInput, btnCompactRegenerate} = elements;
    if (!compactPromptInput || !btnCompactRegenerate) {
        return;
    }
    if (compactPromptInput.dataset.cgReadyWired) {
        return;
    }
    compactPromptInput.dataset.cgReadyWired = '1';

    /**
     * Sync the is-ready class on the send button based on textarea content.
     *
     * @returns {void}
     */
    const sync = () => {
        const hasText = compactPromptInput.value.trim().length > 0;
        btnCompactRegenerate.classList.toggle('is-ready', hasText);
    };

    compactPromptInput.addEventListener('input', sync);

    // Enter sends the free-text feedback (Shift+Enter inserts a newline), like any
    // modern chat composer. Ignore when empty or while the send button is disabled
    // (a stream is in flight).
    compactPromptInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) {
            return;
        }
        event.preventDefault();
        if (btnCompactRegenerate.disabled || !compactPromptInput.value.trim()) {
            return;
        }
        btnCompactRegenerate.click();
    });

    sync();
};
