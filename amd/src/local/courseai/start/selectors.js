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
 * Selectors for the start screen's top bar crumb.
 *
 * Every JS hook is a data-region attribute, never an id or a CSS class (see
 * local_coursegen/courseai_page).
 *
 * @module     local_coursegen/local/courseai/start/selectors
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    regions: {
        pathBack: '[data-region="local_coursegen/start/path-back"]',
        workspace: '[data-region="local_coursegen/start/workspace"]',
    },
};
