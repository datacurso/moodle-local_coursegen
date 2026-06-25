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
 * @returns {{attachToRow: Function}}
 */
export const wireDragAndDrop = (container, itemSelector, idDataset, onReorder, parentSectionId, canDrag) => {
    let dragSrcEl = null;
    const dragBlocked = () => typeof canDrag === 'function' && !canDrag();

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
        const row = event.currentTarget;
        dragSrcEl = row;
        row.classList.add('dp-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Store the parent section so cross-section drops can be rejected.
        event.dataTransfer.setData('text/plain', parentSectionId || '');
    };

    const onDragOver = (event) => {
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
        if (!dragSrcEl || dragSrcEl === row) {
            return;
        }
        // Reject cross-section activity drops.
        const originSection = event.dataTransfer.getData('text/plain');
        if (parentSectionId !== null && originSection !== (parentSectionId || '')) {
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
        const row = event.currentTarget;
        row.classList.remove('dp-dragging');
        if (dragBlocked()) {
            dragSrcEl = null;
            return;
        }
        container.querySelectorAll(itemSelector).forEach((el) => {
            el.classList.remove('dp-drag-over');
        });
        // The dragged row (its id) is what moved — pass it so the log can name
        // the moved element and its new position, not just the parent.
        const movedId = (row && row.dataset[idDataset]) || null;
        dragSrcEl = null;
        // Collect new order and dispatch.
        const ids = [];
        container.querySelectorAll(itemSelector).forEach((el) => {
            const id = el.dataset[idDataset];
            if (id) {
                ids.push(id);
            }
        });
        if (ids.length > 1) {
            onReorder(ids, movedId);
        }
    };

    const attachToRow = (row) => {
        row.setAttribute('draggable', 'true');
        row.addEventListener('dragstart', onDragStart);
        row.addEventListener('dragover', onDragOver);
        row.addEventListener('dragleave', onDragLeave);
        row.addEventListener('drop', onDrop);
        row.addEventListener('dragend', onDragEnd);
    };

    // Attach to all existing rows immediately.
    container.querySelectorAll(itemSelector).forEach(attachToRow);

    // Return attach so callers can wire newly-created rows.
    return {attachToRow};
};

/**
 * Send a reorder_sections action and log a concise user turn.
 *
 * @param {Object}   ctx              - ui-detailed ctx; reads runPlanAction, log, texts.
 * @param {string[]} targetIds        - Section UUIDs in new DOM order.
 */
export const sendReorderSections = async(ctx, targetIds) => {
    const {runPlanAction, log, texts} = ctx;
    // Reordering is a user action → one concise turn (no « »).
    if (typeof log === 'function') {
        log({
            actor: 'user',
            kind: 'user',
            message: (texts && texts.courseai_log_reordered_sections) || 'You reordered the sections',
        });
    }
    try {
        const pendingAction = {
            action: 'reorder_sections',
            target_ids: targetIds,
        };
        await runPlanAction(pendingAction);
    } catch (e) {
        // Non-fatal: the re-stream on next user action will correct any ordering.
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
        const section = sections.find((s) => s && s.id === sectionId);
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
        };
        await runPlanAction(pendingAction);
    } catch (e) {
        // Non-fatal.
    }
};
