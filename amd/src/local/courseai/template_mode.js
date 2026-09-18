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
 * server-side by local/template/render.js from local_coursegen/template_structure,
 * and the activity-type picker grid by local/template/chooser.js from
 * local_coursegen/template_activity_chooser. Nothing here builds HTML by hand.
 *
 * @module     local_coursegen/local/courseai/template_mode
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import YUI from 'core/yui';
import {getStrings} from 'core/str';
import {initFilepicker} from '../../repository/courseai';
import {bindToggleWrap, showFilePicker} from './context/filepicker';
import {
    getTemplateStructure,
    startTemplateGeneration,
    finishTemplateGeneration,
} from './template/repository';
import {runGenerationStream} from './template/generation_stream';
import {refreshPreviewLinks, usePreviewSession} from './template/preview';
import {
    createTemplateState,
    applyStructureResponse,
    addSection,
    insertActivity,
    removeActivity,
    toggleSectionCollapsed,
} from './template/state';
import {renderStructure, wireStructureEvents} from './template/render';
import {renderChooserGrid, openActivityChooser, wireChooserModal} from './template/chooser';
import {formatTemplate} from './utils';

// Localised labels used while mutating the structure (add-section button text,
// generic "Section" word for naming new sections, and the "N sections · M
// activities" stats template). Fetched once and cached — wireTemplateMode runs
// before the page's own translated strings are loaded (see courseai.js), so
// this module fetches only the couple of strings it needs.
let labelsPromise = null;
const getLabels = () => {
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
 * Generate the course from the picked template, with the input bar's own
 * values (prompt and syllabus), and watch it happen.
 *
 * Nothing runs until the stream is opened: start_template_generation only
 * exports the template, attaches the syllabus and hands back the stream whose
 * consumption drives the run. The professor therefore sees each activity being
 * generated as it happens, instead of a spinner over a job nobody can see.
 *
 * @param {Object} tplState
 * @param {HTMLSelectElement|null} tplSelect
 * @param {HTMLElement} genBtn
 */
const runGeneration = async(tplState, tplSelect, genBtn) => {
    const templateId = parseInt(tplSelect?.value || '0', 10);
    if (!templateId) {
        return;
    }
    genBtn.disabled = true;
    try {
        const started = await startTemplateGeneration(
            templateId,
            tplState.prompt || '',
            parseInt(tplState.syllabusdraftitemid || 0, 10) || 0
        );
        usePreviewSession(started.sessionid);
        const created = await runGenerationStream(
            started.streamurl,
            () => finishTemplateGeneration(started.sessionid),
            started.sessionid,
            {
                prompt: tplState.prompt || '',
                templateName: tplSelect?.options[tplSelect.selectedIndex]?.text || '',
            }
        );
        window.location.href = created.courseurl;
    } catch (e) {
        genBtn.disabled = false;
        Notification.exception(e);
    }
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
 */
export const wireTemplateMode = (state) => {
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

    // Input-bar defaults: no images, page default language, no syllabus yet.
    const tplState = createTemplateState({lang: state.defaultLang || ''});

    wireInputBar(tplState, state);

    // Sequence guard: reselecting the template autocomplete before a previous
    // getTemplateStructure() fetch resolves must not let the slower, stale
    // response overwrite the structure of the template picked afterwards.
    // Incremented on every 'change'; loadTemplateStructure captures the id it
    // was launched with and discards its response if it no longer matches.
    const requestTracker = {id: 0};

    // Single source of truth for re-rendering: always resolves the localised
    // label first so the "+ Add section" button never flashes untranslated text.
    const rerenderStructure = async() => {
        const {addSectionLabel, statsTemplate} = await getLabels();
        await renderStructure(container, tplState, {addSection: addSectionLabel});
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
    };

    wireStructureEvents(container, {
        onToggleSection: async(sectionId) => {
            toggleSectionCollapsed(tplState, sectionId);
            try {
                await rerenderStructure();
            } catch (e) {
                // Revert so the in-memory model matches what is still on screen.
                toggleSectionCollapsed(tplState, sectionId);
                Notification.exception(e);
            }
        },
        onOpenChooser: (sectionId, position) => {
            openActivityChooser(sectionId, position);
        },
        onRemoveActivity: async(sectionId, activityIndex) => {
            const section = tplState.sections.find((s) => s.id === sectionId);
            const removedActivity = section ? section.activities[activityIndex] : null;
            if (removeActivity(tplState, sectionId, activityIndex)) {
                try {
                    await rerenderStructure();
                } catch (e) {
                    // Put the removed row back so state matches the still-rendered DOM.
                    if (section && removedActivity) {
                        section.activities.splice(activityIndex, 0, removedActivity);
                    }
                    Notification.exception(e);
                }
            }
        },
        onAddSection: async() => {
            const {sectionWord} = await getLabels();
            const section = addSection(tplState, sectionWord);
            if (section) {
                try {
                    await rerenderStructure();
                } catch (e) {
                    // Undo the append so state matches the still-rendered DOM.
                    const idx = tplState.sections.indexOf(section);
                    if (idx !== -1) {
                        tplState.sections.splice(idx, 1);
                        if (!tplState.nolimit) {
                            tplState.remainingSections += 1;
                        }
                    }
                    Notification.exception(e);
                }
            }
        },
    });

    wireChooserModal(async(sectionId, position, modname, extras) => {
        const activity = tplState.allowedActivities.find((a) => a.modname === modname);
        if (!activity) {
            return;
        }
        // InsertActivity assigns this id (via the pre-decrement of nextActivityId)
        // to the new row — captured so the catch below can find and undo it.
        const pendingActivityId = tplState.nextActivityId;
        if (insertActivity(tplState, sectionId, position, {...activity, ...(extras || {})})) {
            try {
                await rerenderStructure();
            } catch (e) {
                const section = tplState.sections.find((s) => s.id === sectionId);
                const idx = section ? section.activities.findIndex((a) => a.id === pendingActivityId) : -1;
                if (idx !== -1) {
                    section.activities.splice(idx, 1);
                }
                Notification.exception(e);
            }
        }
    });

    // The real course-creation backend for this button (create_course_from_template
    // webservice / template_course_builder_service) was removed - it shipped the
    // old backup/restore + mock-AI design, already superseded elsewhere. Rather
    // than leave the button silently do nothing when other code re-enables it
    // (limits/loading logic still toggles genBtn.disabled below), tell the
    // professor plainly instead of failing silently.
    const genBtn = document.getElementById('tplModeGenerate');
    if (genBtn) {
        genBtn.addEventListener('click', () => {
            runGeneration(tplState, tplSelect, genBtn);
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
};

/**
 * Show/refresh or hide the input bar's syllabus chip to match tplState.
 *
 * @param {Object} tplState
 */
const refreshSyllabusChip = (tplState) => {
    const hasFile = !!tplState.syllabusdraftitemid;
    const chipsRow = document.getElementById('tplChipsRow');
    const chip = document.getElementById('tplChipSyllabus');
    const chipName = document.getElementById('tplChipSyllabusName');
    if (chipName) {
        chipName.textContent = tplState.syllabusfilename || '';
    }
    if (chip) {
        chip.classList.toggle('hidden', !hasFile);
    }
    if (chipsRow) {
        chipsRow.style.display = hasFile ? '' : 'none';
    }
};

/**
 * Wire the reduced input bar pinned at the bottom of the left panel: syllabus
 * attach (same no-course filepicker mechanics as free mode), generate-images
 * toggle, and language select. Values live in tplState, ready for the future
 * generation payload — the Generate button itself stays a stub elsewhere.
 *
 * @param {Object} tplState
 * @param {Object} state - Page state (createInitialState) carrying languages/defaultLang.
 */
const wireInputBar = (tplState, state) => {
    // Adaptation prompt — composer textarea, value tracked in tplState.
    const promptInput = document.getElementById('tplPromptInput');
    if (promptInput) {
        promptInput.addEventListener('input', () => {
            tplState.prompt = promptInput.value;
        });
    }

    // Language select — same options source as free mode (the page-context
    // languages array parsed by courseai.js into state.languages).
    const langSelect = document.getElementById('tplLangSelect');
    if (langSelect) {
        (state.languages || []).forEach((language) => {
            const option = document.createElement('option');
            option.value = language.code;
            option.textContent = language.name;
            langSelect.appendChild(option);
        });
        if (tplState.lang) {
            langSelect.value = tplState.lang;
        }
        // If the default language isn't offered, track whatever the select
        // actually shows so state and UI never disagree.
        tplState.lang = langSelect.value || tplState.lang;
        langSelect.addEventListener('change', () => {
            tplState.lang = langSelect.value;
        });
    }

    // Generate-images toggle — same toggle-track pattern as free mode.
    const imgToggleWrap = document.getElementById('tplImgToggleWrap');
    const imgCheckbox = document.getElementById('tplWithImages');
    if (imgToggleWrap && imgCheckbox) {
        bindToggleWrap(imgToggleWrap, imgCheckbox);
        imgCheckbox.addEventListener('change', () => {
            tplState.generateimages = imgCheckbox.checked ? 1 : 0;
            imgToggleWrap.classList.toggle('on', imgCheckbox.checked);
        });
    }

    // Syllabus attach — reuses the free-mode courseai_filepicker_init flow via
    // showFilePicker's onPicked hook; the picked draft file lives in tplState.
    const attachBtn = document.getElementById('tplBtnSyllabus');
    if (attachBtn) {
        attachBtn.addEventListener('click', async() => {
            await showFilePicker({
                state: {},
                CourseaiRepository: {initFilepicker},
                Notification,
                YUI,
                texts: {},
                onPicked: (filename, draftitemid) => {
                    tplState.syllabusfilename = filename;
                    tplState.syllabusdraftitemid = draftitemid;
                    refreshSyllabusChip(tplState);
                },
            });
        });
    }

    const removeBtn = document.getElementById('tplChipSyllabusRemove');
    if (removeBtn) {
        removeBtn.addEventListener('click', () => {
            tplState.syllabusfilename = '';
            tplState.syllabusdraftitemid = 0;
            refreshSyllabusChip(tplState);
        });
    }
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

        if (detailsEl) {
            detailsEl.style.display = '';
        }

        const {addSectionLabel, statsTemplate} = await getLabels();
        await renderStructure(container, tplState, {addSection: addSectionLabel});
        refreshPreviewLinks();
        updateStats(tplState, statsTemplate);
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
