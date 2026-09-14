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
 * Opens/closes a "+" trigger's own template picker as a native Bootstrap
 * dropdown, positioned by Bootstrap's own bundled Popper.
 *
 * Every trigger (see template_course_sections.mustache and
 * template_course_sections_row.mustache) carries data-toggle="dropdown" and
 * is a ".dropdown" wrapper holding the trigger button next to an empty
 * ".dropdown-menu" sibling. This module only ever fills that sibling with
 * fresh content and then asks Bootstrap's own dropdown plugin to show or
 * hide it — positioning, collision handling, outside-click/Escape-to-close,
 * and aria-expanded bookkeeping are all Bootstrap's, not this plugin's.
 * template_instance_events.js opens via toggle() (not the bare show()
 * method, which defaults to skipping Popper entirely) once each click's own
 * async fetch-then-render finishes, and stops that same click from also
 * reaching Bootstrap's global data-toggle click handler — see the comment
 * there for why.
 *
 * @module     local_coursegen/local/template/template_instance_menu
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import jQuery from 'jquery';

/**
 * Render the picker body into the trigger's own dropdown-menu, then open it
 * as a native Bootstrap dropdown (content is filled in first, so Bootstrap
 * measures the real, final size when it positions the menu).
 *
 * @param {Object} params
 * @param {HTMLElement} params.triggerEl The "+" button that was clicked —
 *     must sit inside a ".dropdown" wrapper next to a ".dropdown-menu".
 * @param {Array} params.options Menu items: {sourcecmid, name, typelabel,
 *     disabled, scopehint, tooltip} (see template_instance_menu.mustache).
 */
export const openInstanceMenu = async({triggerEl, options}) => {
    const menuEl = triggerEl.closest('.dropdown').querySelector('.dropdown-menu');
    const rendered = await Templates.render('local_coursegen/template_instance_menu', {
        hasoptions: options.length > 0,
        options,
    });
    Templates.replaceNodeContents(menuEl, rendered, '');
    jQuery(triggerEl).dropdown('toggle');
};

/**
 * Close a trigger's own dropdown, if it is open — called once its pick has
 * been handled, so the menu never lingers over the row it just helped
 * insert.
 *
 * @param {HTMLElement} triggerEl The "+" button whose dropdown should close.
 */
export const closeInstanceMenu = (triggerEl) => {
    jQuery(triggerEl).dropdown('hide');
};
