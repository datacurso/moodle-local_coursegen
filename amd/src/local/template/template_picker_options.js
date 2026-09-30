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
 * The options of the template picker: every activity currently marked "Use
 * as template" on the page, enabled or disabled depending on whether its
 * scope makes it eligible for the section the menu was opened from.
 *
 * Computed entirely client-side, from the rows already on the page plus
 * state.activityScope — an unsaved "Use as template" row picked earlier in
 * this same editing session is immediately offerable, with no server round
 * trip.
 *
 * @module     local_coursegen/local/template/template_picker_options
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

/**
 * Build one marked row's own picker option, or null if the row is missing
 * something it needs (no cmid, or no section ancestor to check eligibility
 * against) — a single malformed row must never take the whole list down.
 *
 * @param {HTMLElement} row A row carrying the "tpl-row-template" class.
 * @param {number} targetsectionid The section the "+" was triggered from.
 * @param {Object} state The live wizard state from init.js.
 * @param {Object} hints {samesectionhint, coursehint, tooltip} pre-fetched strings.
 * @returns {Object|null}
 */
const buildOneOption = (row, targetsectionid, state, hints) => {
    const cmid = parseInt(row.dataset.id, 10);
    const sectionEl = row.closest('[data-for="section"]');
    if (!cmid || !sectionEl) {
        return null;
    }
    const sectionid = parseInt(sectionEl.dataset.id, 10);
    const scope = state.activityScope[cmid] || 'course';
    const eligible = scope === 'course' || sectionid === targetsectionid;

    let scopehint = hints.samesectionhint;
    let itemtooltip = '';
    if (eligible) {
        if (scope === 'course') {
            scopehint = hints.coursehint;
        }
    } else {
        itemtooltip = hints.tooltip;
    }

    return {
        sourcecmid: cmid,
        name: row.querySelector('.tpl-template-tag')?.dataset.name || '',
        typelabel: row.dataset.typelabel || '',
        modname: row.dataset.modname || '',
        iconurl: row.querySelector('img.activityicon')?.src || '',
        disabled: !eligible,
        scopehint,
        tooltip: itemtooltip,
    };
};

/**
 * Build the picker's option list for one target section: every row
 * currently marked "Use as template" anywhere in the review, enabled when
 * its scope makes it eligible for this section.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {number} targetsectionid The section the "+" was triggered from.
 * @param {Object} state The live wizard state from init.js.
 * @returns {Promise<Array>} Menu options (see template_instance_menu.mustache).
 */
export const buildAvailableTemplates = async(container, targetsectionid, state) => {
    const [samesectionhint, coursehint, tooltip] = await getStrings([
        {key: 'template_instance_scope_same_section', component: 'local_coursegen'},
        {key: 'template_instance_scope_whole_course', component: 'local_coursegen'},
        {key: 'template_instance_scope_unavailable', component: 'local_coursegen'},
    ]);
    const hints = {samesectionhint, coursehint, tooltip};

    const templaterows = container.querySelectorAll('.tpl-row-template[data-for="cmitem"]');
    const options = [];
    for (const row of templaterows) {
        const option = buildOneOption(row, targetsectionid, state, hints);
        if (option !== null) {
            options.push(option);
        }
    }
    return options;
};

