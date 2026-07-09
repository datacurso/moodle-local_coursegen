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
 * Drag-and-drop and reorder helpers for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/dnd
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';

/**
 * The drag currently in flight, shared across ALL wirers so an activity can
 * cross container boundaries (same section, another subsection, another
 * section). Null when nothing is being dragged.
 *
 * @type {{row: HTMLElement, idDataset: string, containerId: string|null, handled: boolean}|null}
 */
let activeDrag = null;

/** Auto-scroll loop state (one drag at a time). */
let autoScrollTimer = null;
let autoScrollDocListener = null;

/**
 * Nearest scrollable ancestor of a node (falls back to the page scroller).
 *
 * @param {HTMLElement} el
 * @returns {HTMLElement}
 */
const findScrollParent = (el) => {
    for (let node = el; node && node !== document.body; node = node.parentElement) {
        const style = window.getComputedStyle(node);
        if (/(auto|scroll)/.test(style.overflowY) && node.scrollHeight > node.clientHeight) {
            return node;
        }
    }
    return document.scrollingElement || document.documentElement;
};

/**
 * Stop the drag auto-scroll loop and its listener.
 */
const stopAutoScroll = () => {
    if (autoScrollTimer !== null) {
        clearInterval(autoScrollTimer);
        autoScrollTimer = null;
    }
    if (autoScrollDocListener) {
        document.removeEventListener('dragover', autoScrollDocListener);
        autoScrollDocListener = null;
    }
};

/**
 * Keep the plan scrollable while a row is being dragged: browsers do not
 * auto-scroll inner overflow containers during HTML5 drags, so long courses
 * were impossible to traverse. While the drag is alive, holding the pointer
 * near the scroller's top/bottom edge scrolls it continuously (the loop runs
 * on a timer because dragover stops firing when the pointer is stationary).
 *
 * @param {HTMLElement} originEl - The dragged row (locates the scroller).
 */
const startAutoScroll = (originEl) => {
    stopAutoScroll();
    const scroller = findScrollParent(originEl);
    const isPage = scroller === document.scrollingElement || scroller === document.documentElement;
    let pointerY = null;
    autoScrollDocListener = (event) => {
        pointerY = event.clientY;
    };
    document.addEventListener('dragover', autoScrollDocListener);
    const EDGE = 80;
    const STEP = 18;
    autoScrollTimer = setInterval(() => {
        if (pointerY === null) {
            return;
        }
        const top = isPage ? 0 : scroller.getBoundingClientRect().top;
        const bottom = isPage ? window.innerHeight : scroller.getBoundingClientRect().bottom;
        if (pointerY < top + EDGE) {
            scroller.scrollTop -= STEP;
        } else if (pointerY > bottom - EDGE) {
            scroller.scrollTop += STEP;
        }
    }, 30);
};

/**
 * Wire drag-and-drop for a container whose direct children are draggable rows.
 *
 * @param {HTMLElement} container       - Parent element whose children will be dragged.
 * @param {string}      itemSelector    - CSS selector matching direct draggable children.
 * @param {string}      idDataset       - dataset property name that holds the UUID (camelCase).
 * @param {Function}    onReorder       - Called with the array of UUIDs in new DOM order.
 * @param {string|null} parentSectionId - Section UUID for activity-level drops; null for sections.
 * @param {Function}    [canDrag]       - Optional predicate; when it returns false, drags are blocked
 *                                        (e.g. while the plan is still streaming).
 * @param {Function}    [onMoveIn]      - Called as (movedId, index, fromContainerId) when an activity
 *                                        from ANOTHER container is dropped into this one. Activity
 *                                        wirers pass it; section/subsection wirers stay same-container.
 * @returns {{attachToRow: Function}}
 */
export const wireDragAndDrop = (container, itemSelector, idDataset, onReorder, parentSectionId, canDrag, onMoveIn) => {
    let dragSrcEl = null;
    // Order snapshot taken when the drag starts, so onDragEnd can tell a real
    // reorder from a no-op (dropping a row back onto its own slot). A no-op must
    // NOT log "You moved X to position N" nor hit the service.
    let orderAtStart = [];
    const dragBlocked = () => typeof canDrag === 'function' && !canDrag();
    // Only DIRECT children count: a section cmlist may hold nested subsection
    // lists whose activity rows must never join the parent's order payload.
    const directItems = () => Array.prototype.filter.call(
        container.children, (el) => el.matches(itemSelector)
    );
    const currentOrder = () => {
        const ids = [];
        directItems().forEach((el) => {
            const id = el.dataset[idDataset];
            if (id) {
                ids.push(id);
            }
        });
        return ids;
    };

    // A drag from ANOTHER wirer that this container can receive: activities
    // move freely between containers (same section, another subsection,
    // another section); sections and subsections stay within their own list.
    const acceptsForeignDrag = () => Boolean(
        activeDrag
        && !dragSrcEl
        && typeof onMoveIn === 'function'
        && activeDrag.idDataset === 'activityId'
        && idDataset === 'activityId'
        && activeDrag.containerId !== parentSectionId
    );

    // Land a foreign activity row at `refIndex logic`: insert the dragged row
    // into THIS container and dispatch the cross-container move.
    const completeForeignDrop = (insertFn) => {
        const moved = activeDrag.row;
        const fromContainerId = activeDrag.containerId;
        insertFn(moved);
        const index = directItems().indexOf(moved);
        activeDrag.handled = true;
        onMoveIn(moved.dataset[idDataset], index >= 0 ? index : null, fromContainerId);
    };

    const onDragStart = (event) => {
        // Reordering is disabled while the plan is streaming (it re-renders and a
        // reorder would race the in-progress stream): cancel the drag outright.
        if (dragBlocked()) {
            event.preventDefault();
            return;
        }
        // Sections contain activity rows; both are draggable. Stop the event
        // here so an activity drag never bubbles to its section's wirer.
        event.stopPropagation();
        // Dragging is an edit intent: hide the review decision card immediately so it
        // is not visible during the drag gesture (a cancelled drag re-shows it in
        // onDragEnd; a real reorder keeps it hidden until the next review settles).
        getDecisionOverlay().hide();
        const row = event.currentTarget;
        dragSrcEl = row;
        activeDrag = {row, idDataset, containerId: parentSectionId, handled: false};
        // Long courses: keep the plan scroller moving while the drag hovers
        // near its top/bottom edge (browsers do not auto-scroll inner
        // overflow containers during HTML5 drags).
        startAutoScroll(row);
        orderAtStart = currentOrder();
        row.classList.add('dp-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', parentSectionId || '');
    };

    const onDragOver = (event) => {
        // Only advertise a drop target for drags this container can take:
        // its own rows, or a foreign ACTIVITY (cross-container move).
        if (!dragSrcEl && !acceptsForeignDrag()) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = 'move';
        const row = event.currentTarget;
        if (row !== dragSrcEl) {
            row.classList.add('dp-drag-over');
        }
    };

    const onDragLeave = (event) => {
        event.stopPropagation();
        event.currentTarget.classList.remove('dp-drag-over');
    };

    const onDrop = (event) => {
        event.preventDefault();
        event.stopPropagation();
        const row = event.currentTarget;
        row.classList.remove('dp-drag-over');
        // Foreign ACTIVITY dropped onto one of this container's rows: land it
        // before/after the target by pointer position and dispatch the move.
        if (acceptsForeignDrag()) {
            const rect = row.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            completeForeignDrop((moved) => {
                row.parentNode.insertBefore(moved, after ? row.nextSibling : row);
            });
            return;
        }
        if (!dragSrcEl || dragSrcEl === row) {
            return;
        }
        // DOM reorder, DIRECTION-AWARE: inserting always-before the target makes
        // it impossible to move an element DOWN past a target (or back to a lower
        // slot) — it'd just land before it again and "stick". So when dragging
        // DOWNWARD (source currently before the target) insert AFTER the target;
        // when dragging UPWARD (source after the target) insert BEFORE it.
        const parent = row.parentNode;
        // draggingDown = the target is a LATER sibling of the dragged row (walk
        // forward from the source until we hit the target). Avoids bitwise
        // compareDocumentPosition (lint) and text-node ambiguity.
        let draggingDown = false;
        for (let node = dragSrcEl.nextSibling; node; node = node.nextSibling) {
            if (node === row) {
                draggingDown = true;
                break;
            }
        }
        parent.insertBefore(dragSrcEl, draggingDown ? row.nextSibling : row);
    };

    const onDragEnd = (event) => {
        event.stopPropagation();
        stopAutoScroll();
        const row = event.currentTarget;
        row.classList.remove('dp-dragging');
        if (dragBlocked()) {
            dragSrcEl = null;
            activeDrag = null;
            return;
        }
        directItems().forEach((el) => {
            el.classList.remove('dp-drag-over');
        });
        // Cross-container move: the destination wirer already dispatched the
        // move_activity action — the source list must NOT also send a reorder
        // for the row that just left it.
        const crossHandled = Boolean(activeDrag && activeDrag.handled);
        activeDrag = null;
        // The dragged row (its id) is what moved — pass it so the log can name
        // the moved element and its new position, not just the parent.
        const movedId = (row && row.dataset[idDataset]) || null;
        dragSrcEl = null;
        if (crossHandled) {
            orderAtStart = [];
            return;
        }
        // Collect new order and dispatch.
        const ids = currentOrder();
        // No-op guard: if the order is unchanged (the row was dropped back onto
        // its own slot), there is nothing to report — skip the log AND the
        // service call so we never show "moved to position N" for a non-move.
        const unchanged = ids.length === orderAtStart.length
            && ids.every((id, i) => id === orderAtStart[i]);
        orderAtStart = [];
        if (ids.length > 1 && !unchanged) {
            onReorder(ids, movedId);
        } else {
            // Cancelled / dropped back in place: no action runs, so bring the review
            // decision card back (it was hidden on dragstart).
            getDecisionOverlay().show();
        }
    };

    // Container-level foreign drop: dropping into the container's empty space
    // (below the last activity, on the add-activity strip…) appends at the
    // end. Row-level drops stopPropagation, so this never double-fires.
    container.addEventListener('dragover', (event) => {
        if (!acceptsForeignDrag()) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    });
    container.addEventListener('drop', (event) => {
        if (!acceptsForeignDrag()) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        completeForeignDrop((moved) => {
            const addWrap = container.querySelector(':scope > .dp-add-activity-wrap');
            if (addWrap) {
                container.insertBefore(moved, addWrap);
            } else {
                container.appendChild(moved);
            }
        });
    });

    const attachToRow = (row) => {
        if (!row) {
            return;
        }
        // Idempotent: a row can be re-offered (e.g. reconcile's settle pass re-attaches
        // every section to catch rows created off the DnD path). Attaching twice would
        // stack duplicate listeners, so wire each row exactly once.
        if (row.dataset.cgDndWired === '1') {
            return;
        }
        row.dataset.cgDndWired = '1';
        row.setAttribute('draggable', 'true');
        row.addEventListener('dragstart', onDragStart);
        row.addEventListener('dragover', onDragOver);
        row.addEventListener('dragleave', onDragLeave);
        row.addEventListener('drop', onDrop);
        row.addEventListener('dragend', onDragEnd);
    };

    // Attach to all existing rows immediately.
    directItems().forEach(attachToRow);

    // Return attach so callers can wire newly-created rows.
    return {attachToRow};
};

/**
 * Send a reorder_sections action and log a concise user turn.
 *
 * @param {Object}   ctx              - ui-detailed ctx; reads runPlanAction, log, texts, state.
 * @param {string[]} targetIds        - Section UUIDs in new DOM order.
 * @param {string}   [movedId]        - UUID of the section the user dragged.
 */
export const sendReorderSections = async(ctx, targetIds, movedId) => {
    const {runPlanAction, log, texts, state} = ctx;
    // Name WHICH section moved and to WHICH (1-based) position; fall back to the
    // generic line when the dragged section can't be resolved (no « »).
    if (typeof log === 'function') {
        const sections = (state && state.latestInitialSections) || [];
        // The dragged element may be a top-level section or a nested subsection
        // (subsections reorder with the same reorder_sections action).
        let moved = sections.find((s) => s && s.id === movedId);
        if (!moved) {
            for (const section of sections) {
                moved = ((section && section.subsections) || []).find((sub) => sub && sub.id === movedId);
                if (moved) {
                    break;
                }
            }
        }
        const movedName = moved && String(moved.name || '').trim();
        const newPos = movedId ? targetIds.indexOf(movedId) + 1 : 0;
        let message;
        if (movedName && newPos > 0) {
            message = ((texts && texts.courseai_log_moved_section)
                || 'You moved section "{$a->name}" to position {$a->position}')
                .replace('{$a->name}', movedName)
                .replace('{$a->position}', String(newPos));
        } else {
            message = (texts && texts.courseai_log_reordered_sections) || 'You reordered the sections';
        }
        log({actor: 'user', kind: 'user', message});
    }
    try {
        const pendingAction = {
            action: 'reorder_sections',
            target_ids: targetIds,
            // WHICH section the user dragged, so the service persists the same
            // "You moved section X to position N" turn (reload === live).
            moved_id: movedId || null,
        };
        await runPlanAction(pendingAction);
    } catch (e) {
        // Non-fatal: the re-stream on next user action will correct any ordering.
    }
};

/**
 * Find a container (top-level section or nested subsection) in the latest
 * plan snapshot.
 *
 * @param {Array} sections - state.latestInitialSections.
 * @param {string} containerId
 * @returns {Object|null}
 */
const findContainer = (sections, containerId) => {
    let container = (sections || []).find((s) => s && s.id === containerId);
    if (!container) {
        for (const section of sections || []) {
            container = ((section && section.subsections) || []).find(
                (sub) => sub && sub.id === containerId
            );
            if (container) {
                break;
            }
        }
    }
    return container || null;
};

/**
 * Send a move_activity action (cross-container drag) and log a user turn.
 *
 * Also updates the moved activity's client-side entry so later actions
 * (replan/delete/divider inserts) resolve its NEW container.
 *
 * @param {Object} ctx               - ui-detailed ctx.
 * @param {string} activityId        - The dragged activity UUID.
 * @param {string} destContainerId   - Destination section or subsection UUID.
 * @param {number|null} position     - Zero-based slot in the destination.
 */
export const sendMoveActivity = async(ctx, activityId, destContainerId, position) => {
    const {runPlanAction, log, texts, state} = ctx;

    // Re-point the activity's entry at its new container so client-side
    // lookups (insert dividers, regen routing) stay correct before the
    // re-stream settles.
    const entry = state.detailedActivityEls && state.detailedActivityEls[activityId];
    const destSubMeta = state.detailedSubsectionMeta && state.detailedSubsectionMeta[destContainerId];
    if (entry) {
        if (destSubMeta) {
            entry.subsectionId = destContainerId;
            entry.sectionId = destSubMeta.sectionId;
        } else {
            entry.subsectionId = null;
            entry.sectionId = destContainerId;
        }
    }

    if (typeof log === 'function') {
        const sections = (state && state.latestInitialSections) || [];
        let movedTitle = '';
        for (const section of sections) {
            const pools = [section && section.activities || []]
                .concat(((section && section.subsections) || []).map((sub) => sub && sub.activities || []));
            for (const pool of pools) {
                const hit = pool.find((a) => a && a.id === activityId);
                if (hit) {
                    movedTitle = String(hit.title || '').trim();
                    break;
                }
            }
            if (movedTitle) {
                break;
            }
        }
        const dest = findContainer(sections, destContainerId);
        const destName = (dest && String(dest.name || '').trim()) || '';
        // Same phrasing the service persists (log_move_activity_to), so the
        // live turn and the reload replay match.
        const message = ((texts && texts.log_move_activity_to) || 'You moved «{title}» to «{section}».')
            .replace('{$a->title}', movedTitle || '?')
            .replace('{$a->section}', destName || '?')
            .replace('{title}', movedTitle || '?')
            .replace('{section}', destName || '?');
        log({actor: 'user', kind: 'user', message});
    }

    try {
        const pendingAction = {
            action: 'move_activity',
            target_ids: [activityId],
            parent_section_id: destContainerId,
        };
        if (typeof position === 'number' && position >= 0) {
            pendingAction.position = position;
        }
        await runPlanAction(pendingAction);
    } catch (e) {
        // Non-fatal: the re-stream on the next action corrects any drift.
    }
};

/**
 * Send a reorder_activities action and log a concise user turn.
 *
 * @param {Object}   ctx              - ui-detailed ctx; reads runPlanAction, log, texts, state.
 * @param {string}   sectionId        - Parent section UUID.
 * @param {string[]} targetIds        - Activity UUIDs in new DOM order.
 * @param {string}   [movedId]        - UUID of the activity the user dragged.
 */
export const sendReorderActivities = async(ctx, sectionId, targetIds, movedId) => {
    const {runPlanAction, log, texts, state} = ctx;
    // Reordering activities is a user action → one concise turn that names WHICH
    // activity moved and to WHICH (1-based) position; falls back to the section
    // name, then a generic line (no « »).
    if (typeof log === 'function') {
        const sections = (state && state.latestInitialSections) || [];
        // The parent container may be a top-level section or a subsection.
        let section = sections.find((s) => s && s.id === sectionId);
        if (!section) {
            for (const candidate of sections) {
                section = ((candidate && candidate.subsections) || []).find((sub) => sub && sub.id === sectionId);
                if (section) {
                    break;
                }
            }
        }
        const sectionName = (section && String(section.name || '').trim()) || '';
        const activities = (section && section.activities) || [];
        const moved = activities.find((a) => a && a.id === movedId);
        const movedTitle = moved && String(moved.title || '').trim();
        const newPos = movedId ? targetIds.indexOf(movedId) + 1 : 0;
        let message;
        if (movedTitle && newPos > 0) {
            message = ((texts && texts.courseai_log_moved_activity)
                || 'You moved "{$a->title}" to position {$a->position}')
                .replace('{$a->title}', movedTitle)
                .replace('{$a->position}', String(newPos));
        } else if (sectionName) {
            message = ((texts && texts.courseai_log_reordered_activities) || 'You reordered the activities in: {$a}')
                .replace('{$a}', sectionName);
        } else {
            message = (texts && texts.courseai_log_reordered_activities_generic) || 'You reordered the activities';
        }
        log({actor: 'user', kind: 'user', message});
    }
    try {
        const pendingAction = {
            action: 'reorder_activities',
            parent_section_id: sectionId,
            target_ids: targetIds,
            // WHICH activity the user dragged, so the service persists the same
            // "You moved X to position N" turn we logged live (reload === live).
            moved_id: movedId || null,
        };
        await runPlanAction(pendingAction);
    } catch (e) {
        // Non-fatal.
    }
};
