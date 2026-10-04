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
 * Puts the course page back as the user left it, and keeps what the user leaves open from then on.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {loadUiState} from 'local_coursegen/courseai/bootstrap/ui-state-storage';
import {restoreUiState} from 'local_coursegen/courseai/bootstrap/ui-state-restore';
import {startUiStateTracking} from 'local_coursegen/courseai/bootstrap/ui-state-tracking';
import {waitForQuiet} from 'local_coursegen/courseai/bootstrap/ui-state-settle';

/** What the user does that means the user is in control of the page again. */
const TAKEOVER_EVENTS = ['wheel', 'touchstart', 'keydown', 'mousedown'];

/**
 * Notices when the user takes over the page while it is being put back, so the user is never fought.
 */
class TakeoverGuard {
    /**
     * @param {Object} root Event target of the page, normally the document.
     */
    constructor(root) {
        this.root = root;
        this.tookOver = false;
        this.onTakeover = this.onTakeover.bind(this);
        this.hasTakenOver = this.hasTakenOver.bind(this);
    }

    /**
     * Start listening.
     *
     * @returns {void}
     */
    start() {
        TAKEOVER_EVENTS.forEach((type) => this.root.addEventListener(type, this.onTakeover, true));
    }

    /**
     * Stop listening.
     *
     * @returns {void}
     */
    stop() {
        TAKEOVER_EVENTS.forEach((type) => this.root.removeEventListener(type, this.onTakeover, true));
    }

    /**
     * The user did something on the page.
     *
     * @returns {void}
     */
    onTakeover() {
        this.tookOver = true;
    }

    /**
     * Whether the user took over since the guard started.
     *
     * @returns {boolean}
     */
    hasTakenOver() {
        return this.tookOver;
    }
}

/**
 * The storage of the tab, or null when the browser does not give access to it.
 *
 * @param {Object} win The window.
 * @returns {Object|null}
 */
export const tabStorage = (win) => {
    try {
        return win.sessionStorage;
    } catch (error) {
        return null;
    }
};

/**
 * Wait until the workspace of the page stops changing.
 *
 * @param {Object} root Where the workspace is looked up, normally the document.
 * @returns {Promise<void>}
 */
const waitForWorkspace = (root) => {
    const workspace = root.getElementById('courseaiWorkspace');
    return waitForQuiet(workspace);
};

/**
 * Put the page back as saved, once the page has been drawn, unless the user takes over first.
 *
 * @param {Object} params
 * @param {Object} params.root Lookup and event target of the page, normally the document.
 * @param {Object} params.saved What captureUiState read.
 * @param {Function} params.settle Resolves when the page stopped changing.
 * @param {Object} params.restoreOptions Options of restoreUiState.
 * @returns {Promise<void>}
 */
const restoreWhenSettled = async({root, saved, settle, restoreOptions}) => {
    const guard = new TakeoverGuard(root);
    guard.start();
    try {
        await settle(root);
        await restoreUiState(root, saved, {...restoreOptions, shouldStop: guard.hasTakenOver});
    } finally {
        guard.stop();
    }
};

/**
 * Put a reloaded page back as the user left it and keep tracking what the user leaves open.
 *
 * Tracking starts after the restore, because the restore itself clicks and scrolls and would otherwise
 * overwrite what it is restoring.
 *
 * @param {Object} params
 * @param {Object} params.root Lookup and event target of the page, normally the document.
 * @param {Object} params.win Where the page is left from, normally the window.
 * @param {Object|null} params.storage A Storage, normally sessionStorage.
 * @param {Function} params.getSessionId Planning session record id, which is 0 until the page has a session.
 * @param {boolean} params.restore Whether the page was reloaded, so there is a state to put back.
 * @param {Function} [params.settle] Resolves when the page stopped changing.
 * @param {Object} [params.restoreOptions] Options of restoreUiState, for the tests.
 * @returns {Promise<Object>} The tracker, to stop it with stop().
 */
export const restoreAndTrackUiState = async({
    root, win, storage, getSessionId, restore, settle, restoreOptions = {},
}) => {
    const sessionId = getSessionId();
    const saved = loadUiState(storage, sessionId);
    if (restore && saved) {
        const waitForPage = settle || waitForWorkspace;
        await restoreWhenSettled({root, saved, settle: waitForPage, restoreOptions});
    }
    return startUiStateTracking({root, win, storage, getSessionId});
};
