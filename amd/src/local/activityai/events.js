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

import {dispatchEvent} from 'core/event_dispatcher';

/**
 * Reactive events for Activity AI.
 *
 * @module     local_coursegen/local/activityai/events
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Events for the Activity AI reactive instance.
 */
export const eventTypes = {
    /**
     * Event triggered when the reactive state is updated.
     */
    activityAiStateUpdated: 'local_coursegen/activityAiStateUpdated',
};

/**
 * Trigger an event to indicate that the reactive state is updated.
 *
 * @param {Object} detail Event detail.
 * @param {HTMLElement} container Event target.
 * @returns {CustomEvent}
 */
export const notifyActivityAiStateUpdated = (detail, container) => {
    return dispatchEvent(eventTypes.activityAiStateUpdated, detail, container);
};
