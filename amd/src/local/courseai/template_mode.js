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
 * Template mode — handles mode switching and template form interactions.
 *
 * This entrypoint only wires DOM events; the guided-form structure (sections
 * and activities, replicating core_courseformat's card/row look) is rendered
 * server-side by local/template/render.js from local_coursegen/template_structure.
 * Nothing here builds HTML by hand.
 *
 * @module     local_coursegen/local/courseai/template_mode
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getString} from 'core/str';
import {getTemplateAgentState, getTemplateStructure} from './template/repository';
import {runGeneration} from './template/run_generation';
import {resumeGenerationStream} from './template/generation_stream';
import {reviewAndCreate} from './template/finish';
import {wireInputBar} from './template/input_bar';
import {refreshPreviewLinks, usePreviewSession} from './template/preview';
import {initTopBar} from './template/top_bar';
import {
    createTemplateState,
    applyStructureResponse,
    toggleSectionCollapsed,
} from './template/state';
import {renderStructure, wireStructureEvents} from './template/render';
import {formatTemplate} from './utils';
import Selectors from './template/selectors';
import {refreshGenerateButton} from './template/generate_gate';

/**
 * Let the Generate button follow the form: it is on once a template is loaded and the professor gave a text or a
 * file, and off again when both are taken away.
 *
 * Nothing happens while a generation is on screen: from then on the button belongs to the review.
 *
 * @param {Object} tplState
 * @param {HTMLButtonElement|null} genBtn
 */
const refreshGenerateState = (tplState, genBtn) => {
    const generating = document.body.classList.contains(Selectors.classes.generating);
    refreshGenerateButton(tplState, genBtn, generating);
};

// The "N sections · M activities" stats template. Fetched once and cached —
// wireTemplateMode runs before the page's own translated strings are loaded
// (see courseai.js), so this module fetches only the string it needs.
let statsTemplatePromise = null;
const getStatsTemplate = () => {
    if (!statsTemplatePromise) {
        statsTemplatePromise = getString('courseai_plan_sections_counter', 'local_coursegen');
    }
    return statsTemplatePromise;
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
 * Wire mode switching and template form.
 *
 * @param {Object} state
 * @param {Object} host Holds the page's actions once they exist.
 */
export const wireTemplateMode = (state, host) => {
    // Free/Template mode switching is plain <a href> navigation
    // (aicoursecreation.php / ?mode=template), server-rendered from the
    // mode param — no JS involved.
    //
    // The template picker itself is a native Moodle form (single autocomplete
    // element, see classes/form/course_template_picker_form.php), rendered
    // server-side and embedded as-is — Moodle's own form renderer already
    // enhances the underlying <select> into the autocomplete widget, so no
    // JS wiring is needed here beyond listening for its 'change' event.
    // Moodleform's default id for an unnamed-id element is "id_<fieldname>".
    const tplSelect = document.getElementById('id_templateid');
    const container = document.getElementById('tplModeStructure');

    // The activities pill of the top bar opens and closes its list of activities.
    initTopBar();

    // Input-bar defaults: no images, page default language, no syllabus yet.
    const tplState = createTemplateState({lang: state.defaultLang || ''});

    // The Generate button follows the form: a template, and a text or a file.
    const genBtn = document.getElementById('tplModeGenerate');
    wireInputBar(tplState, state, () => refreshGenerateState(tplState, genBtn));

    // Sequence guard: reselecting the template autocomplete before a previous
    // getTemplateStructure() fetch resolves must not let the slower, stale
    // response overwrite the structure of the template picked afterwards.
    // Incremented on every 'change'; loadTemplateStructure captures the id it
    // was launched with and discards its response if it no longer matches.
    const requestTracker = {id: 0};

    // Single source of truth for re-rendering: the structure, the stats line
    // and the Generate button all follow the in-memory model.
    const rerenderStructure = async() => {
        const statsTemplate = await getStatsTemplate();
        await renderStructure(container, tplState);
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
        refreshGenerateState(tplState, genBtn);
    };

    wireStructureEvents(container, {
        onToggleSection: async(sectionIndex) => {
            toggleSectionCollapsed(tplState, sectionIndex);
            try {
                await rerenderStructure();
            } catch (e) {
                // Revert so the in-memory model matches what is still on screen.
                toggleSectionCollapsed(tplState, sectionIndex);
                Notification.exception(e);
            }
        },
    });

    // Generate: the button is only on while a template is loaded.
    if (genBtn) {
        genBtn.addEventListener('click', () => {
            runGeneration(tplState, tplSelect, genBtn, state, host);
        });
    }

    // Template selection — load structure.
    if (tplSelect) {
        tplSelect.addEventListener('change', () => {
            const tplId = parseInt(tplSelect.value, 10);
            // One class on the workspace drives the picked/unpicked chrome:
            // .courseai-workspace.tpl-active hides the main column's empty
            // state (see aicoursecreation.css) while a template is selected.
            const workspace = document.getElementById('courseaiWorkspace');
            if (workspace) {
                workspace.classList.toggle('tpl-active', tplId > 0);
            }
            requestTracker.id += 1;
            const requestId = requestTracker.id;
            if (tplId > 0) {
                loadTemplateStructure(tplId, tplState, container, state, requestTracker, requestId);
            } else {
                clearStructure(tplState, container, state);
            }
        });
    }

    if (state.templateResume && state.templateResume.sessionid > 0 && tplSelect) {
        resumeRun(state.templateResume, {tplSelect, tplState, container, state, host, requestTracker});
    }
};

/**
 * Repaint a template generation after a reload and go on with it.
 *
 * @param {Object} resume What the page was given: sessionid, templateid, prompt and templatename.
 * @param {Object} parts The elements and state of the template mode.
 */
const resumeRun = async(resume, parts) => {
    const {tplSelect, tplState, container, state, host, requestTracker} = parts;
    try {
        tplSelect.value = String(resume.templateid);
        const workspace = document.getElementById('courseaiWorkspace');
        if (workspace) {
            workspace.classList.add('tpl-active');
        }
        requestTracker.id += 1;
        await loadTemplateStructure(resume.templateid, tplState, container, state, requestTracker, requestTracker.id);
        const snapshot = await getTemplateAgentState(resume.sessionid);
        usePreviewSession(resume.sessionid);
        await resumeGenerationStream(
            snapshot,
            () => createCourseWhenReady(host, state, tplState, resume.sessionid),
            resume.sessionid,
            {prompt: resume.prompt || '', templateName: resume.templatename || ''}
        );
    } catch (e) {
        Notification.exception(e);
    }
};

const createCourseWhenReady = async(host, state, tplState, sessionId) => {
    await host.ready;
    return reviewAndCreate(host, state, tplState, sessionId);
};

/**
 * Load a template's guided-form structure (sections, activities, spaces and
 * section limits) and render it.
 *
 * @param {number} templateId
 * @param {Object} tplState
 * @param {HTMLElement} container
 * @param {Object} state
 * @param {Object} requestTracker - {id} mutable holder of the latest request id.
 * @param {number} requestId - The id this call was launched with.
 */
const loadTemplateStructure = async(templateId, tplState, container, state, requestTracker, requestId) => {
    const detailsEl = document.getElementById('tplModeDetails');
    const limitsEl = document.getElementById('tplModeLimits');
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

        const statsTemplate = await getStatsTemplate();
        await renderStructure(container, tplState);
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
        showStatsRow(limitsEl);

        // The button comes on when there is a text or a file to work from.
        refreshGenerateState(tplState, genBtn);
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

/**
 * Show the row that holds the stats and the preview link (the markup itself is static,
 * see courseai_page.mustache#tplModeLimits - this only reveals it).
 *
 * @param {HTMLElement} rowEl
 */
const showStatsRow = (rowEl) => {
    if (!rowEl) {
        return;
    }
    rowEl.style.display = '';
};

/**
 * Clear the structure display.
 *
 * @param {Object} tplState
 * @param {HTMLElement} container
 * @param {Object} state
 */
const clearStructure = (tplState, container, state) => {
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
    if (limitsEl) {
        limitsEl.style.display = 'none';
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
