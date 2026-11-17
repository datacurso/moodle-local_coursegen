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
 * The sections and activities the guideline adds after the template's own.
 *
 * The structure shown before a run is the template's. When the guideline asks for more (units, subsections,
 * activities), the service says what it added in one `template_structure` event; this module puts it in the
 * state the structure view and the counters read, grouped by section, and tells the page to draw it.
 *
 * @module     local_coursegen/local/courseai/template/added_structure
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Function|null} Redraws the page for a structure event, set by the template mode. */
let listener = null;

/** Called with each template_unit_copies event; set by template_mode.js. */
let copiesListener = null;

/**
 * Set what draws the copies of a template section.
 *
 * @param {Function|null} callback
 */
export const setCopiesListener = (callback) => {
    copiesListener = callback;
};

/**
 * Hand a template_unit_copies event to whoever draws it.
 *
 * @param {Object} data
 * @returns {Promise<void>}
 */
export const notifyCopies = async(data) => {
    if (copiesListener) {
        await copiesListener(data);
    }
};

/**
 * Choose what draws an added structure.
 *
 * @param {Function|null} callback Receives the event and returns a promise.
 */
export const setStructureListener = (callback) => {
    listener = callback;
};

/**
 * Hand an added structure to the page, if something is listening.
 *
 * @param {Object} data A template_structure event.
 * @returns {Promise<void>}
 */
export const notifyStructure = async(data) => {
    if (listener) {
        await listener(data);
    }
};

/**
 * The icon markup of the first activity of the state that has the given module, or ''.
 *
 * @param {Object} state
 * @param {string} modname
 * @returns {string}
 */
const iconFor = (state, modname) => {
    for (const section of state.sections) {
        const known = section.activities.find((activity) => activity.modname === modname && activity.iconhtml);
        if (known) {
            return known.iconhtml;
        }
    }
    return '';
};

/**
 * One added activity as the structure view reads it: written by the AI, never edited by hand.
 *
 * @param {Object} state
 * @param {Object} item {uid, name, resource_type}
 * @returns {Object}
 */
const addedActivity = (state, item) => ({
    id: item.uid,
    name: item.name || '',
    modname: item.resource_type || '',
    purpose: '',
    iconhtml: iconFor(state, item.resource_type),
    typelabel: '',
    locked: true,
    action: 'instance',
    isinstance: true,
    aigenerated: true,
    generationuid: item.uid || '',
});

/**
 * The activities of one added section, in the order the service gave them.
 *
 * @param {Object} state
 * @param {Array<Object>} items The activities of the event.
 * @param {number} number The section number.
 * @returns {Array<Object>}
 */
const activitiesOf = (state, items, number) => items
    .filter((item) => item.section === number)
    .map((item) => addedActivity(state, item));

/**
 * Put the sections and activities the service added after the template's own into the state.
 *
 * Calling it again replaces what an earlier call added. The counters of the units field no longer apply
 * once sections are added, so they stop resizing.
 *
 * @param {Object} state
 * @param {Object} data {sections: [{section, name, depth}], activities: [{uid, name, resource_type, section}]}
 */
export const mergeAddedStructure = (state, data) => {
    const sections = Array.isArray(data?.sections) ? data.sections : [];
    const items = Array.isArray(data?.activities) ? data.activities : [];
    const own = state.sections.filter((section) => !section.added);
    const added = sections.map((section) => ({
        id: section.section,
        name: section.name || '',
        behavior: 'aimodify',
        locked: false,
        depth: Number(section.depth) || 0,
        collapsed: false,
        added: true,
        activities: activitiesOf(state, items, section.section),
    }));
    state.sections = [...own, ...added];
    state.addedSections = added.length > 0;
};

/**
 * Put the copies of a template section right after the section they repeat, and after the copies before them.
 *
 * The section a copy repeats is found by its number in the template; a copy that repeats a section the page
 * does not show is left out.
 *
 * @param {Object} state The structure state.
 * @param {Object} data A template_unit_copies event:
 *     {renamed, renamed_activities, sections: [{section, name, copy_of, depth}], activities}.
 */
export const mergeUnitCopies = (state, data) => {
    const sections = Array.isArray(data?.sections) ? data.sections : [];
    const items = Array.isArray(data?.activities) ? data.activities : [];
    // The first copy is the template section itself: it takes the name of its unit.
    for (const renamed of Array.isArray(data?.renamed) ? data.renamed : []) {
        const first = state.sections.find((section) => section.num === Number(renamed.copy_of));
        if (first && renamed.name) {
            first.name = renamed.name;
        }
    }
    // Its activities keep their uids; their names lose the markers of the template.
    const names = new Map((Array.isArray(data?.renamed_activities) ? data.renamed_activities : [])
        .map((item) => [String(item.uid), item.name]));
    state.sections.forEach((section) => section.activities.forEach((activity) => {
        const name = names.get(String(activity.generationuid || activity.id));
        if (name) {
            activity.name = name;
        }
    }));
    for (const copy of sections) {
        const source = Number(copy.copy_of);
        let last = -1;
        state.sections.forEach((section, index) => {
            if (section.num === source || section.copyOf === source) {
                last = index;
            }
        });
        if (last < 0) {
            continue;
        }
        state.sections.splice(last + 1, 0, {
            id: `copy-${copy.section}`,
            num: null,
            copyOf: source,
            name: copy.name || '',
            behavior: 'aimodify',
            locked: false,
            depth: Number(copy.depth) || 0,
            collapsed: false,
            copied: true,
            activities: activitiesOf(state, items, copy.section),
        });
    }
};
