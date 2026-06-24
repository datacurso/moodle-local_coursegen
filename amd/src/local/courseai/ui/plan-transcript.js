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
 * Per-section Markdown DETAIL under each section checklist item.
 *
 * The section checklist is unchanged: each section shows its name with a spinner
 * (left) that flips to a check when its activities are all detailed. This module
 * ONLY fills the Markdown detail that sits BELOW each section's name — the
 * section's description + its activities (+ each activity's detailed plan) — and
 * clamps it with a "Show more"/"Show less" toggle, exactly like the old single
 * block but now split per section. It updates in real time as the stream arrives
 * and is rebuilt identically on reload.
 *
 * Self-contained: stream handlers import and call these directly (no ctx). State
 * is module-level (one wizard per page) and cleared by resetTranscript per round.
 *
 * @module     local_coursegen/local/courseai/ui/plan-transcript
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderMarkdown, formatSectionMd} from 'local_coursegen/local/courseai/ui/markdown';

/** Collapsed max-height (px) before the detail fades + shows a "Show more" toggle. */
const CLAMP_PX = 200;

/** @type {Map<string, Object>} sectionId -> accumulated section data. */
const sections = new Map();

/**
 * Locate a section's detail container (below its checklist item name).
 *
 * @param {string} sectionId
 * @returns {HTMLElement|null}
 */
const detailEl = (sectionId) => document.querySelector(
    '.courseai-checklist-item[data-section-id="' + sectionId + '"] .courseai-checklist-detail'
);

/**
 * Attach a "Show more"/"Show less" clamp to a detail block once it overflows.
 * Mirrors the long-message fade+expand used elsewhere (160–200px + mask).
 *
 * @param {HTMLElement} el - The detail container.
 * @returns {void}
 */
const clampDetail = (el) => {
    if (!el || el.dataset.cgClamp) {
        return;
    }
    window.requestAnimationFrame(() => {
        if (el.scrollHeight <= CLAMP_PX + 4) {
            return;
        }
        el.dataset.cgClamp = '1';
        el.classList.add('cg-detail-clamped');
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'cg-log-toggle cg-detail-toggle';
        toggle.innerHTML = '<span class="cg-log-toggle-text">Show more</span>'
            + '<span class="cg-log-toggle-chevron" aria-hidden="true">⌄</span>';
        toggle.addEventListener('click', () => {
            const expanded = el.classList.toggle('is-expanded');
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const label = toggle.querySelector('.cg-log-toggle-text');
            if (label) {
                label.textContent = expanded ? 'Show less' : 'Show more';
            }
        });
        el.insertAdjacentElement('afterend', toggle);
    });
};

/**
 * Render one section's accumulated data into its detail container as Markdown.
 *
 * @param {string} sectionId
 * @returns {void}
 */
const renderDetail = (sectionId) => {
    const entry = sections.get(sectionId);
    const target = detailEl(sectionId);
    if (!entry || !target) {
        return;
    }
    const md = formatSectionMd({
        // The section NAME is already the checklist item label — don't repeat it
        // in the detail; show only the description + activities.
        name: '',
        description: entry.description,
        activities: entry.activityOrder.map((id) => entry.activities.get(id)),
    });
    target.innerHTML = renderMarkdown(md);
};

/**
 * Clear accumulated state at the start of a fresh planning round.
 *
 * @returns {void}
 */
export const resetTranscript = () => {
    sections.clear();
};

/**
 * Handle a 'section' event: start accumulating this section's detail.
 *
 * @param {Object} data - {id, name, description}
 * @returns {void}
 */
export const transcriptOnSection = (data) => {
    if (!data || !data.id) {
        return;
    }
    let entry = sections.get(data.id);
    if (!entry) {
        entry = {
            description: '', activities: new Map(), activityOrder: [],
            expected: 0, planned: 0,
        };
        sections.set(data.id, entry);
    }
    entry.description = data.description || entry.description;
    renderDetail(data.id);
};

/**
 * Handle an 'activity' event: add it to its section's detail.
 *
 * @param {Object} data - {id, section_id, title, activity_type, description, deleted}
 * @returns {void}
 */
export const transcriptOnActivity = (data) => {
    if (!data || !data.section_id || data.deleted) {
        return;
    }
    const entry = sections.get(data.section_id);
    if (!entry) {
        return;
    }
    if (!entry.activities.has(data.id)) {
        entry.activityOrder.push(data.id);
        entry.expected += 1;
    }
    entry.activities.set(data.id, {
        title: data.title,
        activity_type: data.activity_type,
        description: data.description || '',
        detailedPlan: (entry.activities.get(data.id) || {}).detailedPlan || null,
    });
    renderDetail(data.section_id);
};

/**
 * Handle a 'detailed_plan_activity' event: attach the full detailed plan and,
 * once every activity in the section is planned, clamp the detail.
 *
 * @param {Object} data - {activity_id, section_id, data}
 * @returns {void}
 */
export const transcriptOnActivityDetail = (data) => {
    if (!data || !data.section_id) {
        return;
    }
    const entry = sections.get(data.section_id);
    if (!entry) {
        return;
    }
    const activity = entry.activities.get(data.activity_id);
    if (activity) {
        activity.detailedPlan = data.data || activity.detailedPlan || null;
    }
    entry.planned += 1;
    renderDetail(data.section_id);
    if (entry.expected > 0 && entry.planned >= entry.expected) {
        clampDetail(detailEl(data.section_id));
    }
};

/** @returns {boolean} Whether any section detail was accumulated this round. */
export const transcriptHasContent = () => sections.size > 0;

/**
 * Finalize: clamp every section's detail (the plan has settled).
 *
 * @returns {void}
 */
export const finalizeTranscript = () => {
    sections.forEach((entry, id) => clampDetail(detailEl(id)));
};

/**
 * Build the WHOLE section checklist (items + Markdown details) from an
 * authoritative plan tree — used on RELOAD and to reconcile at review so the
 * panel looks identical to the live build. Each item is rendered done (check);
 * its detail is the section's description + activities as clamped Markdown.
 *
 * @param {Array} plan - Plan sections (each with activities[].detailed_plan).
 * @returns {void}
 */
export const rebuildTranscriptFromPlan = (plan) => {
    const list = document.getElementById('courseaiChecklistList');
    const checklist = document.getElementById('courseaiChecklist');
    if (!list) {
        return;
    }
    const visible = (plan || []).filter(
        (s) => s && !s.deleted && String(s.name || '').trim()
    );
    if (!visible.length) {
        return;
    }
    list.innerHTML = '';
    visible.forEach((section) => {
        const item = document.createElement('li');
        item.className = 'courseai-checklist-item is-done';
        item.setAttribute('data-section-id', String(section.id || ''));
        item.setAttribute('data-remaining', '0');
        const activities = (section.activities || [])
            .filter((a) => !a.deleted)
            .map((a) => ({
                title: a.title,
                activity_type: a.activity_type,
                description: a.description || '',
                detailedPlan: a.detailed_plan || null,
            }));
        const md = renderMarkdown(formatSectionMd({
            name: '', description: section.description || '', activities,
        }));
        item.innerHTML = '<div class="courseai-checklist-head">'
            + '<span class="courseai-checklist-check">'
            + '<svg class="spinner-icon" viewBox="0 0 24 24">'
            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
            + '<svg class="check-icon" viewBox="0 0 24 24">'
            + '<polyline points="20 6 9 17 4 12"/></svg></span>'
            + '<span class="courseai-checklist-name">' + (section.name || '') + '</span>'
            + '</div>'
            + '<div class="courseai-checklist-detail cg-log-md">' + md + '</div>';
        list.appendChild(item);
        clampDetail(item.querySelector('.courseai-checklist-detail'));
    });
    if (checklist) {
        checklist.classList.remove('hidden');
    }
};
