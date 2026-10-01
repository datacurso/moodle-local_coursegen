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
 * Default action of every activity, applied automatically when a course's
 * structure loads.
 *
 * A real template has dozens of activities, so each one is seeded with the
 * same neutral default ("keep") the moment the course structure loads, the
 * same default the server renders; the admin only has to touch the
 * exceptions via the per-activity dropdown in the section/activity review.
 *
 * @module     local_coursegen/local/template/type_action_sync
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {MODNAMES} from 'local_coursegen/ai_activity_types';
import {ACTION} from 'local_coursegen/local/template/constants';
import Selectors from 'local_coursegen/local/template/selectors';

/**
 * @param {string} modname
 * @returns {boolean} Whether "Template" is a safe option for this module type today: every type the
 *     AI service has a content contract for (see ai_activity_types.js) offers it.
 */
export const typeSupportsTemplate = (modname) => MODNAMES.includes(modname);

/**
 * Seed one rendered activity with the default action, unless it already has
 * an explicit value, which is never overwritten.
 *
 * @param {HTMLElement} cmitem The rendered activity.
 * @param {Object} state
 */
const seedActivityDefault = (cmitem, state) => {
    const cmid = parseInt(cmitem.dataset.id);
    if (!cmid || state.activityAction[cmid] !== undefined) {
        return;
    }
    state.activityAction[cmid] = ACTION.KEEP;
    state.activityRef[cmid] = true;
};

/**
 * Seed state.activityAction with the default for every activity that
 * doesn't already have an explicit value — never overwrites a value the
 * admin (or a previous render) already set.
 *
 * @param {HTMLElement} container The rendered course structure.
 * @param {Object} state
 */
export const applyTypeDefaultsToState = (container, state) => {
    const cmitems = container.querySelectorAll(Selectors.rows.activity);
    for (const cmitem of cmitems) {
        seedActivityDefault(cmitem, state);
    }
};
