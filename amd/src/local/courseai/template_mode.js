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
import {getStrings} from 'core/str';
import {getTemplateStructure, createCourseFromTemplate} from './template/repository';
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
 * Build the "what the professor added" payload from the in-memory template
 * state and create the course. The server re-reads keep/modify/exclude/
 * reference straight from the database by itself — only newly added
 * sections/activities (negative client-side placeholder ids) are sent. On
 * success, navigates to the newly created course.
 *
 * @param {HTMLElement} genBtn
 * @param {HTMLElement} tplSelect
 * @param {Object} tplState
 */
const handleGenerateClick = async(genBtn, tplSelect, tplState) => {
    if (!genBtn || genBtn.disabled) {
        return;
    }
    const templateId = tplSelect ? parseInt(tplSelect.value, 10) : 0;
    if (!templateId) {
        return;
    }

    genBtn.disabled = true;
    try {
        const newsections = tplState.sections
            .filter((section) => !section.locked && section.id < 0)
            .map((section) => ({clientid: section.id, name: section.name}));

        const newactivities = [];
        tplState.sections.forEach((section) => {
            section.activities.forEach((activity) => {
                if (!activity.locked) {
                    newactivities.push({sectionid: section.id, modname: activity.modname});
                }
            });
        });

        const result = await createCourseFromTemplate({templateid: templateId, newsections, newactivities});
        if (result && result.success && result.courseurl) {
            window.location.href = result.courseurl;
            return;
        }
        genBtn.disabled = false;
        Notification.exception(new Error((result && result.message) || 'Course creation failed.'));
    } catch (e) {
        genBtn.disabled = false;
        Notification.exception(e);
    }
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
    const sidebar = document.getElementById('courseaiSidebar');
    const collapseBtn = document.getElementById('courseaiSidebarCollapse');
    const expandBtn = document.getElementById('courseaiSidebarExpand');
    const container = document.getElementById('tplModeStructure');

    // Sidebar collapse/expand.
    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', () => {
            sidebar.classList.add('collapsed');
        });
    }
    if (expandBtn && sidebar) {
        expandBtn.addEventListener('click', () => {
            sidebar.classList.remove('collapsed');
        });
    }

    const tplState = createTemplateState();

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

    wireChooserModal(async(sectionId, position, modname) => {
        const activity = tplState.allowedActivities.find((a) => a.modname === modname);
        if (!activity) {
            return;
        }
        // InsertActivity assigns this id (via the pre-decrement of nextActivityId)
        // to the new row — captured so the catch below can find and undo it.
        const pendingActivityId = tplState.nextActivityId;
        if (insertActivity(tplState, sectionId, position, activity)) {
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

    const genBtn = document.getElementById('tplModeGenerate');
    if (genBtn) {
        genBtn.addEventListener('click', () => handleGenerateClick(genBtn, tplSelect, tplState));
    }

    // Template selection — load structure.
    if (tplSelect) {
        tplSelect.addEventListener('change', () => {
            const tplId = parseInt(tplSelect.value, 10);
            // Before a pick, #templateModeCard is just the bare label+field (no
            // border, no footer/Generate) — and core/form-autocomplete's own
            // "search to change selection" row stays collapsed once picked, so
            // only the chip shows. All driven by one class on the card (see
            // #templateModeCard.tpl-active in aicoursecreation.css — Bootstrap's
            // .d-md-inline-block on .form-autocomplete-input carries !important,
            // so a plain inline style can't win, this needs id+class specificity).
            // Queried here (not once up-front) because core/form-autocomplete's
            // own enhance() call — a separate js_call_amd — may not have finished
            // inserting its markup yet at the point wireTemplateMode runs.
            const card = document.getElementById('templateModeCard');
            if (card) {
                card.classList.toggle('tpl-active', tplId > 0);
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
    const card = document.getElementById('templateModeCard');
    if (card) {
        card.classList.remove('tpl-active');
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
    Object.assign(tplState, createTemplateState());
    state.templateStructureLoaded = false;
};
