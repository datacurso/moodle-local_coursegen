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
 * How far the repeat blocks of each activity have got: the items written out of the items planned.
 *
 * An activity may hold several blocks, each planned on its own and written in groups that finish in any
 * order, so the counts are kept per block and an activity reports their sum. Nothing here touches the page.
 *
 * @module     local_coursegen/local/courseai/template/repeat_progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A whole number of at least zero.
 *
 * @param {*} value
 * @returns {number}
 */
const count = (value) => Math.max(0, Math.floor(Number(value) || 0));

/**
 * A tracker with no activity yet.
 *
 * @returns {Object} {plan, progress, summary, reset}
 */
export const createRepeatTracker = () => {
    const activities = new Map();

    /**
     * The blocks of one activity, created on first use.
     *
     * @param {string} uid
     * @returns {Map}
     */
    const blocksOf = (uid) => {
        if (!activities.has(uid)) {
            activities.set(uid, new Map());
        }
        return activities.get(uid);
    };

    return {
        /**
         * The plan of a block is ready.
         *
         * @param {string} uid
         * @param {string} block
         * @param {number} total
         */
        plan: (uid, block, total) => {
            const blocks = blocksOf(uid);
            const known = blocks.get(block);
            blocks.set(block, {total: count(total), done: known ? known.done : 0, last: known ? known.last : ''});
        },

        /**
         * Some items of a block are written. A late message never takes the count back.
         *
         * @param {string} uid
         * @param {string} block
         * @param {number} done
         * @param {number} total
         * @param {string} last
         */
        progress: (uid, block, done, total, last) => {
            const blocks = blocksOf(uid);
            const known = blocks.get(block) || {total: 0, done: 0, last: ''};
            const nextDone = count(done);
            blocks.set(block, {
                total: Math.max(known.total, count(total)),
                done: Math.max(known.done, nextDone),
                last: nextDone >= known.done && last ? String(last) : known.last,
            });
        },

        /**
         * What the activity reports: the sum over its blocks and the last item written, or null with no block.
         *
         * @param {string} uid
         * @returns {Object|null} {done, total, last}
         */
        summary: (uid) => {
            const blocks = activities.get(uid);
            if (!blocks || blocks.size === 0) {
                return null;
            }
            let done = 0;
            let total = 0;
            let last = '';
            for (const block of blocks.values()) {
                done += Math.min(block.done, block.total);
                total += block.total;
                last = block.last || last;
            }
            return {done, total, last};
        },

        /**
         * Forget everything, for a run that starts over.
         */
        reset: () => {
            activities.clear();
        },
    };
};
