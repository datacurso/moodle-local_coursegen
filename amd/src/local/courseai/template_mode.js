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
import {getString, getStrings} from 'core/str';
import {getTemplateStructure} from './template/repository';
import {reopenGeneration, runGeneration} from './template/run_generation';
import {wireInputBar} from './template/input_bar';
import {refreshPreviewLinks} from './template/preview';
import {mergeAddedStructure, mergeUnitCopies, setCopiesListener, setStructureListener} from './template/added_structure';
import {ALL_STATUS_CLASSES, STATUS_CLASS} from './template/generation_events';
import {initTopBar} from './template/top_bar';
import {
    createTemplateState,
    applyStructureResponse,
    toggleSectionCollapsed,
    setUnits,
    countsForUnits,
} from './template/state';
import Selectors from './template/selectors';
import {renderStructure, wireStructureEvents} from './template/render';
import {pickSpaceFile, removeSpaceFile, refreshGenerateState} from './template/space_files';
import {pickSpaceActivity, removeSpaceActivity} from './template/space_activities';
import {clearSpaceGate, missingSpaces, requiredSpaceCount, showSpaceGate} from './template/space_gate';
import {pickSpaceChoice, removeSpaceChoice} from './template/space_choices';
import {formatTemplate} from './utils';

// The "N sections · M activities" stats template. Fetched once and cached —
// wireTemplateMode runs before the page's own translated strings are loaded
// (see courseai.js), so this module fetches only the string it needs.
let statsTemplatePromise = null;
const getStatsTemplate = () => {
    if (!statsTemplatePromise) {
        statsTemplatePromise = Promise.all([
            getString('courseai_plan_sections_counter', 'local_coursegen'),
            getString('courseai_plan_sections_counter_copied', 'local_coursegen'),
        ]).then(([plain, copied]) => ({plain, copied}));
    }
    return statsTemplatePromise;
};

/**
 * Update the "N sections · M activities" summary line in the toolbar.
 *
 * @param {Object} tplState
 * @param {{plain: string, copied: string}} statsTemplate The line without and with copied activities.
 */
const updateStats = (tplState, statsTemplate) => {
    const statsEl = document.getElementById('tplModeStats');
    if (!statsEl) {
        return;
    }
    // The counts follow the number of units asked, not only the template's own sections.
    const counts = countsForUnits(tplState);
    // The activities copied as they are get named, so the total does not read as what the AI writes.
    const template = counts.copied > 0 ? statsTemplate.copied : statsTemplate.plain;
    statsEl.textContent = formatTemplate(template, counts);
};

/**
 * Mark the rows drawn after the run began as waiting, the way the rows of the template already are.
 */
const markRowsPending = () => {
    document.querySelectorAll('[data-generation-uid]').forEach((row) => {
        const started = ALL_STATUS_CLASSES.some((name) => row.classList.contains(name));
        if (!started) {
            row.classList.add(STATUS_CLASS.pending);
        }
    });
};

/**
 * Show the units field for a template that has units, with its range and the professor's number.
 *
 * @param {Object} tplState
 */
const refreshUnitsField = (tplState) => {
    const field = document.querySelector(Selectors.regions.unitsField);
    const input = document.querySelector(Selectors.actions.unitsInput);
    if (!field || !input) {
        return;
    }
    field.hidden = !tplState.unitcount;
    if (tplState.unitcount) {
        input.min = 1;
        input.max = tplState.maxunits;
        input.value = tplState.units;
    }
};

/**
 * Keep the state in step with the units field.
 *
 * @param {Object} tplState
 */
const wireUnitsField = (tplState) => {
    const input = document.querySelector(Selectors.actions.unitsInput);
    if (!input) {
        return;
    }
    input.addEventListener('change', async() => {
        setUnits(tplState, input.value);
        // Show the value that was kept, which may differ from what was typed.
        input.value = tplState.units;
        updateStats(tplState, await getStatsTemplate());
    });
};

/**
 * Wire mode switching and template form.
 *
 * @param {Object} state
 * @param {Object} host Holds the page's actions once they exist.
 * @param {Object|null} reopened A run of a template that did not finish, to open where it was.
 */
export const wireTemplateMode = (state, host, reopened = null) => {
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

    // Input-bar defaults: no images, language left to the AI (detect automatically), no syllabus yet.
    const tplState = createTemplateState({lang: ''});

    wireInputBar(tplState, state);
    wireUnitsField(tplState);

    // Sequence guard: reselecting the template autocomplete before a previous
    // getTemplateStructure() fetch resolves must not let the slower, stale
    // response overwrite the structure of the template picked afterwards.
    // Incremented on every 'change'; loadTemplateStructure captures the id it
    // was launched with and discards its response if it no longer matches.
    const requestTracker = {id: 0};

    // Single source of truth for re-rendering: the structure, the stats line
    // and the Generate button all follow the in-memory model.
    const genBtn = document.getElementById('tplModeGenerate');
    const rerenderStructure = async() => {
        const statsTemplate = await getStatsTemplate();
        await renderStructure(container, tplState);
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
        refreshGenerateState(tplState, genBtn);
    };

    // The sections and activities the guideline adds join the structure and its counters while the run is on.
    setStructureListener(async(data) => {
        mergeAddedStructure(tplState, data);
        await rerenderStructure();
        markRowsPending();
    });
    // The copies of a template section, one per unit of the syllabus, join it while the run is on.
    setCopiesListener(async(data) => {
        mergeUnitCopies(tplState, data);
        await rerenderStructure();
        markRowsPending();
    });

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
        onPickSpaceFile: (sectionIndex, activityIndex) => {
            pickSpaceFile(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
        onRemoveSpaceFile: (sectionIndex, activityIndex) => {
            removeSpaceFile(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
        onPickSpaceChoice: (sectionIndex, activityIndex) => {
            pickSpaceChoice(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
        onRemoveSpaceChoice: (sectionIndex, activityIndex) => {
            removeSpaceChoice(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
        onPickSpaceActivity: (sectionIndex, activityIndex) => {
            pickSpaceActivity(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
        onRemoveSpaceActivity: (sectionIndex, activityIndex) => {
            removeSpaceActivity(tplState, sectionIndex, activityIndex, rerenderStructure);
        },
    });

    // Generate: a required space still without its file stops it here, before the AI is asked anything; the card that
    // says so lets the teacher bring the file, and the generation starts as soon as nothing is missing.
    const generate = () => {
        const missing = missingSpaces(tplState);
        if (missing.length) {
            showSpaceGate(missing, requiredSpaceCount(tplState), {onBring: bringSpace, onReveal: revealSpace});
            return;
        }
        clearSpaceGate();
        runGeneration(tplState, tplSelect, genBtn, state, host);
    };
    const afterBrought = async() => {
        await rerenderStructure();
        if (document.getElementById('cgSpaceGateCard')) {
            generate();
        }
    };
    const bringSpace = (sectionIndex, activityIndex) => {
        const row = tplState.sections[sectionIndex]?.activities[activityIndex];
        if (row?.spacekind === 'choice' && row.spacechoice?.mode !== 'teacher') {
            pickSpaceChoice(tplState, sectionIndex, activityIndex, afterBrought);
            return;
        }
        pickSpaceFile(tplState, sectionIndex, activityIndex, afterBrought);
    };
    // The card of a file in the structure, brought into view (its section opened if it was closed) and marked for a
    // moment, so the teacher sees where the file goes.
    const revealSpace = async(sectionIndex, activityIndex) => {
        const section = tplState.sections[sectionIndex];
        if (section?.collapsed) {
            toggleSectionCollapsed(tplState, sectionIndex);
            await rerenderStructure();
        }
        const position = `[data-section-index="${sectionIndex}"][data-activity-index="${activityIndex}"]`;
        const spaceCard = container?.querySelector(`[data-region="local_coursegen/template/space-card"]${position}`);
        if (!spaceCard) {
            return;
        }
        spaceCard.scrollIntoView({block: 'center', behavior: 'smooth'});
        spaceCard.classList.remove('cg-space-row--flash');
        // Read once so that the animation starts again when the same card is shown twice in a row.
        void spaceCard.offsetWidth;
        spaceCard.classList.add('cg-space-row--flash');
    };
    if (genBtn) {
        genBtn.addEventListener('click', generate);
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

    if (reopened?.resumable && tplSelect) {
        reopenRun(reopened, {tplState, tplSelect, container, state, requestTracker, genBtn, host});
    }
};

/**
 * Open a run that did not finish: its template picked, its structure shown, and the run paused where it was.
 *
 * @param {Object} reopened {id, templateid, streamurl, context}
 * @param {Object} page {tplState, tplSelect, container, state, requestTracker, genBtn, host}
 * @returns {Promise<void>}
 */
const reopenRun = async(reopened, page) => {
    page.tplSelect.value = String(reopened.templateid);
    document.getElementById('courseaiWorkspace')?.classList.add('tpl-active');
    page.requestTracker.id += 1;
    await loadTemplateStructure(
        reopened.templateid, page.tplState, page.container, page.state, page.requestTracker, page.requestTracker.id
    );
    if (!page.state.templateStructureLoaded) {
        return;
    }
    await reopenGeneration(page.tplState, page.genBtn, page.state, page.host, reopened);
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
        refreshUnitsField(tplState);

        if (detailsEl) {
            detailsEl.style.display = '';
        }

        const statsTemplate = await getStatsTemplate();
        await renderStructure(container, tplState);
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
        await renderLimitsBanner(limitsEl, limitsBadge, tplState);

        // A required space keeps the button off until its file is picked.
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
const clearStructure = (tplState, container, state) => {
    if (container) {
        container.innerHTML = '';
    }
    const detailsEl = document.getElementById('tplModeDetails');
    if (detailsEl) {
        detailsEl.style.display = 'none';
    }
    const unitsField = document.querySelector(Selectors.regions.unitsField);
    if (unitsField) {
        unitsField.hidden = true;
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
    clearSpaceGate();
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
        guidelineid: tplState.guidelineid,
    }));
    state.templateStructureLoaded = false;
};
