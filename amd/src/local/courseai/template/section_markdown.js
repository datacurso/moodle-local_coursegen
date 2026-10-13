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
 * The text of one section of the template generation, the way free creation writes the plan of a section: each activity
 * in bold with its type in italics, and under it, when it has repeat blocks, how many items are written and which they are.
 *
 * Pure text: nothing here touches the page.
 *
 * @module     local_coursegen/local/courseai/template/section_markdown
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The Markdown of one activity.
 *
 * @param {Object} activity {title, type, repeat} where repeat is null or {done, total, last, names}.
 * @param {Object} labels {count: (done, total) => string, includes: (names) => string}
 * @returns {string}
 */
export const activityMarkdown = (activity, labels) => {
    const title = String(activity.title || '').trim() || '-';
    const type = String(activity.type || '').trim();
    const lines = [type ? `**${title}** _(${type})_` : `**${title}**`];
    const repeat = activity.repeat;
    if (repeat && repeat.total > 0) {
        const last = repeat.last && repeat.done < repeat.total ? ` · ${repeat.last}` : '';
        lines.push('', `${labels.count(repeat.done, repeat.total)}${last}`);
        if (repeat.names && repeat.names.length > 0) {
            lines.push('', labels.includes(repeat.names.join(', ')));
        }
    }
    return lines.join('\n');
};

/**
 * The Markdown of one section: its activities, in order, separated by a blank line.
 *
 * @param {Array<Object>} activities As for activityMarkdown.
 * @param {Object} labels As for activityMarkdown.
 * @returns {string}
 */
export const sectionMarkdown = (activities, labels) => activities
    .map((activity) => activityMarkdown(activity, labels))
    .join('\n\n');
