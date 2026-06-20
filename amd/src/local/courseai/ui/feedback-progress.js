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
 * Decision-log "AI is working" progress indicator for free-text feedback.
 *
 * Shown at the end of the feed when the user submits an adjustment so the view
 * never looks stuck, and removed when the AI's response (review_needed) arrives.
 *
 * @module     local_coursegen/local/courseai/ui/feedback-progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const ENTRY_ID = 'cgFeedbackThinking';

/**
 * Remove the pending "thinking" entry if present.
 *
 * @returns {void}
 */
export const hideFeedbackThinking = () => {
    const existing = document.getElementById(ENTRY_ID);
    if (existing) {
        existing.remove();
    }
};

/**
 * Append a spinner log entry at the end of the active feed.
 *
 * @param {Object} texts - localized strings
 * @returns {void}
 */
export const showFeedbackThinking = (texts) => {
    hideFeedbackThinking();
    const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
    if (!feed) {
        return;
    }

    const entry = document.createElement('div');
    entry.className = 'cg-log-entry cg-log-thinking';
    entry.id = ENTRY_ID;
    entry.setAttribute('role', 'status');
    entry.innerHTML = '<span class="cg-log-bar" aria-hidden="true"></span>'
        + '<span class="cg-log-body">'
        + '<span class="cg-log-spin" aria-hidden="true"></span>'
        + '<span class="cg-log-msg"></span>'
        + '<span class="cg-log-ts">now</span>'
        + '</span>';

    const message = (texts && texts.courseai_log_ai_thinking) || 'Analyzing your request…';
    entry.querySelector('.cg-log-msg').textContent = message;

    feed.appendChild(entry);
    window.requestAnimationFrame(() => {
        entry.scrollIntoView({block: 'nearest', inline: 'nearest'});
    });
};
