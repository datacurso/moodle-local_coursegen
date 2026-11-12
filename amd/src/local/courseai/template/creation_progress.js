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
 * The percentage of the writing of the course in Moodle, from what the server reports.
 *
 * Writing the activities is almost all of the work, so it covers most of the bar and moves with each activity written;
 * the steps around it have a fixed place. The bar starts at zero and reaches a hundred only when the course exists.
 *
 * @module     local_coursegen/local/courseai/template/creation_progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Where each step that has no count of its own is on the bar, in percent. */
const STEP_PLACE = {result: 0, course: 2, kept: 92, arrange: 96};

/** The share of the bar that the activities cover: from the end of the course step to the start of the kept ones. */
const ACTIVITIES_FROM = 2;
const ACTIVITIES_TO = 92;

/**
 * Where the bar is for a step the server reported.
 *
 * @param {string} step What the creation is doing: result, course, activities, kept, arrange.
 * @param {number} done Activities written so far.
 * @param {number} total Activities to write.
 * @param {number} current Where the bar is now: a step the bar does not know leaves it where it was, and it never goes back.
 * @returns {number} Whole percent from 0 to 99.
 */
export const creationPercent = (step, done, total, current = 0) => {
    let place = current;
    if (step === 'activities' && total > 0) {
        const share = Math.min(1, Math.max(0, done / total));
        place = ACTIVITIES_FROM + (ACTIVITIES_TO - ACTIVITIES_FROM) * share;
    } else if (Object.prototype.hasOwnProperty.call(STEP_PLACE, step)) {
        place = STEP_PLACE[step];
    }
    return Math.min(99, Math.max(current, Math.round(place)));
};
