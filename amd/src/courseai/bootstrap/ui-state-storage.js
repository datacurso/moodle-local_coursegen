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
 * Where the course page keeps what the user left open, per planning session.
 *
 * The state is a convenience of the browser: the page works without it, so a storage that is
 * missing, blocked or full is never an error.
 *
 * @module     local_coursegen/courseai/bootstrap/ui-state-storage
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The key a session keeps its state under.
 *
 * @param {number} sessionId Planning session record id.
 * @returns {string}
 */
export const uiStateKey = (sessionId) => 'local_coursegen:courseai-ui:' + sessionId;

/**
 * Parse a stored state.
 *
 * @param {string|null} raw
 * @returns {Object|null} The state, or null when it is missing or not an object.
 */
const parseState = (raw) => {
    if (!raw) {
        return null;
    }
    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            return parsed;
        }
    } catch (error) {
        return null;
    }
    return null;
};

/**
 * Read what the user left open in a session.
 *
 * @param {Object|null} storage A Storage, normally sessionStorage.
 * @param {number} sessionId Planning session record id.
 * @returns {Object|null} The saved state, or null when there is none.
 */
export const loadUiState = (storage, sessionId) => {
    if (!storage || !(sessionId > 0)) {
        return null;
    }
    try {
        const key = uiStateKey(sessionId);
        const raw = storage.getItem(key);
        return parseState(raw);
    } catch (error) {
        return null;
    }
};

/**
 * Keep what the user left open in a session.
 *
 * @param {Object|null} storage A Storage, normally sessionStorage.
 * @param {number} sessionId Planning session record id.
 * @param {Object} state What captureUiState read.
 * @returns {boolean} Whether the state was saved.
 */
export const saveUiState = (storage, sessionId, state) => {
    if (!storage || !(sessionId > 0)) {
        return false;
    }
    try {
        const key = uiStateKey(sessionId);
        const raw = JSON.stringify(state);
        storage.setItem(key, raw);
        return true;
    } catch (error) {
        return false;
    }
};
