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
 * Changing the behavior of a unit changes it for the subsections under it.
 *
 * A flexsections course renders each subsection as a card of its own that says
 * which section is its parent (data-parent); a subsection is part of its unit,
 * so keeping or modifying the unit does the same to everything inside it.
 *
 * @module     local_coursegen/local/template/section_cascade
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Selectors from 'local_coursegen/local/template/selectors';

/**
 * The cards of the subsections that hang directly from a section.
 *
 * @param {HTMLElement} card The section's card.
 * @returns {HTMLElement[]}
 */
const childCards = (card) => {
    const root = card.parentElement;
    if (!root || !card.dataset.number) {
        return [];
    }
    const cards = root.querySelectorAll(Selectors.rows.section + '[data-parent="' + card.dataset.number + '"]');
    return Array.from(cards);
};

/**
 * Give a section and everything under it the same behavior.
 *
 * @param {HTMLElement} card The section's card.
 * @param {string} behavior The behavior chosen.
 * @param {Object} state The live wizard state from init.js.
 */
export const cascadeBehavior = (card, behavior, state) => {
    for (const child of childCards(card)) {
        const select = child.querySelector(Selectors.regions.sectionBehaviorSelect);
        const offered = select && Array.from(select.options).some((option) => option.value === behavior);
        if (offered) {
            select.value = behavior;
            state.sectionBehavior[parseInt(select.dataset.sid)] = behavior;
        }
        cascadeBehavior(child, behavior, state);
    }
};
