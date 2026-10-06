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
 * Server-rendered structure view for the template-mode guided form.
 *
 * Builds a plain-data Mustache context from the in-memory state (state.js) and
 * asks core/templates to render local_coursegen/template_structure — the ONLY
 * place that produces this view's HTML. This module never touches innerHTML
 * with hand-built markup; it only calls Templates.replaceNodeContents with the
 * server-rendered result and reads data-* attributes off delegated click events.
 *
 * @module     local_coursegen/local/courseai/template/render
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {getStrings} from 'core/str';
import Selectors from './selectors';

/**
 * Resolve a human-readable label per distinct modname present in the state
 * (the activities of the template), used as the small grey subtitle under each
 * activity's title (Image 1's "description" line). Cached on state.typeLabels
 * so this only round-trips once per template load, not on every re-render.
 *
 * @param {Object} state
 * @returns {Promise<void>}
 */
const ensureTypeLabels = async(state) => {
    const modnames = [...new Set(
        state.sections.flatMap((section) => section.activities
            // Instance rows carry their own snapshotted label (and their
            // modname snapshot may even be empty — never ask core/str for
            // "mod_"), so only real rows without a label round-trip here.
            .filter((a) => a.modname && !a.typelabel)
            .map((a) => a.modname))
    )].filter((modname) => !(modname in state.typeLabels));

    if (!modnames.length) {
        return;
    }

    const labels = await getStrings(modnames.map((modname) => ({key: 'pluginname', component: 'mod_' + modname})));
    modnames.forEach((modname, index) => {
        state.typeLabels[modname] = labels[index];
    });
};

/**
 * The Mustache fields of one activity row.
 *
 * @param {Object} activity
 * @param {Object} state
 * @param {number} sectionindex
 * @param {number} index
 * @returns {Object}
 */
const buildActivityContext = (activity, state, sectionindex, index) => ({
    name: activity.name,
    modname: activity.modname,
    purpose: activity.purpose,
    iconhtml: activity.iconhtml,
    locked: activity.locked,
    isinstance: !!activity.isinstance,
    aigenerated: !!activity.aigenerated,
    // Only an AI-generated row ever receives progress events, so the
    // attribute is omitted entirely on the rest rather than rendered
    // as a meaningless empty one.
    generationuid: activity.generationuid || '',
    sectionindex,
    index,
    typelabel: activity.typelabel || state.typeLabels[activity.modname] || '',
});

/**
 * Build the Mustache context for local_coursegen/template_structure from state.
 *
 * @param {Object} state
 * @returns {Object}
 */
const buildContext = (state) => ({
    sections: state.sections.map((section, sectionindex) => ({
        // Every place a click needs to find its way back to this section
        // (collapse toggle, space file) addresses it by this render-time
        // position, never by section.id.
        index: sectionindex,
        name: section.name,
        locked: section.locked,
        collapsed: !!section.collapsed,
        activitiescount: section.activities.length,
        activities: section.activities.map((activity, index) => buildActivityContext(activity, state, sectionindex, index)),
    })),
});

/**
 * Render (or re-render) the structure into the container.
 * Re-renders always replace the container's contents — delegated listeners on
 * the container itself (wired once by wireStructureEvents) survive every
 * re-render, so nothing needs to be re-wired here.
 *
 * @param {HTMLElement} container
 * @param {Object} state
 * @returns {Promise<void>}
 */
export const renderStructure = async(container, state) => {
    if (!container) {
        return;
    }
    await ensureTypeLabels(state);
    const context = buildContext(state);
    const {html, js} = await Templates.renderForPromise('local_coursegen/template_structure', context);
    Templates.replaceNodeContents(container, html, js);
};

/**
 * Wire delegated click handling on the structure container. Called ONCE per
 * page load — the container node itself is never replaced (only its children,
 * by renderStructure), so this delegation keeps working across every re-render.
 *
 * @param {HTMLElement} container
 * @param {Object} handlers
 * @param {Function} handlers.onToggleSection - (sectionIndex) => void
 */
export const wireStructureEvents = (container, handlers) => {
    if (!container) {
        return;
    }

    container.addEventListener('click', (event) => {
        const toggleEl = event.target.closest(Selectors.actions.toggleSection);
        if (toggleEl) {
            event.preventDefault();
            handlers.onToggleSection(parseInt(toggleEl.dataset.sectionIndex, 10));
        }
    });
};
