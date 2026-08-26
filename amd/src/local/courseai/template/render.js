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
import {canAddSection} from './state';

/**
 * Resolve a human-readable label per distinct modname present in the state
 * (locked sections' activities), used as the small grey subtitle under each
 * activity's title (Image 1's "description" line). Cached on state.typeLabels
 * so this only round-trips once per template load, not on every re-render.
 *
 * @param {Object} state
 * @returns {Promise<void>}
 */
const ensureTypeLabels = async(state) => {
    const modnames = [...new Set(
        state.sections.flatMap((section) => section.activities.map((a) => a.modname))
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
 * Build the Mustache context for local_coursegen/template_structure from state.
 *
 * @param {Object} state
 * @param {Object} labels - {addSection}
 * @returns {Object}
 */
const buildContext = (state, labels) => ({
    sections: state.sections.map((section) => ({
        id: section.id,
        name: section.name,
        locked: section.locked,
        collapsed: !!section.collapsed,
        activitiescount: section.activities.length,
        showaddactivity: !section.locked,
        activities: section.activities.map((activity, index) => ({
            id: activity.id,
            name: activity.name,
            modname: activity.modname,
            purpose: activity.purpose,
            iconhtml: activity.iconhtml,
            locked: activity.locked,
            sectionid: section.id,
            index,
            typelabel: state.typeLabels[activity.modname] || '',
            // The insert-between-rows "+" divider is a planning affordance: it never
            // shows in a locked section (nothing may be added there at all), but it
            // DOES show above a locked activity inside an unlocked section — the
            // professor can still insert a new activity next to a reference one.
            showinsertzone: !section.locked,
        })),
    })),
    showaddsection: true,
    addsectiondisabled: !canAddSection(state),
    addsectionlabel: state.nolimit
        ? labels.addSection
        : `${labels.addSection} (${state.remainingSections})`,
});

/**
 * Render (or re-render) the structure into the container, then update the
 * dependent stats line. Re-renders always replace the container's contents —
 * delegated listeners on the container itself (wired once by wireStructureEvents)
 * survive every re-render, so nothing needs to be re-wired here.
 *
 * @param {HTMLElement} container
 * @param {Object} state
 * @param {Object} labels - {addSection}
 * @returns {Promise<void>}
 */
export const renderStructure = async(container, state, labels) => {
    if (!container) {
        return;
    }
    await ensureTypeLabels(state);
    const context = buildContext(state, labels);
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
 * @param {Function} handlers.onToggleSection - (sectionId) => void
 * @param {Function} handlers.onOpenChooser - (sectionId, position|null) => void
 * @param {Function} handlers.onRemoveActivity - (sectionId, activityIndex) => void
 * @param {Function} handlers.onAddSection - () => void
 */
export const wireStructureEvents = (container, handlers) => {
    if (!container) {
        return;
    }

    // Guards against a fast double click/double Enter firing a second removal
    // before the first one's re-render (which shifts every later DOM index)
    // has finished — that race would otherwise delete the wrong row.
    let removalPending = false;
    const handleRemoveActivity = async(removeEl) => {
        if (removalPending) {
            return;
        }
        removalPending = true;
        try {
            await handlers.onRemoveActivity(
                parseInt(removeEl.dataset.sectionId, 10),
                parseInt(removeEl.dataset.activityIndex, 10)
            );
        } finally {
            removalPending = false;
        }
    };

    container.addEventListener('click', (event) => {
        const toggleEl = event.target.closest(Selectors.actions.toggleSection);
        if (toggleEl) {
            event.preventDefault();
            handlers.onToggleSection(parseInt(toggleEl.dataset.sectionId, 10));
            return;
        }

        const chooserEl = event.target.closest(Selectors.actions.openChooser);
        if (chooserEl) {
            event.preventDefault();
            const sectionId = parseInt(chooserEl.dataset.sectionId, 10);
            const position = 'position' in chooserEl.dataset ? parseInt(chooserEl.dataset.position, 10) : null;
            handlers.onOpenChooser(sectionId, position);
            return;
        }

        const removeEl = event.target.closest(Selectors.actions.removeActivity);
        if (removeEl) {
            event.preventDefault();
            handleRemoveActivity(removeEl);
            return;
        }

        const addSectionEl = event.target.closest(Selectors.actions.addSection);
        if (addSectionEl && !addSectionEl.disabled) {
            event.preventDefault();
            handlers.onAddSection();
        }
    });

    // The delete control is a span[role="button"] (matches the detailed-plan
    // action controls' markup) so it needs an explicit Enter/Space activation —
    // unlike the <a>/<button> triggers above, it is not natively keyboard-activatable.
    container.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const removeEl = event.target.closest(Selectors.actions.removeActivity);
        if (removeEl) {
            event.preventDefault();
            handleRemoveActivity(removeEl);
        }
    });
};
