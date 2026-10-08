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
 * A step the AI takes twice in a row ("Reading an activity" twice) is one line of the feed with a count, not two
 * identical lines. Only the step at the very end of the feed can take a repeat, so the order of the run is never
 * rewritten, and only a step of the AI: a turn of the teacher or a card is never merged.
 *
 * @module     local_coursegen/local/courseai/template/feed_repeat
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STEP_CLASS = 'cg-log-entry--turn-ai';
const MESSAGE_CLASS = 'cg-log-msg';
const MARKDOWN_CLASS = 'cg-log-md';
const BADGE_CLASS = 'cg-log-repeat';

/**
 * The words of a step, without the count badge: the first text node of its message.
 *
 * @param {Element} message The message element of a step.
 * @returns {string}
 */
const wordsOf = (message) => String(message.childNodes[0]?.nodeValue ?? '');

/**
 * The step at the end of the feed that a repeat may be merged into.
 *
 * @param {Element|null} feed The feed of the run.
 * @param {string} message What the new step says.
 * @returns {Element|null} The entry of the step, or null when the new step is not a repeat.
 */
const repeatedStep = (feed, message) => {
    const last = feed?.lastElementChild;
    if (!last || !last.classList.contains(STEP_CLASS)) {
        return null;
    }
    const text = last.querySelector('.' + MESSAGE_CLASS);
    if (!text || text.classList.contains(MARKDOWN_CLASS)) {
        return null;
    }
    if (wordsOf(text) !== message) {
        return null;
    }
    return last;
};

/**
 * The count badge of a step, created the first time the step repeats.
 *
 * @param {Element} entry The entry of the step.
 * @returns {Element}
 */
const badgeOf = (entry) => {
    const text = entry.querySelector('.' + MESSAGE_CLASS);
    const existing = text.querySelector('.' + BADGE_CLASS);
    if (existing) {
        return existing;
    }
    const badge = entry.ownerDocument.createElement('span');
    badge.className = BADGE_CLASS;
    badge.setAttribute('aria-hidden', 'true');
    text.appendChild(badge);
    return badge;
};

/**
 * Merge a step into the one just before it when they say the same.
 *
 * @param {Element|null} feed The feed of the run.
 * @param {string} message What the new step says, for example "Reading an activity".
 * @returns {boolean} True when the step was merged and must not be added as a new line.
 */
export const collapseRepeat = (feed, message) => {
    const entry = repeatedStep(feed, message);
    if (entry === null) {
        return false;
    }
    const seen = Number(entry.dataset.repeat || 1);
    const count = seen + 1;
    entry.dataset.repeat = String(count);
    const badge = badgeOf(entry);
    badge.textContent = '×' + count;
    return true;
};
