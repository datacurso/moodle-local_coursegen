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
 * Reactive instance singleton for Activity AI.
 *
 * @module     local_coursegen/local/activityai/reactive
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Reactive} from 'core/reactive';

import {eventTypes, notifyActivityAiStateUpdated} from 'local_coursegen/local/activityai/events';
import {mutations} from 'local_coursegen/local/activityai/mutations';

export const reactiveInstance = new Reactive({
    name: 'local_coursegen_activityai',
    eventName: eventTypes.activityAiStateUpdated,
    eventDispatch: notifyActivityAiStateUpdated,
    mutations,
});

let initialised = false;

/**
 * Ensure the initial state is set.
 *
 * @param {Object} stateData
 */
export const ensureInitialState = (stateData) => {
    if (initialised) {
        return;
    }

    reactiveInstance.setInitialState(stateData);
    initialised = true;
};
