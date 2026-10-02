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
 * Shared AJAX call helper for the plugin repositories.
 *
 * @module     local_coursegen/repository/base
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Call one external function and return its promise.
 *
 * @param {string} methodname External function name (local_coursegen_*).
 * @param {Object} args Arguments of the external function.
 * @returns {Promise<*>} Resolves with the external function response.
 */
export const call = (methodname, args = {}) => Ajax.call([{methodname, args}])[0];
