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
 * locked reference sections/activities. Everything the professor adds afterwards
 * (new sections, new activities picked from the chooser) only exists in this
 * client-side model until the (not-yet-built) course-creation step consumes it;
 * that endpoint does not exist yet and is out of scope for this module.
 * Mutating this object and calling renderStructure() again is the only way the
 * view changes: there is no hand-built DOM anywhere (see local/template/render.js).
 *
 * @module     local_coursegen/local/courseai/template/state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build a fresh, empty state object.
 *
 * @returns {Object}
 */
export const createTemplateState = () => ({
    loaded: false,
    nolimit: false,
    remainingSections: 0,
    allowedActivities: [],
    sections: [],
    typeLabels: {},
    // Client-only placeholder ids for sections/activities the professor adds —
    // negative so they never collide with real Moodle section/cm ids.
    nextSectionId: -1,
    nextActivityId: -1,
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
    state.allowedActivities = data.allowedactivities || [];
    state.sections = (data.sections || []).map((section) => ({
        id: section.id,
        name: section.name,
        locked: !!section.locked,
        collapsed: false,
        activities: (section.activities || []).map((activity) => ({
            id: activity.id,
            name: activity.name,
            modname: activity.modname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
            locked: !!activity.locked,
        })),
    }));
};

/**
 * Whether a new section can still be added given the template's section limit.
 *
 * @param {Object} state
 * @returns {boolean}
 */
export const canAddSection = (state) => state.nolimit || state.remainingSections > 0;

/**
 * Append a new, empty, unlocked section.
 *
 * @param {Object} state
 * @param {string} sectionLabel - Localised generic label (e.g. "Section"), numbered by position.
 * @returns {Object|null} The created section, or null if the limit was reached.
 */
export const addSection = (state, sectionLabel) => {
    if (!canAddSection(state)) {
        return null;
    }
    const section = {
        id: state.nextSectionId--,
        name: `${sectionLabel || 'Section'} ${state.sections.length + 1}`,
        locked: false,
        collapsed: false,
        activities: [],
    };
    state.sections.push(section);
    if (!state.nolimit) {
        state.remainingSections -= 1;
    }
    return section;
};

/**
 * Insert an activity (picked from the chooser) into a section's activity list.
 *
 * @param {Object} state
 * @param {number} sectionId
 * @param {number|null} position - 0-based index to insert BEFORE, or null/undefined to append.
 * @param {Object} activity - {modname, displayname, purpose, iconhtml}
 * @returns {boolean} Whether the insertion happened.
 */
export const insertActivity = (state, sectionId, position, activity) => {
    const section = state.sections.find((s) => s.id === sectionId);
    if (!section || section.locked) {
        return false;
    }
    const newActivity = {
        id: state.nextActivityId--,
        name: activity.displayname,
        modname: activity.modname,
        purpose: activity.purpose,
        iconhtml: activity.iconhtml,
        locked: false,
    };
    const hasPosition = typeof position === 'number' && position >= 0 && position <= section.activities.length;
    if (hasPosition) {
        section.activities.splice(position, 0, newActivity);
    } else {
        section.activities.push(newActivity);
    }
    return true;
};

/**
 * Remove one (unlocked) activity from a section by its current render index.
 *
 * @param {Object} state
 * @param {number} sectionId
 * @param {number} activityIndex
 * @returns {boolean} Whether a row was removed.
 */
export const removeActivity = (state, sectionId, activityIndex) => {
    const section = state.sections.find((s) => s.id === sectionId);
    if (!section) {
        return false;
    }
    const activity = section.activities[activityIndex];
    if (!activity || activity.locked) {
        return false;
    }
    section.activities.splice(activityIndex, 1);
    return true;
};

/**
 * Toggle a section's collapsed state.
 *
 * @param {Object} state
 * @param {number} sectionId
 */
export const toggleSectionCollapsed = (state, sectionId) => {
    const section = state.sections.find((s) => s.id === sectionId);
    if (section) {
        section.collapsed = !section.collapsed;
    }
};
