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
 * The checklist inside the template thread: one row per section, each
 * waiting on its own activities, filled in as the plan streams. Free mode
 * plans sections, so a row is a section there; a template already has its
 * sections, so here a row is an activity the AI is planning the content of.
 *
 * thread.js drives the surrounding conversation and calls into this module
 * for anything checklist-shaped.
 *
 * @module     local_coursegen/local/courseai/template/thread_checklist
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {initChecklistCollapse} from 'local_coursegen/courseai/bootstrap/checklist-helpers';
import {clampDetail} from 'local_coursegen/local/courseai/ui/plan-transcript';
import {fillChecklistDetail} from 'local_coursegen/local/courseai/template/plan_review';

let collapseWired = false;
/** What each section has planned so far, so its detail is rebuilt whole. */
const planned = new Map();

/**
 * Empty the checklist, for a run that starts over.
 */
export const resetChecklist = () => {
    const list = document.getElementById('courseaiChecklistList');
    if (list) {
        list.innerHTML = '';
    }
    planned.clear();
    const checklist = document.getElementById('courseaiChecklist');
    if (checklist) {
        checklist.classList.add('hidden');
    }
    if (!collapseWired) {
        // Delegated on the document, so once is enough for the page's life.
        initChecklistCollapse();
        collapseWired = true;
    }
};

/**
 * Open the checklist with one row per section, each waiting on its own
 * activities.
 *
 * @param {Array} sections [{section, name, total}], from the service.
 */
export const openChecklist = (sections) => {
    const checklist = document.getElementById('courseaiChecklist');
    const list = document.getElementById('courseaiChecklistList');
    if (!checklist || !list) {
        return;
    }
    checklist.classList.remove('hidden');
    const rows = sections || [];
    const count = checklist.querySelector('.cg-group-count');
    if (count) {
        count.textContent = rows.length ? String(rows.length) : '';
    }

    rows.forEach((section) => {
        const key = String(section.section);
        if (list.querySelector(`[data-section-key="${key}"]`)) {
            return;
        }
        const item = document.createElement('li');
        item.className = 'courseai-checklist-item is-loading';
        item.setAttribute('data-section-key', key);
        // Free mode counts down the activities a section is still waiting on
        // and flips the row when it reaches zero. Same attribute, same rule.
        item.setAttribute('data-remaining', String(section.total || 0));
        // The same markup free mode builds for its own rows: both icons are
        // always present and the class decides which one shows.
        item.innerHTML = '<div class="courseai-checklist-head">'
            + '<span class="courseai-checklist-check">'
            + '<svg class="spinner-icon" viewBox="0 0 24 24">'
            + '<path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path></svg>'
            + '<svg class="check-icon" viewBox="0 0 24 24">'
            + '<polyline points="20 6 9 17 4 12"></polyline></svg></span>'
            + '<span class="courseai-checklist-name"></span>'
            + '</div>'
            + '<div class="courseai-checklist-detail cg-log-md"></div>';
        item.querySelector('.courseai-checklist-name').textContent = section.name || '';
        list.appendChild(item);
    });
};

/**
 * Find, or open, one activity's place inside its section.
 *
 * @param {Object} event Any plan event, carrying cmid, name and section.
 * @returns {Object|null} The entry being built, or null with no row yet.
 */
const entryFor = (event) => {
    const list = document.getElementById('courseaiChecklistList');
    const key = String(event.section);
    if (!list || !list.querySelector(`[data-section-key="${key}"]`)) {
        return null;
    }
    const entries = planned.get(key) || [];
    let entry = entries.find((existing) => existing.cmid === event.cmid);
    if (!entry) {
        entry = {cmid: event.cmid, name: event.name || '', section: event.section, summary: '', parts: []};
        entries.push(entry);
        planned.set(key, entries);
    }
    return entry;
};

/**
 * Repaint one section's detail from what it has so far.
 *
 * @param {string|number} section
 */
const repaint = (section) => {
    const list = document.getElementById('courseaiChecklistList');
    const key = String(section);
    const item = list ? list.querySelector(`[data-section-key="${key}"]`) : null;
    if (item) {
        fillChecklistDetail(item.querySelector('.courseai-checklist-detail'), planned.get(key) || []);
    }
};

/**
 * Show one piece of an activity's plan the moment it is written.
 *
 * @param {Object} event A plan_progress_part event.
 */
export const addPlanPart = (event) => {
    const entry = entryFor(event);
    if (!entry || !event.part) {
        return;
    }
    const at = entry.parts.findIndex((part) => part.key === event.part.key);
    if (at >= 0) {
        entry.parts[at] = event.part;
    } else {
        entry.parts.push(event.part);
    }
    repaint(event.section);
};

/**
 * Show an activity's one-line summary as soon as it arrives.
 *
 * @param {Object} event A plan_progress_summary event.
 */
export const addPlanSummary = (event) => {
    const entry = entryFor(event);
    if (!entry) {
        return;
    }
    entry.summary = event.summary || '';
    repaint(event.section);
};

/**
 * Start an activity's place in its section, so its pieces have somewhere to
 * land as they arrive.
 *
 * @param {Object} event A plan_progress_start event.
 */
export const startPlanEntry = (event) => {
    const entry = entryFor(event);
    if (entry) {
        // A replan rewrites what this activity says, and only this one.
        entry.parts = [];
        entry.summary = '';
        repaint(event.section);
    }
};

/**
 * Record one activity's plan under its section and, once the section has
 * nothing left to wait for, close its row.
 *
 * @param {Object} entry The plan entry for that activity.
 */
export const finishChecklistRow = (entry) => {
    const list = document.getElementById('courseaiChecklistList');
    if (!list) {
        return;
    }
    const key = String(entry.section);
    const item = list.querySelector(`[data-section-key="${key}"]`);
    if (!item) {
        return;
    }

    const entries = planned.get(key) || [];
    const at = entries.findIndex((existing) => existing.cmid === entry.cmid);
    if (at >= 0) {
        // The streamed pieces built this already; the finished entry is the
        // authority on what it ended up saying.
        entries[at] = entry;
    } else {
        entries.push(entry);
    }
    planned.set(key, entries);

    const detail = item.querySelector('.courseai-checklist-detail');
    fillChecklistDetail(detail, entries);

    const remaining = Math.max(0, Number(item.getAttribute('data-remaining') || 0) - 1);
    item.setAttribute('data-remaining', String(remaining));
    if (remaining > 0) {
        return;
    }
    item.className = 'courseai-checklist-item is-done';
    // A plan runs to dozens of lines, so it is folded behind the same
    // "Show more" control free mode folds its own section details behind.
    // The class goes on before the measure so the row never paints at full
    // height for a frame and then collapses.
    if (detail) {
        detail.classList.add('cg-detail-clamped');
        clampDetail(detail);
    }
};
