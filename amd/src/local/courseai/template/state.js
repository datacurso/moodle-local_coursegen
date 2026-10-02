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
    state.sections = (data.sections || []).map((section) => ({
        id: section.id,
        name: section.name,
        behavior: section.behavior || 'aimodify',
        locked: !!section.locked,
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
            spaceinstruction: activity.spaceinstruction || '',
            spacerequired: !!activity.spacerequired,
            spacefile: null,
        })),
    }));
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
 * Whether a required space is still without its file.
 *
 * @param {Object} state
 * @returns {boolean}
 */
export const hasMissingRequiredSpace = (state) => allActivities(state)
    .some((activity) => activity.isspace && activity.spacerequired && !activity.spacefile);

/**
 * The files picked for the spaces, as the generation start takes them.
 *
 * @param {Object} state
 * @returns {Object[]} Each {cmid, draftitemid}.
 */
export const pickedSpaceFiles = (state) => allActivities(state)
    .filter((activity) => activity.isspace && activity.spacefile)
    .map((activity) => ({cmid: parseInt(activity.id, 10), draftitemid: activity.spacefile.draftitemid}));

/**
 * Toggle a section's collapsed state.
 *
 * @param {Object} state
 * @param {number} sectionIndex - The section's position in state.sections.
 */
export const toggleSectionCollapsed = (state, sectionIndex) => {
    const section = state.sections[sectionIndex];
    if (section) {
        section.collapsed = !section.collapsed;
    }
};
