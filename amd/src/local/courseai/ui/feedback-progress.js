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
 * Generalized "agent is working…" live indicator for the conversation thread.
 *
 * A SINGLE updating in-thread entry (never one turn per event) that shows the
 * agent is busy: it appears when the user submits an adjustment or when the
 * server starts streaming a status, updates its text in place as new status
 * events arrive, and is removed when meaningful content/sections land or the
 * stream reaches a terminal lifecycle event (review_needed/completed/failed).
 *
 * This keeps the left thread alive without spam: transient status/token/field/
 * progress events feed this one indicator instead of emitting permanent turns.
 *
 * @module     local_coursegen/local/courseai/ui/feedback-progress
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const ENTRY_ID = 'cgFeedbackThinking';

/**
 * Remove the live "working" indicator if present.
 *
 * @returns {void}
 */
export const hideWorkingIndicator = () => {
    const existing = document.getElementById(ENTRY_ID);
    if (existing) {
        existing.remove();
    }
};

/**
 * Backward-compatible alias: drop the live indicator.
 *
 * @returns {void}
 */
export const hideFeedbackThinking = () => {
    hideWorkingIndicator();
};

/**
 * Show (or update) the single live "working" indicator at the end of the feed.
 *
 * If the indicator already exists, only its message text is updated in place so
 * a stream of status events never stacks multiple entries. The indicator is
 * appended at the END of the active feed so it sits next to the input.
 *
 * @param {Object} texts   - Localized strings (for the default message).
 * @param {string} [message] - Explicit message; falls back to the localized default.
 * @returns {void}
 */
export const showWorkingIndicator = (texts, message) => {
    const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
    if (!feed) {
        return;
    }
    const resolved = (message && String(message).trim())
        || (texts && texts.courseai_log_ai_working)
        || (texts && texts.courseai_log_ai_thinking)
        || 'The assistant is working…';

    let entry = document.getElementById(ENTRY_ID);
    if (entry) {
        const msgEl = entry.querySelector('.cg-log-msg');
        if (msgEl) {
            msgEl.textContent = resolved;
        }
        return;
    }

    entry = document.createElement('div');
    entry.className = 'cg-log-entry cg-log-thinking';
    entry.id = ENTRY_ID;
    entry.setAttribute('role', 'status');
    entry.innerHTML = '<span class="cg-log-bar" aria-hidden="true"></span>'
        + '<span class="cg-log-body">'
        + '<span class="cg-log-spin" aria-hidden="true"></span>'
        + '<span class="cg-log-msg"></span>'
        + '<span class="cg-log-ts">now</span>'
        + '</span>';
    entry.querySelector('.cg-log-msg').textContent = resolved;

    feed.appendChild(entry);
    window.requestAnimationFrame(() => {
        entry.scrollIntoView({block: 'nearest', inline: 'nearest'});
    });
};

/**
 * Backward-compatible alias used by the feedback action: show the "Analyzing
 * your request…" indicator (the default message when none is supplied).
 *
 * @param {Object} texts - Localized strings.
 * @returns {void}
 */
export const showFeedbackThinking = (texts) => {
    const message = (texts && texts.courseai_log_ai_thinking) || 'Analyzing your request…';
    showWorkingIndicator(texts, message);
};
