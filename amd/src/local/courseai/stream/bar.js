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
 * Stream bar DOM helpers for Course AI.
 *
 * Standalone helpers that manage the planning stream overlay and
 * the thin indeterminate progress bar on the review card.
 *
 * @module     local_coursegen/local/courseai/stream/bar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Ensure the planning stream content is visible and the loading overlay is hidden.
 *
 * @returns {void}
 */
export const ensureStreamContentVisible = () => {
    const loadingEl = document.getElementById('planningLoading');
    const streamContentEl = document.getElementById('planningStreamContent');
    if (loadingEl) {
        loadingEl.style.display = 'none';
    }
    if (streamContentEl) {
        streamContentEl.style.display = '';
    }
};

/**
 * Attach a thin indeterminate progress bar to the top of the review card.
 * Idempotent: no-op if the bar is already present.
 *
 * @returns {void}
 */
export const showStreamBar = () => {
    const card = document.getElementById('planReviewCard');
    if (!card || card.querySelector('.cg-stream-bar')) {
        return;
    }
    const bar = document.createElement('div');
    bar.className = 'cg-stream-bar';
    bar.setAttribute('aria-hidden', 'true');
    card.prepend(bar);
};

/**
 * Remove the thin indeterminate progress bar from the review card.
 *
 * @returns {void}
 */
export const hideStreamBar = () => {
    const card = document.getElementById('planReviewCard');
    if (!card) {
        return;
    }
    const bar = card.querySelector('.cg-stream-bar');
    if (bar) {
        bar.remove();
    }
};
