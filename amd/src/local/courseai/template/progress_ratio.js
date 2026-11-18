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
 * The share of the activities of a run that are written, which the meter of the progress card draws.
 *
 * @module     local_coursegen/local/courseai/template/progress_ratio
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * How full the meter is.
 *
 * @param {Object} progress {done, total}, for example {done: 1, total: 4}.
 * @returns {number} A number from 0 (nothing written, or nothing announced) to 1 (every activity written).
 */
export const progressRatio = (progress) => {
    const done = Number(progress?.done);
    const total = Number(progress?.total);
    if (!Number.isFinite(done) || !Number.isFinite(total) || total <= 0) {
        return 0;
    }
    const share = done / total;
    const atLeastEmpty = Math.max(0, share);
    return Math.min(1, atLeastEmpty);
};
