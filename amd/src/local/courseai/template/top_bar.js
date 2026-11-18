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
 * The activities pill of the top bar of a template generation, and the panel that lists the activities.
 *
 * The pill is always in view; the list of activities opens under it on a press and goes away on a press anywhere
 * else or on Escape, so the detail is one press away and never takes space of its own. The rows of the list are
 * drawn by generation_checklist, which only needs the list to exist; this file only opens and closes it.
 *
 * @module     local_coursegen/local/courseai/template/top_bar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const TOGGLE_ID = 'tplTopActivitiesToggle';
const PANEL_ID = 'tplTopActivitiesPanel';

/**
 * Show or hide the panel and say so to a screen.
 *
 * @param {boolean} open
 */
const setOpen = (open) => {
    const toggle = document.getElementById(TOGGLE_ID);
    const panel = document.getElementById(PANEL_ID);
    if (!toggle || !panel) {
        return;
    }
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
};

/**
 * Close the panel; a panel that is already closed stays as it is.
 */
export const closeActivitiesPanel = () => {
    setOpen(false);
};

/**
 * Open the panel when it is closed and close it when it is open.
 */
export const toggleActivitiesPanel = () => {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) {
        return;
    }
    setOpen(panel.hidden);
};

/**
 * A press on the page: the pill is handled by its own button, so only a press outside the pill and the panel closes it.
 *
 * @param {Event} event
 */
const onPagePress = (event) => {
    const toggle = document.getElementById(TOGGLE_ID);
    const panel = document.getElementById(PANEL_ID);
    if (!toggle || !panel || panel.hidden) {
        return;
    }
    if (toggle.contains(event.target) || panel.contains(event.target)) {
        return;
    }
    setOpen(false);
};

/**
 * A key on the page: Escape closes an open panel and gives the focus back to the pill.
 *
 * @param {KeyboardEvent} event
 */
const onPageKey = (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    const toggle = document.getElementById(TOGGLE_ID);
    const panel = document.getElementById(PANEL_ID);
    if (!toggle || !panel || panel.hidden) {
        return;
    }
    setOpen(false);
    toggle.focus();
};

/**
 * Start the top bar: the pill opens and closes the panel, and the page closes it when it is left alone.
 */
export const initTopBar = () => {
    const toggle = document.getElementById(TOGGLE_ID);
    if (toggle) {
        toggle.addEventListener('click', toggleActivitiesPanel);
    }
    document.addEventListener('click', onPagePress);
    document.addEventListener('keydown', onPageKey);
};
