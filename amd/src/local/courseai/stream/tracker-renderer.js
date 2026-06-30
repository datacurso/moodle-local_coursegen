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
 * Generation progress renderer.
 *
 * The generation phase reuses the SAME plan cards as planning (#prvSections)
 * instead of a separate progress panel, so the whole wizard stays visually
 * unified. This maps the generation tracker's per-activity status onto the real
 * activity rows (matched by data-activity-id): a status class drives a spinner
 * while an activity is being created in Moodle, then a check when it is done
 * (see the body.cg-generating rules in aicoursecreation.css).
 *
 * @module     local_coursegen/local/courseai/stream/tracker-renderer
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Map a tracker activity status to the row's generation-status class. */
const STATUS_CLASS = {
    done: 'cg-gen-done',
    in_progress: 'cg-gen-active',
    pending: 'cg-gen-pending',
};

const ALL_STATUS_CLASSES = ['cg-gen-done', 'cg-gen-active', 'cg-gen-pending'];

/**
 * Reflect the generation tracker's per-activity status onto the live plan cards
 * (#prvSections). Called on every tracker update via the bound renderTracker.
 *
 * @param {Object} state - Shared stream state (reads state.generationTracker).
 * @returns {void}
 */
export const renderGenerationTracker = (state) => {
    const tracker = state.generationTracker;
    if (!tracker || !Array.isArray(tracker.flat)) {
        return;
    }
    const root = document.getElementById('prvSections');
    if (!root) {
        return;
    }
    tracker.flat.forEach((activity) => {
        if (!activity || !activity.id) {
            return;
        }
        const row = root.querySelector('[data-activity-id="' + activity.id + '"]');
        if (!row) {
            return;
        }
        row.classList.remove(...ALL_STATUS_CLASSES);
        row.classList.add(STATUS_CLASS[activity.status] || 'cg-gen-pending');
    });
};
