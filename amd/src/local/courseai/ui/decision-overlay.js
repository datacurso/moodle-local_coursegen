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
 * Decision overlay — a full-panel overlay that covers the left chat scroll area
 * and presents a V0-style decision card (Accept / Adjust) when review_needed fires.
 *
 * The overlay markup is rendered statically in the Mustache template
 * (#cgDecisionOverlay inside #courseaiContextChat). This module only toggles
 * visibility and returns DOM references for the body slot and action buttons.
 *
 * @module     local_coursegen/local/courseai/ui/decision-overlay
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Module-level singleton so every caller shares the same controller. */
let instance = null;

/** Pending debounced show() timer — see show()/hide(). */
let pendingShowTimer = null;

/**
 * Small delay before the review decision card actually appears. When the user
 * fires several quick adjustments in a row (e.g. drag-reordering), each action
 * returns to "review" for a beat before the next one starts; without this the
 * Accept/Adjust card would flash in and out between every action. Deferring the
 * show and cancelling it on the next hide() means the card only appears once the
 * user pauses — never as a flash mid-editing.
 */
const SHOW_DELAY_MS = 400;

/**
 * Return (or lazily create) the singleton decision-overlay controller.
 *
 * The first call resolves DOM elements by ID (they must exist in the template).
 * Subsequent calls return the cached instance without touching the DOM.
 *
 * @param {Object} [texts] - Optional i18n texts; used only on first call to set button labels.
 * @returns {{ show: Function, hide: Function, getBody: Function, isVisible: Function }}
 */
export const getDecisionOverlay = (texts) => {
    if (instance) {
        return instance;
    }

    const overlay = document.getElementById('cgDecisionOverlay');
    const body = document.getElementById('cgDecisionBody');
    const acceptBtn = document.getElementById('cgDecisionAccept');
    const adjustBtn = document.getElementById('cgDecisionAdjust');
    const chatScroll = document.getElementById('courseaiChatScroll');
    const compactChatCard = document.getElementById('compactChatCard');

    if (!overlay) {
        // Template not present yet — return a no-op stub so callers don't crash.
        return {
            show: () => undefined,
            hide: () => undefined,
            getBody: () => null,
            isVisible: () => false,
        };
    }

    // Set i18n labels if supplied and not yet set.
    if (texts) {
        if (acceptBtn && texts.courseai_decision_accept) {
            acceptBtn.textContent = texts.courseai_decision_accept;
        }
        if (adjustBtn && texts.courseai_decision_adjust) {
            adjustBtn.textContent = texts.courseai_decision_adjust;
        }
    }

    /**
     * Show the decision card: it sits at the bottom of the chat column, in the
     * composer's slot. ONLY the input field is replaced — the message thread above
     * stays visible and scrollable (per the user's clarification: "tapar el chat"
     * meant only the write field, not the messages). So the scroll region is left
     * untouched; only the composer is hidden so the card takes its place.
     *
     * @returns {void}
     */
    const show = () => {
        if (!overlay) {
            return;
        }
        // Debounced: only reveal the card once the user pauses (SHOW_DELAY_MS with no
        // intervening hide), so it never flashes between back-to-back adjustments.
        clearTimeout(pendingShowTimer);
        pendingShowTimer = setTimeout(() => {
            overlay.style.display = 'flex';
            if (compactChatCard) {
                compactChatCard.style.display = 'none';
            }
            // The full card has its own Accept — drop the composer's inline accept bar.
            const acceptBar = document.getElementById('cgAcceptBar');
            if (acceptBar) {
                acceptBar.style.display = 'none';
            }
            // Keep the newest turn in view above the card.
            if (chatScroll) {
                chatScroll.scrollTop = chatScroll.scrollHeight;
            }
        }, SHOW_DELAY_MS);
    };

    /**
     * Hide the decision card. The compact chat display is not forced here — callers
     * (review-actions, feedback) manage its state via setCompactChatState.
     *
     * @returns {void}
     */
    const hide = () => {
        // Cancel any pending (debounced) show so a just-started action never lets the
        // card flash in a beat later.
        clearTimeout(pendingShowTimer);
        if (!overlay) {
            return;
        }
        overlay.style.display = 'none';
    };

    /**
     * Return the body slot element where proposals or content is injected.
     *
     * @returns {HTMLElement|null}
     */
    const getBody = () => body;

    /**
     * Return whether the overlay is currently visible.
     *
     * @returns {boolean}
     */
    const isVisible = () => overlay ? overlay.style.display !== 'none' : false;

    instance = {show, hide, getBody, isVisible};
    return instance;
};

/**
 * Reset the module-level singleton. Call during test teardown or full page resets.
 *
 * @returns {void}
 */
export const resetDecisionOverlay = () => {
    instance = null;
};
