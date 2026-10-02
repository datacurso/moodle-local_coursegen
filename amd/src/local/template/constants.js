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
 * the per-activity actions, the per-section behaviours, the template scopes,
 * the DOM event names the screen listens to, the flag value a checked
 * "required" radio carries, and the language string keys used from more than
 * one module.
 *
 * The values mirror the constants of local_coursegen\local\models\template_activity
 * and local_coursegen\local\models\template_section, which own them.
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
    REFERENCE: 'reference',
    EXCLUDE: 'exclude',
    TEMPLATE: 'template',
    SPACE: 'space',
};

/** @type {Object} What the generator does with a section. */
export const BEHAVIOR = {
    AI_MODIFY: 'aimodify',
    KEEP: 'keep',
    EXCLUDE: 'exclude',
};

/** @type {Object} How far a template activity can be offered. */
export const SCOPE = {
    COURSE: 'course',
    SECTION: 'section',
};

/** @type {Object} CSS classes the scripts add or remove to change how a row looks (never used to find elements). */
export const CLASS = {
    HIDDEN: 'd-none',
    ROW_SPACE: 'tpl-row-space',
};

/** @type {Object} Tag names the scripts look up on an ancestor. */
export const TAG = {
    TABLE: 'table',
    TABLE_BODY: 'tbody',
};

/** @type {Object} Dropdown commands sent to the Bootstrap dropdown plugin. */
export const DROPDOWN_COMMAND = {
    UPDATE: 'update',
    TOGGLE: 'toggle',
    HIDE: 'hide',
};

/** @type {Object} DOM event names the screen listens to. */
export const EVENT = {
    CHANGE: 'change',
    CLICK: 'click',
    INPUT: 'input',
    BEFORE_UNLOAD: 'beforeunload',
};

/** @type {string} Prefix of the id of a row the teacher added and is not saved yet. */
export const NEW_ROW_PREFIX = 'new-';

/** @type {string} The value a checked "required" radio and a required row carry. */
export const REQUIRED_VALUE = '1';

/** @type {Object} Language string keys used from more than one module. */
export const STRING = {
    SCOPE_SAME_SECTION: 'template_instance_scope_same_section',
    SCOPE_WHOLE_COURSE: 'template_instance_scope_whole_course',
    SCOPE_UNAVAILABLE: 'template_instance_scope_unavailable',
    ADD_SPACE: 'template_add_space',
    SPACE_MODAL_TITLE: 'template_space_modal_title',
    SPACE_REQUIRED: 'template_space_required',
    SPACE_OPTIONAL: 'template_space_optional',
    SPACE_BADGE: 'template_space_badge',
};
