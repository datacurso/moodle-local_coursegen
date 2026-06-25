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
 * Regeneration block for the LEFT panel.
 *
 * When the user asks the AI to replan an activity or a section, we show a NEW
 * block below their instruction that looks EXACTLY like the initial-planning
 * checklist (spinner→check item head + clamped Markdown detail with "Show
 * more"/"Show less"), streaming in real time — with two differences:
 *  - replan_activity: each item's head is the ACTIVITY title (not the section
 *    name) prefixed by the activity's monologo icon (same as the center view,
 *    smaller). Two activities → two separate items.
 *  - replan_section: each item is a section, identical to initial planning.
 *
 * The block is built additively into the post-review feed (#cgLogAfter). The
 * top "structure I planned" checklist is left untouched. The live build (driven
 * by stream events) and the reload rebuild (from the persisted plan) call the
 * SAME item renderer, so reload === live.
 *
 * Self-contained: the stream handlers and the reload replay import these
 * directly. State is module-level (one wizard per page).
 *
 * @module     local_coursegen/local/courseai/ui/regen-block
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderMarkdown, formatActivityDetailMd, formatSectionMd} from 'local_coursegen/local/courseai/ui/markdown';
import {clampDetail} from 'local_coursegen/local/courseai/ui/plan-transcript';
import {getActivityIconUrl, escapeHtml} from 'local_coursegen/local/courseai/utils';

/** @type {HTMLElement|null} The list of the regen block currently streaming. */
let activeList = null;
/** @type {Map<string, HTMLElement>} target id (activity or section) -> its <li>. */
let activeItems = new Map();
/** @type {Map<string, string>} activity id -> its description (from the plan). */
let activeDesc = new Map();

/**
 * The spinner/check circle markup shared with the initial-planning checklist.
 *
 * @returns {string}
 */
const checkMarkup = () => '<span class="courseai-checklist-check">'
    + '<svg class="spinner-icon" viewBox="0 0 24 24">'
    + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
    + '<svg class="check-icon" viewBox="0 0 24 24">'
    + '<polyline points="20 6 9 17 4 12"/></svg></span>';

/**
 * Build one checklist item element (head + empty/filled detail).
 *
 * @param {Object} opts
 * @param {string} opts.id - Target id (activity or section UUID).
 * @param {string} opts.title - Head label (activity or section title).
 * @param {string} [opts.iconType] - Activity type for the monologo icon (activity items only).
 * @param {string} [opts.detailHtml] - Pre-rendered detail HTML (reload), else empty (live).
 * @param {boolean} [opts.done] - Render in the done (check) state.
 * @returns {HTMLElement}
 */
const buildItem = ({id, title, iconType, detailHtml = '', done = false}) => {
    const item = document.createElement('li');
    item.className = 'courseai-checklist-item ' + (done ? 'is-done' : 'is-loading');
    item.setAttribute('data-regen-id', String(id || ''));
    const icon = iconType
        ? '<img class="cg-activity-icon" src="' + escapeHtml(getActivityIconUrl(iconType))
            + '" alt="" onerror="this.style.display=\'none\'">'
        : '';
    item.innerHTML = '<div class="courseai-checklist-head">'
        + checkMarkup()
        + icon
        + '<span class="courseai-checklist-name">' + escapeHtml(String(title || '')) + '</span>'
        + '</div>'
        + '<div class="courseai-checklist-detail cg-log-md">' + detailHtml + '</div>';
    return item;
};

/**
 * Create the regen block container and append it to the post-review feed.
 *
 * @returns {HTMLElement|null} The <ul> list, or null when the feed is missing.
 */
const createBlock = () => {
    const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
    if (!feed) {
        return null;
    }
    const container = document.createElement('div');
    container.className = 'courseai-checklist cg-regen-block';
    const list = document.createElement('ul');
    list.className = 'courseai-checklist-list';
    container.appendChild(list);
    feed.appendChild(container);
    return list;
};

/**
 * Locate an activity (and its section) by id anywhere in a plan tree.
 *
 * @param {Array} plan
 * @param {string} activityId
 * @returns {Object|null} The activity object, or null.
 */
const findActivity = (plan, activityId) => {
    for (const section of plan || []) {
        for (const activity of (section.activities || [])) {
            if (activity && activity.id === activityId) {
                return activity;
            }
        }
    }
    return null;
};

/**
 * @returns {boolean} Whether a live regen block is currently open (streaming).
 */
export const isRegenActive = () => activeList !== null;

/**
 * Start a live ACTIVITY-replan block: one spinner item per target activity,
 * head = icon + activity title (read from the current plan, since a replan keeps
 * the activity's id/title/type and only regenerates its detail).
 *
 * @param {Object} params
 * @param {string[]} params.targetIds - Activity UUIDs being regenerated.
 * @param {Array} params.plan - Current plan tree (for titles/types).
 * @returns {void}
 */
export const startRegenActivities = ({targetIds, plan}) => {
    const list = createBlock();
    if (!list) {
        return;
    }
    activeList = list;
    activeItems = new Map();
    activeDesc = new Map();
    (targetIds || []).forEach((id) => {
        const activity = findActivity(plan, id);
        // A replan keeps the activity's description (only its detailed_plan is
        // regenerated). Capture it now so the live body matches the reload body
        // (the detailed_plan_activity event does NOT carry the description).
        activeDesc.set(id, (activity && activity.description) || '');
        const item = buildItem({
            id,
            title: (activity && activity.title) || 'Activity',
            iconType: (activity && activity.activity_type) || '',
            done: false,
        });
        list.appendChild(item);
        activeItems.set(id, item);
    });
};

/**
 * Fill a streaming activity item's detail and flip it to done, as each
 * ``detailed_plan_activity`` event arrives during an activity replan.
 *
 * @param {Object} data - { activity_id, title, description, data: <detailedPlan> }
 * @returns {void}
 */
export const regenOnActivityDetail = (data) => {
    if (!data || !activeItems.size) {
        return;
    }
    const item = activeItems.get(data.activity_id);
    if (!item) {
        return;
    }
    const bodyMd = formatActivityDetailMd({
        description: activeDesc.get(data.activity_id) || '',
        detailedPlan: data.data || null,
    });
    const detail = item.querySelector('.courseai-checklist-detail');
    if (detail) {
        detail.innerHTML = renderMarkdown(bodyMd);
    }
    item.classList.remove('is-loading');
    item.classList.add('is-done');
};

/**
 * Finalize the live regen block: clamp every item's detail (the round settled).
 *
 * @returns {void}
 */
export const finalizeRegen = () => {
    if (!activeList) {
        return;
    }
    activeList.querySelectorAll('.courseai-checklist-detail').forEach((el) => clampDetail(el));
    activeList = null;
    activeItems = new Map();
    activeDesc = new Map();
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
        const sections = (plan || []).filter((s) => s && !s.deleted && ids.indexOf(s.id) !== -1);
        sections.forEach((section) => {
            const md = renderMarkdown(formatSectionMd({
                name: '',
                description: section.description || '',
                activities: (section.activities || [])
                    .filter((a) => !a.deleted)
                    .map((a) => ({
                        title: a.title,
                        activity_type: a.activity_type,
                        description: a.description || '',
                        detailedPlan: a.detailed_plan || null,
                    })),
            }));
            const item = buildItem({id: section.id, title: section.name || '', detailHtml: md, done: true});
            list.appendChild(item);
            clampDetail(item.querySelector('.courseai-checklist-detail'));
        });
        return;
    }
    // replan_activity
    ids.forEach((id) => {
        const activity = findActivity(plan, id);
        if (!activity) {
            return;
        }
        const md = renderMarkdown(formatActivityDetailMd({
            description: activity.description || '',
            detailedPlan: activity.detailed_plan || null,
        }));
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
