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
 * @param {number} [inputbar.guidelineid] - Institutional guideline (system instruction) id, 0 for none.
 * @returns {Object}
 */
export const createTemplateState = (inputbar = {}) => ({
    loaded: false,
    nolimit: false,
    remainingSections: 0,
    sections: [],
    // True once the service added sections after the template's own (see added_structure.js).
    addedSections: false,
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
    guidelineid: inputbar.guidelineid || 0,
});

/**
 * The numeric id of a guideline, from the id the page lists it under ('si_12') or a number.
 *
 * @param {string|number|null} value
 * @returns {number} 0 when there is no usable id.
 */
export const guidelineIdOf = (value) => {
    const match = String(value ?? '').match(/^(?:si_)?(\d+)$/);
    return match ? parseInt(match[1], 10) : 0;
};

/**
 * Choose the guideline of the run, replacing any previous one; null clears it.
 *
 * @param {Object} state
 * @param {string|number|null} value
 */
export const setGuideline = (state, value) => {
    state.guidelineid = guidelineIdOf(value);
};

/**
 * Populate state from a get_template_structure response.
 *
 * @param {Object} state
 * @param {Object} data
 */
export const applyStructureResponse = (state, data) => {
    state.loaded = true;
    state.addedSections = false;
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
            // A space is a file resource the teacher brings a file for;
            // spacefile is that file once picked: {draftitemid, filename}.
            isspace: !!activity.isspace,
            // "file": a file resource the teacher brings a file for; "activity": a place of the template the teacher
            // fills with one of their own activities, kept in spaceactivity once chosen: {cmid, name, coursename}.
            spacekind: activity.spacekind || (activity.isspace ? 'file' : ''),
            spaceinstruction: activity.spaceinstruction || '',
            spacerequired: !!activity.spacerequired,
            // The course module id the file of the space goes under: the resource's own, or the placeholder of a place
            // where the teacher uploads a file of their own.
            spacecmid: parseInt(activity.spacecmid || activity.id, 10) || 0,
            spacefile: null,
            spaceactivity: null,
            // "choice": a place where the teacher adds one of the activities the admin allowed; spaceoptions lists them
            // ({modname, title, name, mode, instruction, iconhtml}) and spacechoice is the one added: {modname, title,
            // instruction, mode}. A File of mode "teacher" is one the teacher uploads: its file is kept in spacefile.
            spaceoptions: parseOptions(activity.spaceoptions),
            spacechoice: null,
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
    if (state.addedSections || !state.unitcount || !units.length || state.units === units.length) {
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
 * The activity rows of every section, in order.
 *
 * @param {Object} state
 * @returns {Object[]}
 */
const allActivities = (state) => state.sections.flatMap((section) => section.activities);

/**
 * The space row at a position, or null when the position holds none.
 *
 * @param {Object} state
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @returns {Object|null}
 */
const findSpace = (state, sectionIndex, activityIndex) => {
    const section = state.sections[sectionIndex];
    if (!section) {
        return null;
    }
    const activity = section.activities[activityIndex];
    if (!activity || !activity.isspace) {
        return null;
    }
    return activity;
};

/**
 * Set the file of one space row.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 * @param {number} activityIndex - The row's position in its section.
 * @param {string} filename
 * @param {number} draftitemid - Draft area holding the picked file.
 * @returns {boolean} Whether the row is a space and took the file.
 */
export const setSpaceFile = (state, sectionIndex, activityIndex, filename, draftitemid) => {
    const activity = findSpace(state, sectionIndex, activityIndex);
    if (!activity) {
        return false;
    }
    activity.spacefile = {draftitemid, filename};
    return true;
};

/**
 * Empty the file of one space row.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 * @param {number} activityIndex - The row's position in its section.
 * @returns {boolean} Whether the row is a space.
 */
export const clearSpaceFile = (state, sectionIndex, activityIndex) => {
    const activity = findSpace(state, sectionIndex, activityIndex);
    if (!activity) {
        return false;
    }
    activity.spacefile = null;
    return true;
};

/**
 * Set the activity the teacher brings to one space row.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 * @param {number} activityIndex - The row's position in its section.
 * @param {Object} chosen - {cmid, name, coursename}.
 * @returns {boolean} Whether the row is a space for an activity and took it.
 */
export const setSpaceActivity = (state, sectionIndex, activityIndex, chosen) => {
    const activity = findSpace(state, sectionIndex, activityIndex);
    if (!activity || activity.spacekind !== 'activity') {
        return false;
    }
    activity.spaceactivity = {cmid: chosen.cmid, name: chosen.name, coursename: chosen.coursename};
    return true;
};

/**
 * Empty the activity of one space row.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 * @param {number} activityIndex - The row's position in its section.
 * @returns {boolean} Whether the row is a space.
 */
export const clearSpaceActivity = (state, sectionIndex, activityIndex) => {
    const activity = findSpace(state, sectionIndex, activityIndex);
    if (!activity) {
        return false;
    }
    activity.spaceactivity = null;
    return true;
};

/**
 * Whether a space row already has what the teacher brings: its file or its activity.
 *
 * @param {Object} activity
 * @returns {boolean}
 */
export const isSpaceFilled = (activity) => {
    if (activity.spacechoice) {
        // The File the teacher picked to upload counts once its file is there.
        return activity.spacechoice.mode !== 'teacher' || !!activity.spacefile;
    }
    return !!(activity.spacefile || activity.spaceactivity);
};

/**
 * The activities a space offers, from the JSON the structure sent.
 *
 * @param {string} json
 * @returns {Object[]}
 */
const parseOptions = (json) => {
    try {
        const parsed = JSON.parse(json || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
};

/**
 * Set the activity the teacher adds in a space.
 *
 * @param {Object} state
 * @param {number} sectionIndex
 * @param {number} activityIndex
 * @param {Object|null} choice {modname, title, instruction}, or null to empty the space.
 * @returns {boolean} Whether the row is a space of that kind.
 */
export const setSpaceChoice = (state, sectionIndex, activityIndex, choice) => {
    const activity = state.sections[sectionIndex]?.activities[activityIndex];
    if (!activity || activity.spacekind !== 'choice') {
        return false;
    }
    activity.spacechoice = choice;
    return true;
};

/**
 * The activities the teacher added in the spaces, as the generation start takes them.
 *
 * @param {Object} state
 * @returns {Object[]} Each {uid, modname, instruction}.
 */
export const pickedSpaceChoices = (state) => allActivities(state)
    .filter((activity) => activity.spacekind === 'choice' && activity.spacechoice)
    .map((activity) => ({
        uid: activity.id,
        modname: activity.spacechoice.modname,
        instruction: activity.spacechoice.instruction || '',
    }));

/**
 * Whether a required space is still without its file or its activity.
 *
 * @param {Object} state
 * @returns {boolean}
 */
export const hasMissingRequiredSpace = (state) => allActivities(state)
    .some((activity) => activity.isspace && activity.spacerequired && !isSpaceFilled(activity));

/**
 * The files picked for the spaces, as the generation start takes them.
 *
 * @param {Object} state
 * @returns {Object[]} Each {cmid, draftitemid}.
 */
export const pickedSpaceFiles = (state) => allActivities(state)
    .filter((activity) => activity.isspace && activity.spacekind !== 'activity' && activity.spacefile)
    .map((activity) => ({cmid: activity.spacecmid, draftitemid: activity.spacefile.draftitemid}));

/**
 * The activities chosen for the spaces, as the generation start takes them.
 *
 * @param {Object} state
 * @returns {Object[]} Each {uid, cmid}.
 */
export const pickedSpaceActivities = (state) => allActivities(state)
    .filter((activity) => activity.isspace && activity.spacekind === 'activity' && activity.spaceactivity)
    .map((activity) => ({uid: String(activity.id), cmid: activity.spaceactivity.cmid}));

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
