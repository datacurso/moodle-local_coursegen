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
 * Icon assets and add-trigger button for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/icons
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Sparkles SVG for the AI-adjust action button. */
export const iaSparklesSvg = [
    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"',
    'stroke="currentColor" stroke-width="2" stroke-linecap="round"',
    'stroke-linejoin="round" aria-hidden="true">',
    '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582' +
        'a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135' +
        'a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581' +
        'a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135' +
        'a.5.5 0 0 1-.962 0z"/>',
    '</svg>'
].join(' ');

/**
 * Build the absolute URL for a Moodle core pix icon.
 *
 * @param {string} iconkey - Path fragment (e.g. "t/delete").
 * @returns {string}
 */
export const getCoreIconUrl = (iconkey) => {
    if (!iconkey || typeof iconkey !== 'string') {
        return '';
    }
    // eslint-disable-next-line no-undef
    return M.cfg.wwwroot + '/pix/' + iconkey + '.svg';
};

/** SVG grip icon for drag handles (six dots, 10×14 px). */
export const gripSvg = [
    '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"',
    'aria-hidden="true">',
    '<circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/>',
    '<circle cx="2" cy="7" r="1.5"/><circle cx="8" cy="7" r="1.5"/>',
    '<circle cx="2" cy="12" r="1.5"/><circle cx="8" cy="12" r="1.5"/>',
    '</svg>'
].join(' ');

/** Maps activity type → Moodle purpose tint class for the mod-icon chip. */
export const activityPurpose = {
    page: 'content', book: 'content', resource: 'content',
    label: 'content', url: 'content', lesson: 'content',
    glossary: 'content', data: 'content', h5pactivity: 'content',
    quiz: 'assessment', assign: 'assessment',
    forum: 'collaboration', chat: 'collaboration', workshop: 'collaboration',
    choice: 'communication', feedback: 'communication', survey: 'communication',
};

/**
 * Create a dashed "+ Add …" trigger button.
 *
 * @param {string} label - Visible button text.
 * @returns {HTMLButtonElement}
 */
export const createAddTriggerBtn = (label) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dp-add-control dp-add-control--disabled';

    const icon = document.createElement('span');
    icon.className = 'dp-add-control__icon';
    icon.textContent = '+';
    icon.setAttribute('aria-hidden', 'true');

    const labelSpan = document.createElement('span');
    labelSpan.textContent = label;

    btn.appendChild(icon);
    btn.appendChild(labelSpan);
    return btn;
};
