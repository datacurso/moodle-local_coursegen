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
 * Bootstrap class names of the running Moodle, given by PHP.
 *
 * Moodle 4.5 ships Bootstrap 4 and Moodle 5.0+ ships Bootstrap 5, whose class names differ.
 * PHP decides which set applies (\local_coursegen\local\bootstrap_classes) and hands it to the
 * entry point of the page, which stores it here for the templates and markup built in JavaScript.
 *
 * @module     local_coursegen/local/bootstrap_classes
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Object<string, string>} */
let classes = {};

/**
 * Store the class names given by PHP.
 *
 * @param {Object<string, string>} map Class names keyed by purpose.
 */
export const setClasses = (map) => {
    classes = (map && typeof map === 'object') ? {...map} : {};
};

/**
 * The stored class names, as a plain object for template contexts.
 *
 * @returns {Object<string, string>}
 */
export const getClasses = () => ({...classes});

/**
 * One stored class name; an empty string for a purpose that was never given.
 *
 * @param {string} purpose Key of the class name (e.g. 'iconspacing2').
 * @returns {string}
 */
export const bsClass = (purpose) => (typeof classes[purpose] === 'string' ? classes[purpose] : '');
