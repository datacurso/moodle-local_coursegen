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
 * High-level view orchestration for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/view
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {normalizeInitialSections} from './normalize';
import {markActivityPlanned, clearSectionEntries, ensureDetailedEntry} from './activity-row';
import {formatImageCount, setImageBadge, updateSectionImageBadge} from './badges';
import {initDetailedPlanView} from './init-view';

export {initDetailedPlanView, flashAddedActivity} from './init-view';

/**
 * Handle a streaming field event for an activity in detailed mode.
 *
 * @param {Object} ctx
 * @param {Object} data
 */
export const handleDetailedPlanField = (ctx, data) => {
    const {state, texts, formatTemplate} = ctx;
    const {prvHeaderSub} = ctx.elements;

    if (state.planningMode !== 'detailed') {
        initDetailedPlanView(ctx, {renderSections: false});
    }

    // On regeneration (round > 1), clear existing section entries once per section.
    const sectionId = data.section_id;
    if (sectionId && (state.generationRound || 0) > 1) {
        const meta = state.detailedSectionMeta[sectionId];
        if (meta && !meta._prepared) {
            meta._prepared = true;
            clearSectionEntries(ctx, sectionId);
        }
    }

    const entry = ensureDetailedEntry(ctx, data);
    if (!entry || entry.done) {
        return;
    }

    if (data.field === 'activity_description' && typeof data.value === 'string') {
        entry.previewDescription = data.value.trim();
    } else if (data.field === 'chapters' && data.item) {
        entry.chapterCount += 1;
    } else if (data.field === 'questions' && data.item) {
        entry.questionCount += 1;
    } else if (data.field === 'image_suggestions' && data.item) {
        entry.imageCount += 1;
        setImageBadge(ctx, entry.imageBadgeEl, entry.imageCount);
        updateSectionImageBadge(ctx, data.section_id);
    } else if (data.field === 'details' && typeof data.value === 'string' && !entry.previewDescription) {
        entry.previewDescription = data.value.trim();
    }

    const summary = [];
    if (entry.chapterCount > 0) {
        summary.push(`${entry.chapterCount} ${texts.courseai_chapters_label}`);
    }
    if (entry.questionCount > 0) {
        summary.push(`${entry.questionCount} ${texts.courseai_questions_label}`);
    }
    if (entry.imageCount > 0) {
        summary.push(formatImageCount(ctx, entry.imageCount));
    }

    let text = entry.previewDescription || texts.courseai_generating_details;
    if (summary.length > 0) {
        text = `${text} (${summary.join(' · ')})`;
    }

    if (entry.progressEl) {
        // Replace skeleton placeholder with real text on first field data.
        if (entry.progressEl.classList.contains('cg-skeleton-wrap')) {
            entry.progressEl.innerHTML = '';
            entry.progressEl.className = 'prv-activity-desc';
            entry.progressEl.removeAttribute('aria-hidden');
        }
        entry.progressEl.textContent = text;
    }

    if (prvHeaderSub) {
        prvHeaderSub.textContent = formatTemplate(texts.courseai_generating_details_for, {
            name: data.title || texts.courseai_activity_default,
        });
    }
};

/**
 * Handle an activity-planned event in detailed mode.
 *
 * @param {Object} ctx
 * @param {Object} data
 */
export const handleDetailedPlanActivity = (ctx, data) => {
    const {state} = ctx;
    if (state.planningMode !== 'detailed') {
        initDetailedPlanView(ctx, {sections: state.latestInitialSections});
    }
    markActivityPlanned(ctx, data);
};

/**
 * Sync state from a fresh sections list without a full re-render.
 *
 * @param {Object} ctx
 * @param {Array}  sections
 */
export const syncDetailedStructureFromSections = (ctx, sections) => {
    const {state} = ctx;
    const normalized = normalizeInitialSections(ctx, sections || []);
    if (!normalized.length) {
        return;
    }
    if (state.planningMode !== 'detailed') {
        initDetailedPlanView(ctx, {sections: normalized, renderSections: false});
    }
    const totalActivities = normalized.reduce(
        (acc, section) => acc + ((section.activities || []).length),
        0
    );
    state.detailedTotal = Math.max(state.detailedTotal || 0, totalActivities);
};

/**
 * Mark the streaming phase as complete and update header chrome.
 *
 * @param {Object} ctx
 */
export const finalizePlanView = (ctx) => {
    const {prvSpinnerIcon, prvCheckIcon, prvHeader, planningSpinner, prvLiveNote} = ctx.elements;

    if (prvSpinnerIcon) {
        prvSpinnerIcon.style.display = 'none';
    }
    if (prvCheckIcon) {
        prvCheckIcon.style.display = '';
    }
    if (prvHeader) {
        prvHeader.classList.remove('prv-header--stream');
        prvHeader.classList.add('prv-header--done');
    }
    if (planningSpinner) {
        planningSpinner.classList.add('done');
    }
    if (prvLiveNote) {
        prvLiveNote.style.display = 'none';
    }
};

/**
 * Enable all action and add controls (called after the stream completes).
 *
 * @param {Object} ctx
 */
export const enableAllActionControls = (ctx) => { // eslint-disable-line no-unused-vars
    document.querySelectorAll('.dp-action-btn--disabled').forEach(function(el) {
        el.classList.remove('dp-action-btn--disabled');
        el.setAttribute('tabindex', '0');
    });
    // Enable add-section and all add-activity controls.
    document.querySelectorAll('.dp-add-control--disabled').forEach(function(el) {
        el.classList.remove('dp-add-control--disabled');
    });
};
