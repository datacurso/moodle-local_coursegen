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
 * Diff-based plan reconciler — applies minimal DOM mutations after any plan action.
 *
 * Only elements that genuinely changed (deleted, added, updated, or reordered)
 * receive a highlight animation. Unchanged elements are never touched.
 *
 * Algorithm per reconcile() call:
 *   1. Build active sets from currentPlan (deleted===true items excluded).
 *   2. REMOVE  — fade-and-collapse rendered entries absent from active set.
 *   3. ADD     — create entries present in active set but not yet rendered.
 *   4. UPDATE  — in-place markActivityPlanned + info flash for changed activities.
 *   5. REORDER — insertBefore only nodes that are out of position order.
 *   6. FILL    — markActivityPlanned for newly-added skeletons that carry detail.
 *
 * Initial-render guard: when both state maps are empty at entry time (first
 * review_needed of a session), additions are performed silently (no success flash)
 * to avoid mass-flashing the baseline plan.
 *
 * @module     local_coursegen/local/courseai/detailed/reconcile
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {ensureSectionRendered, ensureActivityRendered} from './sync-helpers';
import {markActivityPlanned, refreshSectionMeta} from './activity-row';
import {appendAddSectionControl} from './section-row';
import {ensureSubsectionRendered, refreshSubsectionMeta} from './subsection-row';
import {focusChange, markRemoving} from 'local_coursegen/local/courseai/ui/highlight';
import {removeVanishedActivities, removeVanishedSections, reorderAll} from './reconcile-dom';
import {removeAllTransientPlaceholders} from './pending';

// ---------------------------------------------------------------------------
// Content signature
// ---------------------------------------------------------------------------

/**
 * Cheap content signature for an activity — title + JSON of detailed_plan.
 * Stored on the state entry as `_sig` to detect regeneration without a deep diff.
 *
 * @param {string}           title
 * @param {Object|undefined} detailedPlan
 * @returns {string}
 */
const activitySig = (title, detailedPlan) => title + '||' + (detailedPlan ? JSON.stringify(detailedPlan) : '');

// ---------------------------------------------------------------------------
// Build active structure
// ---------------------------------------------------------------------------

/**
 * Collect active sections from currentPlan in position order.
 *
 * @param {Array} currentPlan  Raw server plan array.
 * @returns {Array}
 */
const buildActiveStructure = (currentPlan) => {
    const activeSections = currentPlan.filter((s) => s.deleted !== true);
    activeSections.sort((a, b) => (a.position ?? 0) - (b.position ?? 0));

    const mapActivities = (activities) => {
        const active = (activities || []).filter((a) => a.deleted !== true);
        active.sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
        return active.map((a) => ({
            id: a.id,
            position: a.position ?? 0,
            activity_type: a.activity_type || a.type || 'quiz',
            title: a.title || '',
            detailed_plan: a.detailed_plan,
        }));
    };

    return activeSections.map((section) => {
        const activeSubsections = (section.subsections || []).filter((sub) => sub.deleted !== true);
        activeSubsections.sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
        return {
            id: section.id,
            position: section.position ?? 0,
            name: section.name,
            activities: mapActivities(section.activities),
            subsections: activeSubsections.map((sub) => ({
                id: sub.id,
                position: sub.position ?? 0,
                name: sub.name || '',
                activities: mapActivities(sub.activities),
            })),
        };
    });
};

// ---------------------------------------------------------------------------
// Add / update helpers (steps 3 + 4)
// ---------------------------------------------------------------------------

/**
 * Ensure a section exists; flash success on genuinely new entries (unless silent).
 *
 * @param {Object}  ctx
 * @param {Object}  section      Active section descriptor.
 * @param {number}  renderIndex  Position index for createDetailedSectionRow.
 * @param {boolean} silent       When true, skip the success flash.
 */
const addOrKeepSection = (ctx, section, renderIndex, silent) => {
    const {state} = ctx;
    const isNew = !state.detailedSectionMeta[section.id];
    ensureSectionRendered(ctx, section, renderIndex);
    const meta = state.detailedSectionMeta[section.id];
    if (isNew && !silent && meta && meta.row) {
        focusChange(meta.row, 'success');
    }
};

/**
 * Ensure an activity exists; flash success on new entries (unless silent).
 * For existing entries whose content signature changed, update in place and
 * flash info. Unchanged entries are not touched.
 *
 * @param {Object}      ctx
 * @param {Object}      activity      Active activity descriptor.
 * @param {string}      sectionId
 * @param {number}      activityIdx
 * @param {HTMLElement} bodyEl
 * @param {boolean}     silent        Skip success flash when true.
 * @param {string}      [subsectionId] Set when the activity nests inside a subsection.
 * @returns {boolean} True when markActivityPlanned was already called here.
 */
const addOrUpdateActivity = (ctx, activity, sectionId, activityIdx, bodyEl, silent, subsectionId) => {
    const {state} = ctx;
    const existing = state.detailedActivityEls[activity.id];

    if (!existing) {
        ensureActivityRendered(ctx, activity, sectionId, activityIdx, bodyEl, subsectionId);
        const entry = state.detailedActivityEls[activity.id];
        if (!silent && entry && entry.wrap) {
            focusChange(entry.wrap, 'success');
        }
        return false; // detail fill handled in step 6
    }

    const newSig = activitySig(activity.title, activity.detailed_plan);
    if (newSig === (existing._sig || '')) {
        return true; // unchanged — skip entirely
    }

    if (!activity.detailed_plan) {
        existing._sig = newSig; // title changed, no detail to fill
        return true;
    }

    markActivityPlanned(ctx, {
        section_id: sectionId,
        subsection_id: subsectionId || undefined,
        activity_id: activity.id,
        activity_type: activity.activity_type,
        title: activity.title,
        data: activity.detailed_plan,
    });
    existing._sig = newSig;
    if (existing.wrap) {
        focusChange(existing.wrap, 'info');
    }
    return true;
};

/**
 * Fade-remove rendered subsection rows whose ids are absent from the active set.
 * Their nested activity entries are removed beforehand by removeVanishedActivities.
 *
 * @param {Object}      ctx
 * @param {Set<string>} activeSubsectionIds
 * @returns {Promise<void>}
 */
const removeVanishedSubsections = async(ctx, activeSubsectionIds) => {
    const {state} = ctx;
    const rendered = state.detailedSubsectionMeta || {};
    const toRemove = Object.keys(rendered).filter((id) => !activeSubsectionIds.has(id));

    await Promise.all(toRemove.map(async(id) => {
        const meta = rendered[id];
        if (!meta || !meta.row) {
            return;
        }
        await markRemoving(meta.row);
        meta.row.remove();
        delete rendered[id];
    }));
};

// ---------------------------------------------------------------------------
// Fill skeleton activities (step 6)
// ---------------------------------------------------------------------------

/**
 * For active activities with a detailed_plan whose entry is still a skeleton
 * (entry.done === false), call markActivityPlanned to fill the detail.
 *
 * @param {Object} ctx
 * @param {Array}  activeSections
 */
const fillSkeletonActivities = (ctx, activeSections) => {
    const {state} = ctx;
    const fillOne = (sectionId, activity, subsectionId) => {
        if (!activity.detailed_plan) {
            return;
        }
        const entry = state.detailedActivityEls[activity.id];
        if (!entry || entry.done) {
            return;
        }
        markActivityPlanned(ctx, {
            section_id: sectionId,
            subsection_id: subsectionId || undefined,
            activity_id: activity.id,
            activity_type: activity.activity_type,
            title: activity.title,
            data: activity.detailed_plan,
        });
        entry._sig = activitySig(activity.title, activity.detailed_plan);
    };
    activeSections.forEach((section) => {
        section.activities.forEach((activity) => fillOne(section.id, activity));
        (section.subsections || []).forEach((subsection) => {
            subsection.activities.forEach((activity) => fillOne(section.id, activity, subsection.id));
        });
    });
};

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Diff currentPlan against the rendered DOM and apply the minimal set of
 * mutations, animating only what changed.
 *
 * @param {Object} ctx
 * @param {Array}  currentPlan  Raw server plan from the review_needed event.
 * @returns {Promise<void>}
 */
export const reconcilePlan = async(ctx, currentPlan) => {
    const {state} = ctx;

    const activeSections = buildActiveStructure(currentPlan);
    const activeSectionIds = new Set(activeSections.map((s) => s.id));
    const activeActivityIds = new Set(activeSections.flatMap((s) => [
        ...s.activities.map((a) => a.id),
        ...s.subsections.flatMap((sub) => sub.activities.map((a) => a.id)),
    ]));
    const activeSubsectionIds = new Set(
        activeSections.flatMap((s) => s.subsections.map((sub) => sub.id))
    );

    // Initial-render guard: suppress success flashes when maps are empty.
    const isInitialRender = (
        Object.keys(state.detailedSectionMeta).length === 0
        && Object.keys(state.detailedActivityEls).length === 0
    );

    // Step 2 — Remove vanished entries (activities first, then subsections, then sections).
    await removeVanishedActivities(ctx, activeActivityIds);
    await removeVanishedSubsections(ctx, activeSubsectionIds);
    await removeVanishedSections(ctx, activeSectionIds);

    // Steps 3+4 — Add new entries; update changed activities.
    activeSections.forEach((section, sectionIdx) => {
        addOrKeepSection(ctx, section, sectionIdx, isInitialRender);
        const meta = state.detailedSectionMeta[section.id];
        if (!meta) {
            return;
        }
        section.activities.forEach((activity, activityIdx) => {
            addOrUpdateActivity(ctx, activity, section.id, activityIdx, meta.bodyEl, isInitialRender);
        });
        section.subsections.forEach((subsection) => {
            const wasRendered = Boolean(
                state.detailedSubsectionMeta && state.detailedSubsectionMeta[subsection.id]
            );
            const submeta = ensureSubsectionRendered(ctx, {
                subsectionId: subsection.id,
                sectionId: section.id,
                name: subsection.name,
                parentBodyEl: meta.bodyEl,
            });
            if (!submeta) {
                return;
            }
            if (!wasRendered && !isInitialRender && submeta.row) {
                focusChange(submeta.row, 'success');
            }
            subsection.activities.forEach((activity, activityIdx) => {
                addOrUpdateActivity(
                    ctx, activity, section.id, activityIdx, submeta.listEl, isInitialRender, subsection.id
                );
            });
            refreshSubsectionMeta(ctx, subsection.id);
        });
        // Keep subsection rows in position order, after the direct activities and
        // before the add-subsection / add-activity sentinels. Only mutate when out
        // of order. The anchor is the "+ Add subsection" control when present
        // (it sits between the last subsection and the parent's "+ Add activity"),
        // else the add-activity sentinel.
        const addWrap = meta.bodyEl.querySelector(':scope > .dp-add-activity-wrap');
        const addSubWrap = meta.bodyEl.querySelector(':scope > .dp-add-subsection-wrap');
        const anchor = addSubWrap || addWrap;
        if (anchor && section.subsections.length > 0) {
            const desired = section.subsections
                .map((subsection) => state.detailedSubsectionMeta[subsection.id])
                .filter((sub) => sub && sub.row)
                .map((sub) => sub.row);
            const current = Array.prototype.filter.call(
                meta.bodyEl.children, (el) => el.classList.contains('cg-subsection')
            );
            const inOrder = desired.length === current.length
                && desired.every((node, i) => node === current[i])
                && (desired.length === 0 || desired[desired.length - 1].nextElementSibling === anchor);
            if (!inOrder) {
                desired.forEach((node) => meta.bodyEl.insertBefore(node, anchor));
            }
        }
    });

    // Keep the add-section control anchored at the bottom.
    appendAddSectionControl(ctx);

    // Step 5 — Reorder DOM nodes to match position order.
    reorderAll(ctx, activeSections);

    // Step 6 — Fill skeleton activities that now carry a detailed_plan.
    fillSkeletonActivities(ctx, activeSections);

    // Defensive cleanup LAST: the row factories replace a transient placeholder in
    // place (inserting the real row where the placeholder sat, so it lands at the
    // right slot with no append-then-reorder jump). Only placeholders with no real
    // row are removed here — clearing them up-front would rob the factories of the
    // slot marker and reintroduce the jump.
    removeAllTransientPlaceholders();

    // Settle point: sync every section's activity-count badge to its REAL rows (so an
    // added/reloaded section shows "N activities", never a stale "2/0"), AND make sure
    // every section row is wired for drag-and-drop. A section row can be created off the
    // DnD path (ensureDetailedSection, when an activity arrives before its section is
    // rendered), and ensureSectionRendered then early-returns without attaching — so the
    // added section ended up NOT draggable. attachToRow is idempotent, so re-attaching
    // already-wired rows is a no-op.
    activeSections.forEach((section) => {
        refreshSectionMeta(ctx, section.id);
        const meta = state.detailedSectionMeta[section.id];
        if (meta && meta.row && state.sectionDnd) {
            state.sectionDnd.attachToRow(meta.row);
        }
    });
};
