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
 * Keeps what the user leaves open on the course page, so a reload can put it back.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state-tracking
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {captureUiState} from 'local_coursegen/courseai/bootstrap/ui-state-capture';
import {saveUiState} from 'local_coursegen/courseai/bootstrap/ui-state-storage';

/** Milliseconds a change waits before it is saved, so a burst of scrolls is saved once. */
const SAVE_DELAY_MS = 150;

/**
 * Saves the state of the page after the user clicks, scrolls or types, and when the user leaves.
 */
export class UiStateTracker {
    /**
     * @param {Object} options
     * @param {Object} options.root Event target and lookup of the page elements, normally the document.
     * @param {Object} options.win Where the page is left from, normally the window.
     * @param {Object} options.storage A Storage, normally sessionStorage.
     * @param {Function} options.getSessionId Planning session record id, read at the time of each save.
     * @param {Function} [options.schedule] Runs a callback later, like setTimeout.
     * @param {Function} [options.cancel] Cancels what schedule scheduled, like clearTimeout.
     */
    constructor({root, win, storage, getSessionId, schedule = setTimeout, cancel = clearTimeout}) {
        this.root = root;
        this.win = win;
        this.storage = storage;
        this.getSessionId = getSessionId;
        this.schedule = schedule;
        this.cancel = cancel;
        this.pending = null;
        this.queueSave = this.queueSave.bind(this);
        this.saveNow = this.saveNow.bind(this);
    }

    /**
     * Start listening. Scrolls do not bubble, so they are heard in the capture phase.
     *
     * @returns {void}
     */
    start() {
        this.root.addEventListener('click', this.queueSave, true);
        this.root.addEventListener('scroll', this.queueSave, true);
        this.root.addEventListener('input', this.queueSave, true);
        this.win.addEventListener('pagehide', this.saveNow);
    }

    /**
     * Stop listening and drop the save that was waiting.
     *
     * @returns {void}
     */
    stop() {
        this.root.removeEventListener('click', this.queueSave, true);
        this.root.removeEventListener('scroll', this.queueSave, true);
        this.root.removeEventListener('input', this.queueSave, true);
        this.win.removeEventListener('pagehide', this.saveNow);
        this.dropPending();
    }

    /**
     * Save once the current burst of changes is over.
     *
     * The click has to take effect first, which is why the save is not done inside the event.
     *
     * @returns {void}
     */
    queueSave() {
        if (this.pending !== null) {
            return;
        }
        // Called detached: setTimeout throws when it is called with the tracker as its receiver.
        const schedule = this.schedule;
        this.pending = schedule(this.saveNow, SAVE_DELAY_MS);
    }

    /**
     * Save the state of the page now.
     *
     * @returns {void}
     */
    saveNow() {
        this.dropPending();
        const sessionId = this.getSessionId();
        const state = captureUiState(this.root);
        saveUiState(this.storage, sessionId, state);
    }

    /**
     * Forget the save that was waiting.
     *
     * @returns {void}
     */
    dropPending() {
        if (this.pending === null) {
            return;
        }
        const cancel = this.cancel;
        cancel(this.pending);
        this.pending = null;
    }
}

/**
 * Start keeping the state of the page.
 *
 * @param {Object} options The options of UiStateTracker.
 * @returns {UiStateTracker} The tracker, to stop it with stop().
 */
export const startUiStateTracking = (options) => {
    const tracker = new UiStateTracker(options);
    tracker.start();
    return tracker;
};
