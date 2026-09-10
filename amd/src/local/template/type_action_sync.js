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
 * Sensible default action per activity TYPE, applied automatically when a
 * course's structure loads.
 *
 * A real template repeats the same handful of component types over and over
 * (a welcome/section banner, an informational page, a discussion forum, a
 * file attachment, a graded activity, a closing survey, a multi-page
 * lesson) — dozens of times across a real course. Reviewing every single
 * activity one by one from a neutral starting point does not scale (a real
 * template reviewed this session has ~28 activities), so each activity is
 * seeded with the sensible default for its own type the moment the course
 * structure loads; the admin only has to touch the rare exception via the
 * per-activity dropdown in the section/activity review below.
 *
 * This module previously also rendered a bulk "change every activity of
 * this type at once" panel (a real mform in
 * classes/form/template_config_form.php, bound here) — removed per explicit
 * client feedback: they didn't want it as a visible, separate control. Only
 * the automatic per-activity seeding stays; the per-activity dropdown below
 * remains the only place to change anything.
 *
 * @module     local_coursegen/local/template/type_action_sync
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Every module type the real AI service has a content contract for — every
 * one of these must offer "Modify", regardless of whether the
 * implementation currently answering generate() has caught up to all of
 * them yet.
 *
 * Mirrors template_content_generator::AI_SUPPORTED_TYPES (PHP) — kept in
 * sync manually since a JS module cannot read a PHP class constant
 * directly. This copy matters for seeding each activity's initial
 * per-activity action as soon as a course's structure loads, before the
 * config form has necessarily rendered yet (see applyTypeDefaultsToState,
 * called from init.js::initSectionState).
 *
 * @type {string[]}
 */
const AI_SUPPORTED = [
    'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum',
    'glossary', 'h5pactivity', 'imscp', 'label', 'lesson', 'page', 'quiz',
    'resource', 'scorm', 'url', 'wiki', 'workshop',
];

/**
 * Sensible default action per recognised component type — mirrors
 * template_config_form::TYPE_META (PHP).
 *
 * @type {Object<string, string>}
 */
const DEFAULT_ACTION = {
    label: 'modify',
    page: 'modify',
    forum: 'keep',
    resource: 'keep',
    assign: 'modify',
    feedback: 'keep',
    lesson: 'keep',
};

/**
 * @param {string} modname
 * @returns {boolean} Whether "Modify" is a safe option for this module type today.
 */
export const typeSupportsModify = (modname) => AI_SUPPORTED.includes(modname);

/**
 * @param {string} modname
 * @returns {string} The sensible default action for this module type.
 */
export const defaultActionForModname = (modname) => {
    const wanted = DEFAULT_ACTION[modname] || 'keep';
    // Never default a type the generator can't handle to "modify" — even if
    // DEFAULT_ACTION said so, an unsupported type must default to "keep".
    return (wanted === 'modify' && !typeSupportsModify(modname)) ? 'keep' : wanted;
};

/**
 * Seed state.activityAction with the per-type default for every activity
 * that doesn't already have an explicit value — never overwrites a value
 * the admin (or a previous render) already set.
 *
 * @param {HTMLElement} container The rendered course structure, to read real modnames from.
 * @param {Object} state
 */
export const applyTypeDefaultsToState = (container, state) => {
    container.querySelectorAll('[data-for="cmitem"][data-modname]').forEach(cmitem => {
        const cmid = parseInt(cmitem.dataset.id);
        const modname = cmitem.dataset.modname;
        if (!cmid || state.activityAction[cmid] !== undefined) {
            return;
        }
        state.activityAction[cmid] = defaultActionForModname(modname);
        state.activityRef[cmid] = true;
    });
};
