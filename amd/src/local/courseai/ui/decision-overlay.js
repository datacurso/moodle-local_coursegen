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
     * Show the overlay: makes the overlay visible and hides the scroll region.
     * The compactChatCard is hidden so the composer does not float over the overlay.
     *
     * @returns {void}
     */
    const show = () => {
        if (!overlay) {
            return;
        }
        overlay.style.display = 'flex';
        if (chatScroll) {
            chatScroll.style.visibility = 'hidden';
        }
        if (compactChatCard) {
            compactChatCard.style.display = 'none';
        }
    };

    /**
     * Hide the overlay and restore the scroll region and compact chat visibility.
     * The compact chat display is not forced here — callers (review-actions, feedback)
     * manage its state via setCompactChatState.
     *
     * @returns {void}
     */
    const hide = () => {
        if (!overlay) {
            return;
        }
        overlay.style.display = 'none';
        if (chatScroll) {
            chatScroll.style.visibility = '';
        }
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
