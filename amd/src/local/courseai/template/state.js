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
 * In-memory data model for the template-mode guided form.
 *
 * The server (get_template_structure) is the source of truth for the template's
 * sections and activities. The only thing the professor adds is the file of a
 * space (a file resource the template asks a file for), kept on the row until
 * the generation starts. Mutating this object and calling renderStructure()
 * again is the only way the view changes: there is no hand-built DOM anywhere
 * (see local/template/render.js).
 *
 * @module     local_coursegen/local/courseai/template/state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build a fresh, empty state object.
 *
 * The input-bar fields (generateimages, lang, syllabus draft file) belong to
 * the professor's session, not to the picked template: pass the current values
 * as `inputbar` so clearing/re-picking a template preserves them.
 *
 * @param {Object} [inputbar] - Optional input-bar values to carry over.
 * @param {string} [inputbar.prompt] - Adaptation prompt typed by the professor.
 * @param {number} [inputbar.generateimages] - 1 to generate images, 0 otherwise.
 * @param {string} [inputbar.lang] - Course language code.
 * @param {number} [inputbar.syllabusdraftitemid] - Draft area of the attached syllabus.
 * @param {string} [inputbar.syllabusfilename] - Attached syllabus filename.
 * @returns {Object}
 */
export const createTemplateState = (inputbar = {}) => ({
    loaded: false,
    nolimit: false,
    remainingSections: 0,
    sections: [],
    typeLabels: {},
    // Units of the template and the number the professor wants, only for a format that has units (0 = none).
    unitcount: 0,
    maxunits: 0,
    units: 0,
    // Input-bar values, ready for the future generation payload.
    prompt: inputbar.prompt || '',
    generateimages: inputbar.generateimages || 0,
    lang: inputbar.lang || '',
    syllabusdraftitemid: inputbar.syllabusdraftitemid || 0,
    syllabusfilename: inputbar.syllabusfilename || '',
});

/**
 * Populate state from a get_template_structure response.
 *
 * @param {Object} state
 * @param {Object} data
 */
export const applyStructureResponse = (state, data) => {
    state.loaded = true;
    state.nolimit = !!data.nolimit;
    state.remainingSections = data.remainingsections || 0;
    state.unitcount = data.unitcount || 0;
    state.maxunits = data.maxunits || 0;
    state.units = state.unitcount;
    state.sections = (data.sections || []).map((section) => ({
        id: section.id,
        name: section.name,
        behavior: section.behavior || 'aimodify',
        locked: !!section.locked,
        // Where a section hangs, for a course whose format has subsections.
        depth: section.depth || 0,
        collapsed: false,
        activities: (section.activities || []).map((activity) => ({
            id: activity.id,
            name: activity.name,
            modname: activity.modname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
            // Instance rows carry their snapshotted type label; real rows
            // resolve theirs client-side (render.js ensureTypeLabels).
            typelabel: activity.typelabel || '',
            locked: !!activity.locked,
            action: activity.action || '',
            // Virtual instance rows ("AI will generate an activity here,
            // molded on a template activity") arrive with the instance's own
            // uid as their id (see get_template_structure.php), locked and
            // non-removable.
            isinstance: !!activity.isinstance,
            aigenerated: !!activity.aigenerated,
            // The id this row answers to in the generation's progress events,
            // and in its own preview link — the same uid the payload sent
            // and the run's events/answer echo back.
            generationuid: activity.generationuid || '',
        })),
    }));
};

/**
 * Set the number of units the professor wants, kept between 1 and the maximum.
 *
 * A value that is not a number goes back to the template's own count.
 *
 * @param {Object} state
 * @param {string|number} raw What the field holds.
 */
export const setUnits = (state, raw) => {
    const value = parseInt(raw, 10);
    if (Number.isNaN(value)) {
        state.units = state.unitcount;
        return;
    }
    state.units = Math.min(state.maxunits, Math.max(1, value));
};

/**
 * The number of units to ask the server for, null when the template's own count stands.
 *
 * @param {Object} state
 * @returns {number|null}
 */
export const requestedUnits = (state) => {
    if (!state.unitcount || state.units === state.unitcount) {
        return null;
    }
    return state.units;
};

/**
 * The end of the subtree that starts at a section: the index after its last deeper section.
 *
 * @param {Object[]} sections
 * @param {number} start
 * @returns {number}
 */
const subtreeEnd = (sections, start) => {
    let end = start + 1;
    while (end < sections.length && sections[end].depth > sections[start].depth) {
        end++;
    }
    return end;
};

/**
 * How many sections and activities the course will have for the number of units asked.
 *
 * Mirrors the server resize: a unit is a top section with subsections; more units copy the last unit
 * (its subsections and the activities the AI writes in them), fewer drop whole units from the end with
 * everything inside them.
 *
 * @param {Object} state
 * @returns {{sections: number, activities: number}}
 */
export const countsForUnits = (state) => {
    const sections = state.sections;
    const total = (list, only) => list.reduce((sum, section) => sum + section.activities.filter(only).length, 0);
    const any = () => true;
    const counts = {sections: sections.length, activities: total(sections, any)};

    const units = [];
    sections.forEach((section, index) => {
        if (section.depth === 0 && index + 1 < sections.length && sections[index + 1].depth > 0) {
            units.push(index);
        }
    });
    if (!state.unitcount || !units.length || state.units === units.length) {
        return counts;
    }

    if (state.units < units.length) {
        units.slice(state.units).forEach((start) => {
            const dropped = sections.slice(start, subtreeEnd(sections, start));
            counts.sections -= dropped.length;
            counts.activities -= total(dropped, any);
        });
        return counts;
    }

    const last = sections.slice(units[units.length - 1], subtreeEnd(sections, units[units.length - 1]));
    const copies = state.units - units.length;
    counts.sections += copies * last.length;
    counts.activities += copies * total(last, (activity) => activity.isinstance);
    return counts;
};

/**
 * Toggle a section's collapsed state.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 */
export const toggleSectionCollapsed = (state, sectionIndex) => {
    const section = state.sections[sectionIndex];
    if (!section) {
        return;
    }
    section.collapsed = !section.collapsed;
    // The subsections that follow a unit and are deeper than it are part of it, so they open and close with it.
    for (let next = sectionIndex + 1; next < state.sections.length && state.sections[next].depth > section.depth; next++) {
        state.sections[next].collapsed = section.collapsed;
    }
};
