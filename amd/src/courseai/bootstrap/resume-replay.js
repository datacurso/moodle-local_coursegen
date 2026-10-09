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
 * Which events of a snapshot a reloaded page replays.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-replay
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Snapshot statuses of a generation that is still running. */
const RUNNING_STATUSES = ['PENDING', 'PLANNING', 'PLANNING_ADJUST', 'PLANNING_ACCEPT', 'GENERATING'];

/**
 * The events to replay for a snapshot.
 *
 * Only a run that is still going has events to replay: at a review or at the end the plan and the
 * transcript show everything, and the events of the phase were emptied.
 *
 * @param {string} status The normalized snapshot status.
 * @param {Object} snapshot The resume snapshot.
 * @returns {Array} The parsed events, oldest first; empty when there is nothing to replay.
 */
export const eventsToReplay = (status, snapshot) => {
    const events = snapshot && snapshot.progress_events;
    if (!Array.isArray(events) || !RUNNING_STATUSES.includes(status)) {
        return [];
    }
    return events;
};
