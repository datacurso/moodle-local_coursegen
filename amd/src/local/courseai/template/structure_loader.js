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
 * Loading, refreshing and clearing the template-mode guided-form structure.
 *
 * Split out of template_mode.js, which only wires the structure-editing
 * events and the Generate button; this module owns fetching the structure
 * from the server, re-rendering it after a local edit, and resetting the
 * panel when no template (or a failed one) is selected.
 *
 * @module     local_coursegen/local/courseai/template/structure_loader
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getStrings} from 'core/str';
import {getTemplateStructure} from './repository';
import {refreshPreviewLinks} from './preview';
import {createTemplateState, applyStructureResponse} from './state';
import {renderStructure} from './render';
import {renderChooserGrid} from './chooser';
import {formatTemplate} from '../utils';

// Localised labels used while mutating the structure (add-section button text,
// generic "Section" word for naming new sections, and the "N sections · M
// activities" stats template). Fetched once and cached — wireTemplateMode runs
// before the page's own translated strings are loaded (see courseai.js), so
// this module fetches only the couple of strings it needs.
let labelsPromise = null;
export const getLabels = () => {
    if (!labelsPromise) {
        labelsPromise = getStrings([
            {key: 'courseai_template_add_section', component: 'local_coursegen'},
            {key: 'section', component: 'moodle'},
            {key: 'courseai_plan_sections_counter', component: 'local_coursegen'},
        ]).then(([addSectionLabel, sectionWord, statsTemplate]) => ({addSectionLabel, sectionWord, statsTemplate}));
    }
    return labelsPromise;
};

/**
 * Update the "N sections · M activities" summary line in the toolbar.
 *
 * @param {Object} tplState
 * @param {string} statsTemplate
 */
const updateStats = (tplState, statsTemplate) => {
    const statsEl = document.getElementById('tplModeStats');
    if (!statsEl) {
        return;
    }
    const totalActivities = tplState.sections.reduce((sum, section) => sum + section.activities.length, 0);
    statsEl.textContent = formatTemplate(statsTemplate, {
        sections: tplState.sections.length,
        activities: totalActivities,
    });
};

/**
 * Re-render the structure from the current in-memory state.
 *
 * Single source of truth for re-rendering: always resolves the localised
 * label first so the "+ Add section" button never flashes untranslated text.
 *
 * @param {HTMLElement} container
 * @param {Object} tplState
 */
export const rerenderStructure = async(container, tplState) => {
    const {addSectionLabel, statsTemplate} = await getLabels();
    await renderStructure(container, tplState, {addSection: addSectionLabel});
    refreshPreviewLinks();
    updateStats(tplState, statsTemplate);
};

/**
 * Render the section limits banner text (the badge markup itself is static,
 * see courseai_page.mustache#tplModeLimits — this only toggles it and sets text).
 *
 * @param {HTMLElement} limitsEl
 * @param {HTMLElement} limitsBadge
 * @param {Object} tplState
 */
const renderLimitsBanner = async(limitsEl, limitsBadge, tplState) => {
    if (!limitsEl || !limitsBadge) {
        return;
    }
    if (tplState.nolimit) {
        const [nolimitStr] = await getStrings([
            {key: 'courseai_template_limits_nolimit', component: 'local_coursegen'},
        ]);
        limitsBadge.textContent = nolimitStr;
    } else {
        const [remainingStr] = await getStrings([
            {key: 'courseai_template_limits_remaining', component: 'local_coursegen'},
        ]);
        limitsBadge.textContent = remainingStr.replace('{$a}', tplState.remainingSections);
    }
    limitsEl.style.display = '';
};

/**
 * Clear the structure display.
 *
 * @param {Object} tplState
 * @param {HTMLElement} container
 * @param {Object} state
 */
export const clearStructure = (tplState, container, state) => {
    if (container) {
        container.innerHTML = '';
    }
    const detailsEl = document.getElementById('tplModeDetails');
    if (detailsEl) {
        detailsEl.style.display = 'none';
    }
    const workspace = document.getElementById('courseaiWorkspace');
    if (workspace) {
        workspace.classList.remove('tpl-active');
    }
    const limitsEl = document.getElementById('tplModeLimits');
    const limitsBadge = document.getElementById('tplModeLimitsBadge');
    if (limitsEl) {
        limitsEl.style.display = 'none';
    }
    if (limitsBadge) {
        limitsBadge.textContent = '';
    }
    const genBtn = document.getElementById('tplModeGenerate');
    if (genBtn) {
        genBtn.disabled = true;
    }
    const statsEl = document.getElementById('tplModeStats');
    if (statsEl) {
        statsEl.textContent = '';
    }
    // Reset only the structure: the input-bar values (images/lang/syllabus)
    // belong to the professor's session and survive clearing the template.
    Object.assign(tplState, createTemplateState({
        prompt: tplState.prompt,
        generateimages: tplState.generateimages,
        lang: tplState.lang,
        syllabusdraftitemid: tplState.syllabusdraftitemid,
        syllabusfilename: tplState.syllabusfilename,
    }));
    state.templateStructureLoaded = false;
};

/**
 * Load a template's guided-form structure (locked sections/activities, section
 * limits, and the admin-allowed activity catalog) and render it.
 *
 * @param {number} templateId
 * @param {Object} tplState
 * @param {HTMLElement} container
 * @param {Object} state
 * @param {Object} requestTracker - {id} mutable holder of the latest request id.
 * @param {number} requestId - The id this call was launched with.
 */
export const loadTemplateStructure = async(templateId, tplState, container, state, requestTracker, requestId) => {
    const detailsEl = document.getElementById('tplModeDetails');
    const limitsEl = document.getElementById('tplModeLimits');
    const limitsBadge = document.getElementById('tplModeLimitsBadge');
    const genBtn = document.getElementById('tplModeGenerate');
    if (!container) {
        return;
    }

    try {
        const data = await getTemplateStructure(templateId);
        if (requestTracker.id !== requestId) {
            // A newer template was selected while this fetch was in flight — discard.
            return;
        }
        applyStructureResponse(tplState, data);

        if (detailsEl) {
            detailsEl.style.display = '';
        }

        await rerenderStructure(container, tplState);
        await renderChooserGrid(tplState.allowedActivities);
        await renderLimitsBanner(limitsEl, limitsBadge, tplState);

        if (genBtn) {
            genBtn.disabled = false;
        }
        state.templateStructureLoaded = true;
    } catch (e) {
        if (requestTracker.id !== requestId) {
            // A newer template selection superseded this failed fetch — its own
            // handler already owns the UI, so this stale failure stays silent.
            return;
        }
        // Reset the structure panel, limits badge and Generate button so the
        // professor doesn't see a mix of the failed template's name with the
        // previous template's structure still on screen.
        clearStructure(tplState, container, state);
        Notification.exception(e);
    }
};
