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
 * DOM helpers shared by the course AI UI modules.
 *
 * @module     local_coursegen/local/courseai/ui/dom
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Hide an image when it fails to load.
 *
 * Replaces the inline onerror="this.style.display='none'" attribute the
 * markup builders used: attach it right after the image is inserted (the
 * error event is dispatched asynchronously, so a listener added in the same
 * task still catches it).
 *
 * @param {HTMLImageElement|null} img
 * @returns {HTMLImageElement|null} The same element, for chaining.
 */
export const hideOnError = (img) => {
    if (!img) {
        return null;
    }
    img.addEventListener('error', () => {
        img.style.display = 'none';
    });
    return img;
};
