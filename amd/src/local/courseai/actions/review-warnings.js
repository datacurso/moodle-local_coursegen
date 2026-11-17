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
 * Small pieces of the review step: the notice with the warnings of the generation and the start date field.
 *
 * @module     local_coursegen/local/courseai/actions/review-warnings
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fill the warnings notice of the review step, or hide it when there is nothing to say.
 *
 * The notice never blocks anything: it only tells the teacher what the generation noticed.
 *
 * @param {HTMLElement|null} container The list that holds the warnings.
 * @param {string[]|undefined} messages Each warning, already worded by the server.
 */
export const showReviewWarnings = (container, messages) => {
    if (!container) {
        return;
    }
    const lines = Array.isArray(messages) ? messages.filter((message) => !!message) : [];
    const items = lines.map((message) => {
        const item = container.ownerDocument.createElement('li');
        item.textContent = message;
        return item;
    });
    container.replaceChildren(...items);
    container.hidden = items.length === 0;
};

/**
 * The start date the date field holds, when it is a real day (Y-m-d); empty otherwise.
 *
 * @param {string|undefined} value
 * @returns {string}
 */
export const validStartDate = (value) => {
    const text = (value || '').trim();
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
    if (!parts) {
        return '';
    }
    const [year, month, day] = [Number(parts[1]), Number(parts[2]), Number(parts[3])];
    const date = new Date(Date.UTC(year, month - 1, day));
    const real = date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
    return real ? text : '';
};
