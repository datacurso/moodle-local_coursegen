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
 * Live, per-section planning transcript for the LEFT panel.
 *
 * The structure is shown in REAL TIME as the SSE stream arrives, not in one
 * block at the end: each section appears with a loading spinner the moment its
 * ``section`` event lands, then its activities and each activity's detailed plan
 * fill in below it (rendered as scoped Markdown) as the ``activity`` /
 * ``detailed_plan_activity`` events arrive. A section's spinner drops once all
 * its activities have been planned.
 *
 * Self-contained: the stream handlers import and call these functions directly
 * (no ctx threading). State is module-level (one wizard instance per page) and
 * is cleared by ``resetTranscript`` at the start of every stream/round.
 *
 * @module     local_coursegen/local/courseai/ui/plan-transcript
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderMarkdown, formatSectionMd} from 'local_coursegen/local/courseai/ui/markdown';

/** Id of the transcript wrapper injected into the planning feed (#cgLog). */
const WRAP_ID = 'cgPlanTranscript';

/** @type {Map<string, Object>} sectionId -> section render state. */
const sections = new Map();

/**
 * Spinner markup for a section still being detailed.
 *
 * @returns {string}
 */
const spinnerHtml = () => '<span class="cg-plan-spin" aria-hidden="true">'
    + '<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
    + '</span>';

/**
 * Clear the transcript: drop module state and remove the wrapper from the DOM.
 * Called at the start of each stream/round so a fresh plan never stacks on an
 * old one.
 *
 * @returns {void}
 */
export const resetTranscript = () => {
    sections.clear();
    const wrap = document.getElementById(WRAP_ID);
    if (wrap) {
        wrap.remove();
    }
};

/**
 * Get (creating if needed) the transcript wrapper inside the planning feed.
 *
 * @param {Object} texts - Localized strings (for the lead line).
 * @returns {HTMLElement|null}
 */
const ensureWrap = (texts) => {
    let wrap = document.getElementById(WRAP_ID);
    if (wrap) {
        return wrap;
    }
    const feed = document.getElementById('cgLog');
    if (!feed) {
        return null;
    }
    wrap = document.createElement('div');
    wrap.id = WRAP_ID;
    wrap.className = 'cg-plan-transcript';
    const head = document.createElement('div');
    head.className = 'cg-plan-transcript-head';
    head.textContent = (texts && texts.courseai_log_ai_planned_structure)
        || 'Here is the structure I planned for your course';
    wrap.appendChild(head);
    feed.appendChild(wrap);
    // The compact boot checklist/skeleton is replaced by this live transcript.
    const leftSkeleton = document.getElementById('cgLeftSkeleton');
    if (leftSkeleton) {
        leftSkeleton.style.display = 'none';
    }
    return wrap;
};

/**
 * Create (and append) an empty section block + its render state.
 *
 * @param {HTMLElement} wrap      - The transcript wrapper.
 * @param {string}      sectionId - Section UUID.
 * @returns {Object} The section render state.
 */
const createSectionEntry = (wrap, sectionId) => {
    const block = document.createElement('div');
    block.className = 'cg-plan-section is-loading';
    block.setAttribute('data-section-id', sectionId);
    const mdEl = document.createElement('div');
    mdEl.className = 'cg-log-md cg-plan-section-md';
    const spin = document.createElement('span');
    spin.className = 'cg-plan-section-spin';
    spin.innerHTML = spinnerHtml();
    block.appendChild(mdEl);
    block.appendChild(spin);
    wrap.appendChild(block);
    const entry = {
        block, mdEl, spin,
        name: '', description: '',
        activities: new Map(), activityOrder: [],
        expected: 0, planned: 0,
    };
    sections.set(sectionId, entry);
    return entry;
};

/**
 * Drop a section block's loading spinner (its detail has settled).
 *
 * @param {Object} entry - The section render state.
 * @returns {void}
 */
const settleSection = (entry) => {
    entry.block.classList.remove('is-loading');
    if (entry.spin) {
        entry.spin.remove();
        entry.spin = null;
    }
};

/**
 * Re-render one section block's Markdown body from its accumulated data.
 *
 * @param {Object} entry - The section render state.
 * @returns {void}
 */
const renderSection = (entry) => {
    if (!entry || !entry.mdEl) {
        return;
    }
    const md = formatSectionMd({
        name: entry.name,
        description: entry.description,
        activities: entry.activityOrder.map((id) => entry.activities.get(id)),
    });
    entry.mdEl.innerHTML = renderMarkdown(md);
};

/**
 * Handle a ``section`` event: create (or update) the section's transcript block
 * with its heading + description and a loading spinner.
 *
 * @param {Object} data  - The section SSE payload {id, name, description}.
 * @param {Object} texts - Localized strings.
 * @returns {void}
 */
export const transcriptOnSection = (data, texts) => {
    if (!data || !data.id) {
        return;
    }
    const wrap = ensureWrap(texts);
    if (!wrap) {
        return;
    }
    let entry = sections.get(data.id);
    if (!entry) {
        entry = createSectionEntry(wrap, data.id);
    }
    entry.name = data.name || entry.name;
    entry.description = data.description || entry.description;
    renderSection(entry);
    window.requestAnimationFrame(() => {
        entry.block.scrollIntoView({block: 'nearest', inline: 'nearest'});
    });
};

/**
 * Handle an ``activity`` event: add the activity (title/type/description) to its
 * section block and bump the section's expected-activity count.
 *
 * @param {Object} data - The activity SSE payload {id, section_id, title, ...}.
 * @returns {void}
 */
export const transcriptOnActivity = (data) => {
    if (!data || !data.section_id) {
        return;
    }
    const entry = sections.get(data.section_id);
    if (!entry || data.deleted) {
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
    renderSection(entry);
};

/**
 * Handle a ``detailed_plan_activity`` event: attach the activity's full detailed
 * plan, re-render, and drop the section's spinner once every activity is planned.
 *
 * @param {Object} data - The detail SSE payload {activity_id, section_id, data}.
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
    renderSection(entry);
    if (entry.expected > 0 && entry.planned >= entry.expected) {
        settleSection(entry);
    }
};

/**
 * Rebuild the whole transcript from an authoritative plan tree (the
 * ``review_needed`` ``current_plan``). Reconciles in place by section id —
 * existing blocks are updated (no flash), missing ones created, stale ones
 * removed — so the final transcript is always correct regardless of how the
 * round streamed (initial planning, a keepPlan adjust, or a full regeneration).
 * Spinners are dropped: the plan has settled.
 *
 * @param {Array}  plan  - Plan sections (each with activities[].detailed_plan).
 * @param {Object} texts - Localized strings.
 * @returns {void}
 */
export const rebuildTranscriptFromPlan = (plan, texts) => {
    const wrap = ensureWrap(texts);
    if (!wrap) {
        return;
    }
    const seen = new Set();
    (plan || []).forEach((section) => {
        if (!section || section.deleted || !String(section.name || '').trim()) {
            return;
        }
        seen.add(section.id);
        let entry = sections.get(section.id);
        if (!entry) {
            entry = createSectionEntry(wrap, section.id);
        }
        entry.name = section.name;
        entry.description = section.description || '';
        const activities = (section.activities || [])
            .filter((activity) => !activity.deleted)
            .map((activity) => ({
                title: activity.title,
                activity_type: activity.activity_type,
                description: activity.description || '',
                detailedPlan: activity.detailed_plan || null,
            }));
        entry.mdEl.innerHTML = renderMarkdown(formatSectionMd({
            name: entry.name,
            description: entry.description,
            activities,
        }));
        settleSection(entry);
    });
    sections.forEach((entry, id) => {
        if (!seen.has(id)) {
            entry.block.remove();
            sections.delete(id);
        }
    });
};

/**
 * Whether a live transcript was built this stream (so lifecycle handlers know
 * not to also emit the one-shot fallback block).
 *
 * @returns {boolean}
 */
export const transcriptHasContent = () => sections.size > 0;

/**
 * Finalize the transcript: drop every remaining spinner (the plan has settled).
 *
 * @returns {void}
 */
export const finalizeTranscript = () => {
    sections.forEach((entry) => {
        entry.block.classList.remove('is-loading');
        if (entry.spin) {
            entry.spin.remove();
            entry.spin = null;
        }
    });
};
