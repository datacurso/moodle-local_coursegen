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
 * showReviewActions helper — finalises the planning stream UI and reveals
 * the decision overlay (WU4: the center #planActions is suppressed in favour
 * of the left-panel overlay).
 *
 * @module     local_coursegen/local/courseai/planning/review-actions
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';

/**
 * Transition the planning panel to "review ready" state.
 *
 * ctx must contain:
 *   elements, texts, setProgress,
 *   setCompactChatState, deps, syncCompactChatState
 *
 * @param {string} mode - 'detailed' | anything else (markdown/sections)
 * @param {Object} ctx
 * @returns {void}
 */
export const showReviewActions = (mode, ctx) => {
    const {elements, texts, setProgress, setCompactChatState, deps, syncCompactChatState} = ctx;

    const {
        planningSpinner,
        typingCursor,
        planningCheckIcon,
        pcIconWrap,
        prvSpinnerIcon,
        prvCheckIcon,
        prvHeader,
        prvHeaderSub,
        prvLiveNote,
        planActionsHint,
        planReviewCard,
        planActions,
        pcStep,
        pcTitle,
        pcSubtitle,
        pcToggleRow,
    } = elements;

    if (planningSpinner) {
        planningSpinner.classList.add('done');
    }
    if (typingCursor) {
        typingCursor.classList.add('hidden');
    }
    setProgress(100);

    // Enable compact chat and show courseai cancel button when review is ready
    setCompactChatState(deps, 'enabled');
    // Sync state from main chat to compact chat (language, chips, etc.)
    syncCompactChatState();

    const courseaiCancelRow = document.getElementById('courseaiCancelRow');
    if (courseaiCancelRow) {
        courseaiCancelRow.style.display = 'flex';
    }

    if (mode === 'detailed') {
        if (planningSpinner) {
            planningSpinner.style.display = 'none';
        }
        if (planningCheckIcon) {
            planningCheckIcon.style.display = '';
        }
        if (pcIconWrap) {
            pcIconWrap.style.background = '#16a34a';
            pcIconWrap.style.color = '#fff';
        }
        if (prvSpinnerIcon) {
            prvSpinnerIcon.style.display = 'none';
        }
        if (prvCheckIcon) {
            prvCheckIcon.style.display = '';
        }
        if (prvHeader) {
            prvHeader.classList.remove('prv-header--stream');
            prvHeader.classList.add('prv-header--done');
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = texts.courseai_plan_detailed_done_subtitle;
        }
        if (prvLiveNote) {
            prvLiveNote.style.display = 'none';
            prvLiveNote.textContent = '';
        }
        if (planActionsHint) {
            planActionsHint.textContent = texts.courseai_plan_review_hint_detailed;
        }
        if (planReviewCard) {
            planReviewCard.style.display = '';
        }
    } else {
        if (pcStep) {
            pcStep.textContent = texts.courseai_plan_detailed_markdown_title;
        }
        if (pcTitle) {
            pcTitle.textContent = texts.courseai_plan_detailed_markdown_title;
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = texts.courseai_plan_detailed_markdown_subtitle;
        }
        if (planActionsHint) {
            planActionsHint.textContent = texts.courseai_plan_review_hint_detailed;
        }
        if (pcToggleRow) {
            pcToggleRow.style.display = 'flex';
        }
    }

    // WU4: Keep #planActions hidden — the decision overlay covers the left panel
    // instead. #btnApprove stays in the DOM (actions.js wires its click handler)
    // and the overlay Accept button delegates to it via click().
    if (planActions) {
        planActions.style.display = 'none';
    }

    // Wire overlay Accept → btnApprove.click() and Adjust → hide overlay + show composer.
    const overlay = getDecisionOverlay(texts);
    const acceptBtn = document.getElementById('cgDecisionAccept');
    const adjustBtn = document.getElementById('cgDecisionAdjust');
    const {btnApprove, compactPromptInput} = elements;

    if (acceptBtn && !acceptBtn.dataset.cgWired) {
        acceptBtn.dataset.cgWired = '1';
        acceptBtn.addEventListener('click', () => {
            if (btnApprove) {
                btnApprove.click();
            }
        });
    }

    if (adjustBtn && !adjustBtn.dataset.cgWired) {
        adjustBtn.dataset.cgWired = '1';
        adjustBtn.addEventListener('click', () => {
            overlay.hide();
            setCompactChatState(deps, 'enabled');
            if (compactPromptInput) {
                compactPromptInput.focus();
            }
        });
    }

    overlay.show();
};
