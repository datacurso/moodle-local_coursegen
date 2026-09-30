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
 * Names the template screen uses to find and mark its own markup: the
 * data-for kinds of a row, the data-region names, the CSS classes it toggles
 * and the selectors more than one module shares. They belong to the mustache
 * templates under templates/, which render them.
 *
 * @module     local_coursegen/local/template/dom_constants
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Object} CSS classes the screen adds, removes or looks for. */
export const CLASS = {
    HIDDEN: 'd-none',
    ROW_GAP: 'tpl-row-gap',
};

/** @type {Object} Tag names the screen looks up. */
export const TAG = {
    TABLE: 'table',
    TABLE_BODY: 'tbody',
};

/** @type {Object} Selectors shared by more than one module. */
export const SELECTOR = {
    SECTION: '[data-for="section"]',
    ACTIVITY_ROW: '[data-for="cmitem"]',
    INSTANCE_ROW: '[data-for="instancerow"]',
    SPACE_ROW: '[data-for="spacerow"]',
    INSTANCE_MENU_TRIGGER: '[data-instance-menu-trigger]',
    DROPDOWN: '.dropdown',
    GAP_OR_ADD: '[data-region="row-gap"], [data-region="add-instance"]',
    SPACE_INSTRUCTION: '[data-region="space-instruction"]',
    SPACE_TAG: '[data-region="space-tag"]',
    INSTANCE_NAME_EDITABLE: '[data-region="instance-name-editable"]',
    TEMPLATE_NAME_FIELD: '#id_templatename',
    NAMING_PREVIEW: '[data-region="naming-preview"]',
};

/** @type {Object} Dropdown commands sent to the Bootstrap dropdown plugin. */
export const DROPDOWN_COMMAND = {
    UPDATE: 'update',
    TOGGLE: 'toggle',
    HIDE: 'hide',
};
