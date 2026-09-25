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
import {startTemplateGeneration, finishTemplateGeneration} from './template/repository';
import {runGenerationStream} from './template/generation_stream';
import {usePreviewSession} from './template/preview';
import {
    createTemplateState,
    addSection,
    insertActivity,
    removeActivity,
    toggleSectionCollapsed,
} from './template/state';
import {wireStructureEvents} from './template/render';
import {openActivityChooser, wireChooserModal} from './template/chooser';
import {wireInputBar} from './template/input_bar';
import {
    getLabels,
    rerenderStructure,
    loadTemplateStructure,
    clearStructure,
} from './template/structure_loader';

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

    // Input-bar defaults: no images, page default language, no syllabus yet.
    const tplState = createTemplateState({lang: state.defaultLang || ''});

    wireInputBar(tplState, state);

    // Sequence guard: reselecting the template autocomplete before a previous
    // getTemplateStructure() fetch resolves must not let the slower, stale
    // response overwrite the structure of the template picked afterwards.
    // Incremented on every 'change'; loadTemplateStructure captures the id it
    // was launched with and discards its response if it no longer matches.
    const requestTracker = {id: 0};

    wireStructureEvents(container, {
        onToggleSection: async(sectionId) => {
            toggleSectionCollapsed(tplState, sectionId);
            try {
                await rerenderStructure(container, tplState);
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
            let removedActivity = null;
            if (section) {
                removedActivity = section.activities[activityIndex];
            }
            if (removeActivity(tplState, sectionId, activityIndex)) {
                try {
                    await rerenderStructure(container, tplState);
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
                    await rerenderStructure(container, tplState);
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
                await rerenderStructure(container, tplState);
            } catch (e) {
                const section = tplState.sections.find((s) => s.id === sectionId);
                let idx = -1;
                if (section) {
                    idx = section.activities.findIndex((a) => a.id === pendingActivityId);
                }
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
