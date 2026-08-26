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
 * Activity-type chooser modal (#tplActivityChooserModal) for the template-mode
 * guided form. Limited to the template's admin-allowed activity types
 * (allowedactivities from get_template_structure). Functionally unchanged from
 * the previous implementation — search filtering, click-to-add — only the
 * markup now comes from local_coursegen/template_activity_chooser (server
 * rendered) instead of being built with document.createElement.
 *
 * @module     local_coursegen/local/courseai/template/chooser
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import jQuery from 'jquery';
import Selectors from './selectors';

const MODAL_SELECTOR = '#tplActivityChooserModal';
const GRID_ID = 'tplChooserGrid';
const SEARCH_ID = 'tplChooserSearch';

// The section/position the chooser is currently adding into. Set by
// openActivityChooser, consumed (and cleared) by the grid's click handler.
let pendingTarget = null;

/**
 * Render the chooser grid for the given allowed-activity catalog. Content is
 * static per loaded template, so this only needs to run again when a NEW
 * template is selected — not on every open.
 *
 * @param {Array} allowedActivities - [{modname, displayname, purpose, iconhtml}]
 * @returns {Promise<void>}
 */
export const renderChooserGrid = async(allowedActivities) => {
    const grid = document.getElementById(GRID_ID);
    if (!grid) {
        return;
    }
    const context = {
        hasactivities: !!(allowedActivities && allowedActivities.length),
        activities: (allowedActivities || []).map((activity) => ({
            modname: activity.modname,
            displayname: activity.displayname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
            searchkey: (activity.displayname || '').toLowerCase(),
        })),
    };
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_activity_chooser', context);
    Templates.replaceNodeContents(grid, html, js);
};

/**
 * Open the chooser modal targeting a given section/position.
 *
 * @param {number} sectionId
 * @param {number|null} position - 0-based insert index, or null to append.
 */
export const openActivityChooser = (sectionId, position) => {
    pendingTarget = {sectionId, position};
    const search = document.getElementById(SEARCH_ID);
    if (search) {
        search.value = '';
        filterChooserGrid('');
    }
    jQuery(MODAL_SELECTOR).modal('show');
};

/**
 * Filter the chooser grid by the search query (client-side, no re-render).
 *
 * @param {string} query
 */
const filterChooserGrid = (query) => {
    const q = query.trim().toLowerCase();
    document.querySelectorAll(`#${GRID_ID} .option`).forEach((card) => {
        card.style.display = !q || card.dataset.search.includes(q) ? '' : 'none';
    });
};

/**
 * Wire the chooser modal's search input and option clicks. Called once — the
 * grid's contents are replaced (never the grid element itself), so delegation
 * on the grid keeps working after renderChooserGrid re-renders it.
 *
 * @param {Function} onPick - (sectionId, position, activity) => void
 */
export const wireChooserModal = (onPick) => {
    const search = document.getElementById(SEARCH_ID);
    if (search) {
        search.addEventListener('input', () => filterChooserGrid(search.value));
    }

    const grid = document.getElementById(GRID_ID);
    if (!grid) {
        return;
    }
    grid.addEventListener('click', (event) => {
        const optionEl = event.target.closest(Selectors.actions.chooserOption);
        if (!optionEl || !pendingTarget) {
            return;
        }
        event.preventDefault();
        const modname = optionEl.dataset.modname;
        onPick(pendingTarget.sectionId, pendingTarget.position, modname);
        pendingTarget = null;
        jQuery(MODAL_SELECTOR).modal('hide');
    });
};
