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
 * Heuristic tracker synchronisation from status text.
 *
 * Keeps the dependency on findNextPendingIndex and markTrackerActivityDone
 * separate from the bulk of tracker data management so tracker.js stays
 * under the 250-line limit.
 *
 * @module     local_coursegen/local/courseai/stream/tracker-sync
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {findNextPendingIndex, markTrackerActivityDone} from './tracker';

/**
 * Advance the generation tracker based on a status-text heuristic.
 *
 * Called during 'status' events when structured progress is NOT active.
 * Uses text patterns to infer which activity just started or finished.
 *
 * @param {Object}   state            Shared stream state
 * @param {string}   statusText       English heuristic text from the status event
 * @param {Function} normalizeTextFn  normalizeText from normalize.js
 * @param {Function} extractActivityFn extractActivityFromStatus from normalize.js
 * @param {Function} isDoneFn         isActivityDoneStatus from normalize.js
 * @param {Function} renderTracker    Bound render callback
 */
export const syncTrackerFromStatus = (
    state,
    statusText,
    normalizeTextFn,
    extractActivityFn,
    isDoneFn,
    renderTracker
) => {
    const tracker = state.generationTracker;
    if (!tracker || tracker.flat.length === 0) {
        return;
    }

    const parsedStart = extractActivityFn(statusText);
    const doneStatus = isDoneFn(statusText);

    if (doneStatus && tracker.currentIndex >= 0) {
        markTrackerActivityDone(state, tracker.currentIndex);
        tracker.currentIndex = -1;
    }

    if (!parsedStart) {
        renderTracker();
        return;
    }

    if (tracker.currentIndex >= 0 && tracker.flat[tracker.currentIndex].status !== 'done') {
        markTrackerActivityDone(state, tracker.currentIndex);
    }

    let nextIndex = -1;
    const pendingStart = findNextPendingIndex(state, Math.max(0, tracker.currentIndex + 1));

    if (parsedStart.title) {
        const normalizedTitle = normalizeTextFn(parsedStart.title);
        for (let idx = Math.max(0, pendingStart); idx < tracker.flat.length; idx++) {
            const activity = tracker.flat[idx];
            if (activity.status !== 'pending') {
                continue;
            }
            if (normalizeTextFn(activity.title) === normalizedTitle) {
                nextIndex = idx;
                break;
            }
        }
    }

    if (nextIndex === -1) {
        nextIndex = pendingStart;
    }

    if (nextIndex >= 0) {
        tracker.flat[nextIndex].status = 'in_progress';
        tracker.currentIndex = nextIndex;
    }

    renderTracker();
};
