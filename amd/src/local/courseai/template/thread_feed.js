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
 * Where the next thing of the thread goes: the thread only grows downwards.
 *
 * Before the activities are listed, what happens goes into the feed above the list. Once the list is on the page,
 * anything new goes into the feed under it, so nothing is ever added in the middle of what is already drawn.
 *
 * @module     local_coursegen/local/courseai/template/thread_feed
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether the list of the activities is on the page.
 *
 * @returns {boolean}
 */
export const listIsShown = () => {
    const list = document.getElementById('courseaiLeftChecklist');
    return !!list && !list.classList.contains('hidden');
};

/**
 * The feed the next thing of the thread goes into.
 *
 * @returns {HTMLElement|null}
 */
export const currentFeed = () => {
    const after = document.getElementById('cgLogAfter');
    if (after && listIsShown()) {
        return after;
    }
    return document.getElementById('cgLog');
};
