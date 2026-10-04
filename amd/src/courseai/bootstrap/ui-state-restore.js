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
 * Put back what the user had left open and where the user had scrolled on the course page.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state-restore
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {DRAFT_FIELDS, SCROLL_PANELS, TOGGLE_KINDS} from 'local_coursegen/courseai/bootstrap/ui-state-capture';

/** Milliseconds the toggles get to change the height of the page before the panels are scrolled. */
const TOGGLE_SETTLE_MS = 450;

/**
 * Wait for a number of milliseconds.
 *
 * @param {number} milliseconds
 * @returns {Promise<void>}
 */
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

/**
 * Click the toggles of one kind whose state differs from the saved one, so the code of the page
 * that opens and closes them runs as it does for the user.
 *
 * @param {Object} root Where the toggles are looked up, normally the document.
 * @param {Object} kind One of TOGGLE_KINDS.
 * @param {Array} saved The saved open states of the kind, in document order.
 * @returns {void}
 */
const applyKind = (root, kind, saved) => {
    const found = root.querySelectorAll(kind.selector);
    const elements = Array.from(found);
    elements.forEach((element, index) => {
        const wanted = saved[index];
        if (typeof wanted !== 'boolean' || kind.isOpen(element) === wanted) {
            return;
        }
        element.click();
    });
};

/**
 * Click the toggles of every kind whose state differs from the saved one.
 *
 * @param {Object} root Where the toggles are looked up, normally the document.
 * @param {Object} saved The saved open states, by the name of the kind.
 * @returns {void}
 */
const applyToggles = (root, saved) => {
    TOGGLE_KINDS.forEach((kind) => {
        const list = saved[kind.name];
        if (Array.isArray(list)) {
            applyKind(root, kind, list);
        }
    });
};

/**
 * Where a panel has to be scrolled to.
 *
 * @param {Object} panel
 * @param {{top: number, atEnd: boolean}} position The saved scroll of the panel.
 * @returns {number} The scrollTop to set.
 */
const scrollTopOf = (panel, position) => {
    if (position.atEnd) {
        return panel.scrollHeight;
    }
    const top = Number(position.top);
    if (Number.isFinite(top)) {
        return top;
    }
    return 0;
};

/**
 * Scroll every panel that is on the page to where it was.
 *
 * A panel that was at its end goes to the end of what it holds now, because what it holds may have grown.
 *
 * @param {Object} root Where the panels are looked up, normally the document.
 * @param {Object} saved The saved scroll, by the name of the panel.
 * @returns {void}
 */
const applyScroll = (root, saved) => {
    Object.entries(SCROLL_PANELS).forEach(([name, id]) => {
        const panel = root.getElementById(id);
        const position = saved[name];
        if (!panel || !position) {
            return;
        }
        panel.scrollTop = scrollTopOf(panel, position);
    });
};

/**
 * Put the text the user typed and did not send back in the fields that are still empty.
 *
 * The field is told that it changed, so the page reacts as it does to typing, for example by enabling
 * its send button.
 *
 * @param {Object} root Where the fields are looked up, normally the document.
 * @param {Object} saved The saved drafts, by the name of the field.
 * @returns {void}
 */
const applyDrafts = (root, saved) => {
    Object.entries(DRAFT_FIELDS).forEach(([name, id]) => {
        const field = root.getElementById(id);
        const text = saved[name];
        if (!field || typeof text !== 'string') {
            return;
        }
        const current = String(field.value || '');
        if (current !== '') {
            return;
        }
        const changed = new Event('input', {bubbles: true});
        field.value = text;
        field.dispatchEvent(changed);
    });
};

/**
 * Take the user back to the chat input if the user was adjusting, which only applies while the page
 * shows the review card the Adjust button belongs to.
 *
 * @param {Object} root Where the review card and its button are looked up, normally the document.
 * @param {Object} saved The saved modes.
 * @returns {void}
 */
const applyModes = (root, saved) => {
    if (saved.adjusting !== true) {
        return;
    }
    const overlay = root.getElementById('cgDecisionOverlay');
    const adjust = root.getElementById('cgDecisionAdjust');
    if (!overlay || !adjust || overlay.style.display === 'none') {
        return;
    }
    adjust.click();
};

/**
 * Put the page back as the user left it: the mode of the review first, then what was open, then where
 * each panel was scrolled and the text that was typed.
 *
 * @param {Object} root Where the page elements are looked up, normally the document.
 * @param {Object|null} saved What captureUiState read.
 * @param {Object} [options]
 * @param {Function} [options.wait] Waits for the toggles to settle.
 * @param {Function} [options.shouldStop] True once the user took over, so nothing else is changed.
 * @returns {Promise<void>}
 */
export const restoreUiState = async(root, saved, {wait = () => pause(TOGGLE_SETTLE_MS), shouldStop = () => false} = {}) => {
    if (!saved || typeof saved !== 'object' || shouldStop()) {
        return;
    }

    if (saved.modes && typeof saved.modes === 'object') {
        applyModes(root, saved.modes);
    }

    if (saved.toggles && typeof saved.toggles === 'object') {
        applyToggles(root, saved.toggles);
    }

    await wait();
    if (shouldStop()) {
        return;
    }

    if (saved.scroll && typeof saved.scroll === 'object') {
        applyScroll(root, saved.scroll);
    }

    if (saved.drafts && typeof saved.drafts === 'object') {
        applyDrafts(root, saved.drafts);
    }
};
