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
 * The single shared floating "pick a template" menu, anchored to whichever
 * trigger opened it. Positioned via getBoundingClientRect (fixed
 * positioning), never relative to its trigger's own table cell — a table
 * cell's positioning context is not a reliable anchor for an absolutely
 * positioned floating menu across browsers. One DOM element is created
 * lazily and reused for every open, the same pattern
 * template_scope_modal.js uses for its own shared modal.
 *
 * @module     local_coursegen/local/template/template_instance_menu
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

/** @type {HTMLElement|null} The lazily-created shared menu element. */
let menuEl = null;
/** @type {Function|null} The currently pending pick callback, if the menu is open. */
let onPick = null;

/**
 * Get (creating on first use) the shared floating menu element.
 *
 * @returns {HTMLElement}
 */
const getMenuEl = () => {
    if (!menuEl) {
        menuEl = document.createElement('div');
        menuEl.className = 'tpl-instance-menu';
        menuEl.setAttribute('role', 'menu');
        document.body.appendChild(menuEl);
        menuEl.addEventListener('click', handleItemClick);
        document.addEventListener('click', handleOutsideClick);
        document.addEventListener('keydown', handleKeydown);
    }
    return menuEl;
};

/**
 * @param {MouseEvent} e
 */
const handleItemClick = (e) => {
    const item = e.target.closest('[data-source-cmid]');
    if (!item) {
        return;
    }
    const picked = {
        sourcecmid: parseInt(item.dataset.sourceCmid, 10),
        sourcename: item.dataset.sourceName,
        typelabel: item.dataset.typeLabel,
    };
    closeInstanceMenu();
    if (onPick) {
        onPick(picked);
    }
};

/**
 * @param {MouseEvent} e
 */
const handleOutsideClick = (e) => {
    if (!menuEl || !menuEl.classList.contains('open')) {
        return;
    }
    if (!menuEl.contains(e.target) && !e.target.closest('[data-instance-menu-trigger]')) {
        closeInstanceMenu();
    }
};

/**
 * @param {KeyboardEvent} e
 */
const handleKeydown = (e) => {
    if (e.key === 'Escape') {
        closeInstanceMenu();
    }
};

/**
 * Close the menu, if open.
 */
export const closeInstanceMenu = () => {
    if (menuEl) {
        menuEl.classList.remove('open');
    }
    onPick = null;
};

/**
 * Open the shared menu, anchored below-left of the given trigger element.
 *
 * @param {Object} params
 * @param {HTMLElement} params.triggerEl The "+" button that was clicked —
 *     must carry data-instance-menu-trigger so an outside click on it (to
 *     open a DIFFERENT trigger while this menu is open) is not treated as
 *     "click outside, close" by the previous open's own listener.
 * @param {Array} params.options Menu items: {sourcecmid, name, typelabel,
 *     disabled, scopehint, tooltip}.
 * @param {Function} params.onPick ({sourcecmid, sourcename, typelabel}) => void,
 *     called once when an enabled item is chosen.
 */
export const openInstanceMenu = async({triggerEl, options, onPick: pickCallback}) => {
    const el = getMenuEl();
    const body = await Templates.render('local_coursegen/template_instance_menu', {
        hasoptions: options.length > 0,
        options,
    });
    Templates.replaceNodeContents(el, body, '');

    const rect = triggerEl.getBoundingClientRect();
    el.style.top = (rect.bottom + 6) + 'px';
    el.style.left = rect.left + 'px';
    el.classList.add('open');
    onPick = pickCallback;
};
