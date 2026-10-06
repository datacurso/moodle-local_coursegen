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
 * Vocabulary the template screen shares with the server: the plugin component,
 * the two actions of an activity, the CSS class a script toggles and the DOM
 * event names the screen listens to.
 *
 * The action values mirror the constants of local_coursegen\local\template\template_actions,
 * which owns them.
 *
 * @module     local_coursegen/local/template/constants
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string} The plugin component, for language strings and templates. */
export const COMPONENT = 'local_coursegen';

/** @type {Object} What the generator does with an activity. */
export const ACTION = {
    KEEP: 'keep',
    AI: 'ai',
};

/** @type {Object} CSS classes the scripts add or remove to change how an element looks (never used to find elements). */
export const CLASS = {
    HIDDEN: 'd-none',
};

/** @type {Object} DOM event names the screen listens to. */
export const EVENT = {
    CHANGE: 'change',
    CLICK: 'click',
    INPUT: 'input',
    BEFORE_UNLOAD: 'beforeunload',
};
