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
 * Replay of the events a generation phase already emitted.
 *
 * The state snapshot of a running generation carries the events its phase has emitted so far.
 * They are routed through the same handlers as the live events, ahead of them and in order, so
 * a reloaded page is drawn the way the live stream drew it.
 *
 * @module     local_coursegen/local/courseai/stream/replay
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Chain the routing of each event onto the queue, in order.
 *
 * A handler that fails does not stop the ones after it, the same as for live events.
 *
 * @param {Promise} queue The queue live events are chained on.
 * @param {Array} events Parsed events to route first.
 * @param {Function} route Routes one parsed event; may be async.
 * @returns {Promise} The queue with the events chained on it.
 */
export const replayInto = (queue, events, route) => {
    if (!Array.isArray(events)) {
        return queue;
    }
    return events.reduce(
        (chain, data) => chain.then(() => route(data)).catch(() => {}),
        queue
    );
};
