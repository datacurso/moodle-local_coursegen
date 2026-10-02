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
 * Checklist item builder shared by the planning checklists.
 *
 * The initial-planning checklist (live stream and reload rebuild), the
 * regeneration blocks and the resume snapshot all render the same
 * spinner-to-check item; this module is the single source of that markup.
 *
 * @module     local_coursegen/local/courseai/ui/checklist-item
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {escapeHtml} from 'local_coursegen/local/courseai/utils';
import {hideOnError} from 'local_coursegen/local/courseai/ui/dom';

/**
 * The spinner/check circle markup of a checklist item.
 *
 * @returns {string}
 */
export const checkMarkup = () => '<span class="courseai-checklist-check">'
    + '<svg class="spinner-icon" viewBox="0 0 24 24">'
    + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
    + '<svg class="check-icon" viewBox="0 0 24 24">'
    + '<polyline points="20 6 9 17 4 12"/></svg></span>';

/**
 * Build one checklist <li>: a head (check circle, optional activity icon and
 * name) followed by the Markdown detail slot, or, in the flat layout, just the
 * check circle and the name as direct children (resume snapshot checklist).
 *
 * Callers set their own data attributes (section/round/regen ids) on the
 * returned element.
 *
 * @param {Object} opts
 * @param {string} opts.name - Item label (section or activity name), escaped here.
 * @param {string} [opts.state='loading'] - 'loading' (spinner) or 'done' (check).
 * @param {string} [opts.iconUrl] - Activity icon URL shown before the name (hidden on load error).
 * @param {string} [opts.detailHtml=''] - Pre-rendered (trusted) detail HTML, empty for a live item.
 * @param {boolean} [opts.flat=false] - Flat layout without head wrapper and detail slot.
 * @returns {HTMLElement}
 */
export const buildChecklistItem = ({name, state = 'loading', iconUrl = '', detailHtml = '', flat = false}) => {
    const item = document.createElement('li');
    item.className = 'courseai-checklist-item ' + (state === 'done' ? 'is-done' : 'is-loading');
    const nameHtml = '<span class="courseai-checklist-name">' + escapeHtml(String(name || '')) + '</span>';
    if (flat) {
        item.innerHTML = checkMarkup() + nameHtml;
        return item;
    }
    const iconHtml = iconUrl
        ? '<img class="cg-activity-icon" src="' + escapeHtml(iconUrl) + '" alt="">'
        : '';
    item.innerHTML = '<div class="courseai-checklist-head">'
        + checkMarkup()
        + iconHtml
        + nameHtml
        + '</div>'
        + '<div class="courseai-checklist-detail cg-log-md">' + detailHtml + '</div>';
    hideOnError(item.querySelector('img.cg-activity-icon'));
    return item;
};
