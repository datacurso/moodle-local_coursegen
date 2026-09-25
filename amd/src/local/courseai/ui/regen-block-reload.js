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
 * Rebuilds the regen block from an authoritative, persisted plan - as opposed
 * to regen-block.js, which streams one from live events. Used on RELOAD, so
 * the panel looks identical to the live build, and for the approved-plan
 * summary shown right after the professor approves. Reuses regen-block.js's
 * item builders (``buildItem``/``createBlock``) rather than duplicating them,
 * so both blocks are built from the same markup.
 *
 * @module     local_coursegen/local/courseai/ui/regen-block-reload
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderMarkdown, formatActivityDetailMd, formatSectionMd} from 'local_coursegen/local/courseai/ui/markdown';
import {clampDetail} from 'local_coursegen/local/courseai/ui/plan-transcript';
import {buildItem, createBlock, findActivity} from 'local_coursegen/local/courseai/ui/regen-block';

/**
 * Rebuild the regen block for one replanned SECTION.
 *
 * @param {HTMLElement} list
 * @param {Array} plan
 * @param {string[]} ids
 * @returns {void}
 */
const rebuildRegenSections = (list, plan, ids) => {
    const sections = (plan || []).filter((s) => s && !s.deleted && ids.indexOf(s.id) !== -1);
    sections.forEach((section) => {
        const activities = (section.activities || [])
            .filter((a) => !a.deleted)
            .map((a) => ({
                title: a.title,
                activity_type: a.activity_type,
                description: a.description || '',
                detailedPlan: a.detailed_plan || null,
            }));
        const sectionMd = formatSectionMd({
            name: '',
            description: section.description || '',
            activities,
        });
        const md = renderMarkdown(sectionMd);
        const item = buildItem({id: section.id, title: section.name || '', detailHtml: md, done: true});
        list.appendChild(item);
        clampDetail(item.querySelector('.courseai-checklist-detail'));
    });
};

/**
 * Rebuild the regen block for replanned ACTIVITIES.
 *
 * @param {HTMLElement} list
 * @param {Array} plan
 * @param {string[]} ids
 * @returns {void}
 */
const rebuildRegenActivities = (list, plan, ids) => {
    ids.forEach((id) => {
        const activity = findActivity(plan, id);
        if (!activity) {
            return;
        }
        const activityMd = formatActivityDetailMd({
            description: activity.description || '',
            detailedPlan: activity.detailed_plan || null,
        });
        const md = renderMarkdown(activityMd);
        const item = buildItem({
            id,
            title: activity.title || 'Activity',
            iconType: activity.activity_type || '',
            detailHtml: md,
            done: true,
        });
        list.appendChild(item);
        clampDetail(item.querySelector('.courseai-checklist-detail'));
    });
};

/**
 * Build a COMPLETE regen block from an authoritative plan — used on RELOAD so
 * the panel looks identical to the live build. Each item renders done + clamped.
 *
 * @param {Object} params
 * @param {string} params.action - 'replan_activity' | 'replan_section'.
 * @param {string[]} params.targetIds - Regenerated activity/section UUIDs.
 * @param {Array} params.plan - Authoritative plan (from the round's ai_planned_structure).
 * @returns {void}
 */
export const rebuildRegenFromPlan = ({action, targetIds, plan}) => {
    const list = createBlock();
    if (!list) {
        return;
    }
    const ids = targetIds || [];
    if (action === 'replan_section') {
        rebuildRegenSections(list, plan, ids);
        return;
    }
    rebuildRegenActivities(list, plan, ids);
};

/**
 * One section's Markdown for the approved-plan summary.
 *
 * @param {Object} section
 * @returns {string}
 */
const approvedSectionMd = (section) => {
    const activities = (section.activities || [])
        .filter((a) => a && !a.deleted)
        .map((a) => ({
            title: a.title,
            activity_type: a.activity_type,
            description: a.description || '',
            detailedPlan: a.detailed_plan || null,
        }));
    return formatSectionMd({
        name: section.name,
        description: section.description || '',
        activities,
    });
};

/**
 * Render the COMPLETE approved plan as ONE single condensed element — every active,
 * named section (its name as a heading, description, activities and each activity's
 * detailed plan) concatenated into ONE Markdown body with ONE "Show more"/"Show
 * less" toggle, shown right after the "You approved the plan" turn. A single block
 * (not one clamped block per section) avoids the repetitive per-section toggles.
 * Live (cached plan) and reload (persisted approved snapshot) build it from the
 * same plan, so they are byte-for-byte identical.
 *
 * @param {Array} plan - The approved plan tree (detailed_plan on activities).
 * @returns {void}
 */
export const renderApprovedPlanSummary = (plan) => {
    const sections = (plan || []).filter((s) => s && !s.deleted && String(s.name || '').trim());
    if (!sections.length) {
        return;
    }
    const sectionTexts = sections.map((section) => approvedSectionMd(section));
    const nonEmptyTexts = sectionTexts.filter(Boolean);
    const md = nonEmptyTexts.join('\n\n');
    if (!md) {
        return;
    }
    const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
    if (!feed) {
        return;
    }
    const container = document.createElement('div');
    container.className = 'courseai-checklist cg-regen-block cg-approved-summary';
    const detail = document.createElement('div');
    detail.className = 'courseai-checklist-detail cg-log-md';
    detail.innerHTML = renderMarkdown(md);
    container.appendChild(detail);
    feed.appendChild(container);
    clampDetail(detail);
};
