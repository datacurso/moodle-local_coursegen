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
 * How the copies of a template section are named: its pattern and the number of the first copy.
 *
 * The fields are drawn in the section's card (template_course_sections.mustache) and show only while the
 * section's behavior is "Template section"; the template's save reads them (see save_payload.js).
 *
 * @module     local_coursegen/local/template/section_naming
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The behavior value of a template section. */
export const TEMPLATE_BEHAVIOR = 'template';

/**
 * The naming fields of a section.
 *
 * @param {HTMLElement|Document} root
 * @param {number} sid
 * @returns {HTMLElement|null}
 */
const regionOf = (root, sid) => root.querySelector(`[data-region="section-naming"][data-sid="${sid}"]`);

/**
 * Show the naming fields of a section only while it is a template section.
 *
 * @param {HTMLElement|Document} root
 * @param {number} sid
 * @param {string} behavior
 */
export const showNamingFor = (root, sid, behavior) => {
    const region = regionOf(root, sid);
    if (region) {
        region.hidden = behavior !== TEMPLATE_BEHAVIOR;
    }
};

/**
 * Show the custom text only when the pattern select is set to "Custom".
 *
 * @param {HTMLElement} region
 */
const syncCustom = (region) => {
    const select = region.querySelector('[data-region="section-naming-pattern"]');
    const custom = region.querySelector('[data-region="section-naming-custom"]');
    if (select && custom) {
        custom.hidden = select.value !== region.dataset.custom;
    }
};

/**
 * Keep the custom text of every section's naming fields in step with its select.
 *
 * @param {HTMLElement} container The rendered course sections review.
 * @param {Function} markDirty Called when a field changes.
 */
export const bindSectionNaming = (container, markDirty) => {
    container.querySelectorAll('[data-region="section-naming"]').forEach((region) => {
        region.addEventListener('change', () => {
            syncCustom(region);
            markDirty();
        });
        region.addEventListener('input', markDirty);
    });
};

/**
 * The naming of a section, as the save sends it.
 *
 * @param {HTMLElement} root
 * @param {number} sid
 * @returns {{namingpattern: string, namingstart: number}}
 */
export const namingOf = (root, sid) => {
    const region = regionOf(root, sid);
    if (!region) {
        return {namingpattern: '', namingstart: 1};
    }
    const select = region.querySelector('[data-region="section-naming-pattern"]');
    const custom = region.querySelector('[data-region="section-naming-custom"]');
    const start = region.querySelector('[data-region="section-naming-start"]');
    const pattern = select?.value === region.dataset.custom ? (custom?.value || '').trim() : (select?.value || '');
    return {namingpattern: pattern, namingstart: parseInt(start?.value || '1', 10) || 0};
};
