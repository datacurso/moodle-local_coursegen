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
 * The loading skeletons of a reloaded page.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-skeleton
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Snapshot statuses of a run that may not have drawn its first section yet. */
const STARTING_STATUSES = ['PENDING', 'PLANNING'];

/** Ids of the skeletons the page shows until the first section is drawn. */
const SKELETON_IDS = ['cgLeftSkeleton', 'cgCenterSkeleton'];

/**
 * Whether the page is still waiting for the first section of the plan.
 *
 * The live stream keeps the skeletons until its first section arrives, so a page reloaded in that
 * window keeps them too: nothing has been drawn that could replace them.
 *
 * @param {string} status The normalized snapshot status.
 * @param {Array} sections The sections of the plan the snapshot carries.
 * @param {Array} events The events the run emitted so far.
 * @returns {boolean} True when the skeletons must stay.
 */
export const isWaitingForFirstSection = (status, sections, events) => {
    if (!STARTING_STATUSES.includes(status)) {
        return false;
    }
    if (Array.isArray(sections) && sections.length > 0) {
        return false;
    }
    let emitted = [];
    if (Array.isArray(events)) {
        emitted = events;
    }
    const hasSection = emitted.some((event) => event && event.type === 'section');
    return !hasSection;
};

/**
 * Hide the loading skeletons.
 *
 * @param {Object} root Where the skeletons are looked up by id, normally the document.
 * @returns {void}
 */
export const hideSkeletons = (root) => {
    SKELETON_IDS.forEach((id) => {
        const skeleton = root.getElementById(id);
        if (skeleton) {
            skeleton.style.display = 'none';
        }
    });
};
