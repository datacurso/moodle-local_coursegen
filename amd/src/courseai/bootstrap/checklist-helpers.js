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
 * Checklist and adjustment-history helpers for the Course AI entrypoint.
 *
 * @module     local_coursegen/courseai/bootstrap/checklist-helpers
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {restoreAdjustmentHistory as doRestoreAdjustmentHistory} from 'local_coursegen/courseai/bootstrap/adjustment-history';

/**
 * Create checklist and adjustment-history helper functions.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {Object} params.texts
 * @returns {{
 *   buildSectionsFromDetailedPlan: Function,
 *   buildChecklistItem: Function,
 *   buildChecklistRoundFromSections: Function,
 *   renderInitialChecklist: Function,
 *   createRoundChecklistElement: Function,
 *   restoreAdjustmentHistory: Function
 * }}
 */
export const makeChecklistHelpers = ({state, elements, texts}) => {
    /**
     * Convert detailed plan sections from the snapshot into UI section objects.
     *
     * @param {Array} detailedSections
     * @returns {Array}
     */
    const buildSectionsFromDetailedPlan = (detailedSections) => {
        if (!Array.isArray(detailedSections)) {
            return [];
        }

        return detailedSections
            .filter((section) => !section?.deleted)
            .map((section, sectionIndex) => ({
                id: section.id,
                section_index: section.section_index ?? sectionIndex,
                name: section.name || `${texts.courseai_section_label} ${sectionIndex + 1}`,
                description: section.description || '',
                activities: (Array.isArray(section.activities) ? section.activities : [])
                    .filter((activity) => !activity?.deleted)
                    .map((activity, activityIndex) => ({
                        activity_type: activity.activity_type || activity.type || 'page',
                        title: activity.title || activity.name || `${texts.courseai_activity_default} ${activityIndex + 1}`,
                        description:
                            activity.description
                            || activity?.detailed_plan?.activity_description
                            || '',
                        detailed_plan: activity.detailed_plan || {},
                    })),
            }));
    };

    /**
     * Build a single checklist `<li>` DOM element for a section.
     *
     * A section is rendered as done (check icon) only when all its activities
     * are actually detailed; otherwise it stays in the spinner state so reload
     * never marks unfinished sections as completed. History rounds pass
     * `forceComplete` because they are, by definition, already finished.
     *
     * @param {Object} section
     * @param {Object} [options]
     * @param {boolean} [options.forceComplete] - mark as done regardless of counts
     * @returns {HTMLElement}
     */
    const buildChecklistItem = (section, options = {}) => {
        const item = document.createElement('li');
        const total = Number(section?.total ?? 0);
        const done = Number(section?.done ?? 0);
        const complete = options.forceComplete === true || (total > 0 && done >= total);
        item.className = 'courseai-checklist-item' + (complete ? ' is-done' : ' is-loading');
        item.setAttribute('data-section-index', String(section.section_index || 0));
        // Carry the attributes the live stream handler (handleDetailedPlanActivity)
        // uses, so a reload mid-planning lets the continuing stream mark each
        // section done as its activities arrive — instead of freezing the
        // checklist at the snapshot state.
        if (section.id) {
            item.setAttribute('data-section-id', String(section.id));
        }
        item.setAttribute('data-round', String(state.generationRound || 0));
        item.setAttribute('data-remaining', String(complete ? 0 : Math.max(0, total - done)));

        const check = document.createElement('span');
        check.className = 'courseai-checklist-check';
        check.innerHTML = '<svg class="spinner-icon" viewBox="0 0 24 24">'
            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>'
            + '<svg class="check-icon" viewBox="0 0 24 24">'
            + '<polyline points="20 6 9 17 4 12"></polyline></svg>';

        const name = document.createElement('span');
        name.className = 'courseai-checklist-name';
        name.textContent = String(section.name || '');

        item.appendChild(check);
        item.appendChild(name);

        return item;
    };

    /**
     * Build a round-level checklist data object from sections.
     *
     * @param {Array} sections
     * @returns {Object|null}
     */
    const buildChecklistRoundFromSections = (sections) => {
        if (!Array.isArray(sections) || sections.length === 0) {
            return null;
        }

        const checklistSections = sections.map((section, index) => {
            const activities = Array.isArray(section?.activities) ? section.activities : [];
            const total = activities.length;
            // "Done" = activities whose detailed_plan is actually filled. NOTE:
            // `description` exists from the INITIAL plan, so it cannot mark detail
            // completion; and buildSectionsFromDetailedPlan maps an undetailed
            // activity's detailed_plan to `{}` (truthy), so we must check for KEYS.
            const done = activities.filter((activity) => {
                const dp = activity?.detailed_plan;
                return Boolean(dp) && typeof dp === 'object' && Object.keys(dp).length > 0;
            }).length;

            return {
                id: section?.id,
                section_index: Number(section?.section_index ?? index),
                name: String(section?.name || ''),
                done,
                total,
            };
        });

        return {
            sections: checklistSections,
        };
    };

    /**
     * Render the initial checklist into the checklist container element.
     *
     * @param {Object} roundData
     * @returns {void}
     */
    const renderInitialChecklist = (roundData) => {
        if (!elements.checklistList || !elements.checklist) {
            return;
        }

        const sections = Array.isArray(roundData?.sections) ? roundData.sections : [];
        elements.checklistList.innerHTML = '';

        if (!sections.length) {
            elements.checklist.classList.add('hidden');
            return;
        }

        sections.forEach((section) => {
            elements.checklistList.appendChild(buildChecklistItem(section));
        });

        elements.checklist.classList.remove('hidden');
    };

    /**
     * Create a round checklist DOM element for the adjustment history.
     *
     * @param {Object} roundData
     * @returns {HTMLElement|null}
     */
    const createRoundChecklistElement = (roundData) => {
        const sections = Array.isArray(roundData?.sections) ? roundData.sections : [];
        if (!sections.length) {
            return null;
        }

        const checklist = document.createElement('div');
        checklist.className = 'courseai-checklist';
        if (typeof roundData?.round !== 'undefined') {
            checklist.setAttribute('data-round', String(roundData.round));
        }

        const label = document.createElement('span');
        label.className = 'courseai-checklist-label';
        label.textContent = texts.courseai_checklist_label;
        checklist.appendChild(label);

        const list = document.createElement('ul');
        list.className = 'courseai-checklist-list';

        sections.forEach((section) => list.appendChild(buildChecklistItem(section, {forceComplete: true})));

        checklist.appendChild(list);
        return checklist;
    };

    /**
     * Restore the adjustment history UI from snapshot messages and planning rounds.
     *
     * @param {Array} messages
     * @param {Array} planningRounds
     * @param {Array} fallbackSections
     * @returns {void}
     */
    const restoreAdjustmentHistory = (messages, planningRounds = [], fallbackSections = []) => {
        doRestoreAdjustmentHistory({
            state,
            elements,
            buildChecklistRoundFromSections,
            renderInitialChecklist,
            createRoundChecklistElement,
            messages,
            planningRounds,
            fallbackSections,
        });
    };

    return {
        buildSectionsFromDetailedPlan,
        buildChecklistItem,
        buildChecklistRoundFromSections,
        renderInitialChecklist,
        createRoundChecklistElement,
        restoreAdjustmentHistory,
    };
};
