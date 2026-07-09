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
 * Subsection row factory for the detailed plan UI.
 *
 * A subsection renders NESTED inside its parent section's cmlist using the
 * SAME Moodle "Custom sections" markup the section rows use (section-item
 * card, Boost collapse chevron, sectionname title, activity-count badge), so
 * the preview mirrors how mod_subsection looks in the real course.
 *
 * The row deliberately has no `.activity` class, so the parent section's
 * activity drag-and-drop never captures it, and its nested cmlist is a
 * separate container so parent reorders never mix levels.
 *
 * The action controls reuse the SECTION intents (replan_section /
 * delete_section) with the subsection id as target — the service resolves
 * subsections as sections, so no new action types exist.
 *
 * @module     local_coursegen/local/courseai/detailed/subsection-row
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buildSectionRowSkeleton, buildSectionActionControls} from './section-dom';
import {wireDragAndDrop, sendReorderSections, sendReorderActivities, sendMoveActivity} from './dnd';
import {createAddTriggerBtn} from './icons';
import {openInlineAddPanel} from 'local_coursegen/local/courseai/ui/panel';
import {markProposalTargetPending} from './pending';

/**
 * Build the on-hover "+" insert button used by the subsection divider.
 *
 * @param {string} label - Accessible label/tooltip.
 * @returns {HTMLButtonElement}
 */
const buildInsertBtn = (label) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cg-insert-btn';
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
        + 'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">'
        + '<path d="M12 5v14M5 12h14"/></svg>';
    return btn;
};

/**
 * Toggle the collapse panel (Boost .collapse / .collapsed classes), same
 * behavior as the section rows.
 *
 * @param {HTMLElement} bodyEl - The .content.collapse panel.
 * @param {HTMLAnchorElement} chevron - The icons-collapse-expand toggle anchor.
 */
const toggleCollapse = (bodyEl, chevron) => {
    const isOpen = bodyEl.classList.contains('show');
    bodyEl.classList.toggle('show', !isOpen);
    chevron.classList.toggle('collapsed', isOpen);
    chevron.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
};

/**
 * Count the parent's REAL rendered subsections (transient skeletons excluded).
 *
 * @param {HTMLElement} parentBodyEl - The parent section's cmlist.
 * @returns {number}
 */
const countActiveSubsections = (parentBodyEl) => (parentBodyEl
    ? Array.prototype.filter.call(
        parentBodyEl.children,
        (el) => el.classList.contains('cg-subsection') && !el.hasAttribute('data-cg-transient')
    ).length
    : 0);

/**
 * Compose the add-subsection user turn like the add-activity one does:
 * "{instruction} — «{parent section}», position {N}" (1-based slot). Reload
 * composes the same line from the persisted payload (thread-replay.js), so
 * both sides stay identical.
 *
 * @param {Object} ctx
 * @param {string} sectionId - Parent (top-level) section UUID.
 * @param {string} instruction
 * @param {number} slot0 - Zero-based slot the subsection lands in.
 * @returns {string|null}
 */
const composeAddSubsectionTurn = (ctx, sectionId, instruction, slot0) => {
    const {state, texts} = ctx;
    const parentMeta = state.detailedSectionMeta[sectionId];
    const parentName = (parentMeta && parentMeta.row
        && parentMeta.row.getAttribute('data-sectionname')) || '';
    const instr = String(instruction || '').trim();
    if (!parentName) {
        return instr || null;
    }
    const target = (texts.courseai_log_add_activity_target || '«{$a->section}», position {$a->position}')
        .replace('{$a->section}', parentName)
        .replace('{$a->position}', String(slot0 + 1));
    return instr ? instr + ' — ' + target : target;
};

/**
 * Log the turn, drop a placement skeleton and send the add-subsection intent
 * (add_section WITH the parent section id — the service generates and nests
 * a subsection there).
 *
 * @param {Object} ctx
 * @param {string} sectionId - Parent (top-level) section UUID.
 * @param {HTMLElement} parentBodyEl - The parent section's cmlist.
 * @param {string} value - The user's instruction.
 * @param {number|null} position - Zero-based slot, or null to append.
 */
const submitAddSubsection = async(ctx, sectionId, parentBodyEl, value, position) => {
    const {runPlanAction, log} = ctx;
    const intent = {action: 'add_section', parent_section_id: sectionId, instruction: value};
    if (typeof position === 'number' && position >= 0) {
        intent.position = position;
    }
    const slot0 = (typeof position === 'number' && position >= 0)
        ? position
        : countActiveSubsections(parentBodyEl);
    const turn = composeAddSubsectionTurn(ctx, sectionId, value, slot0);
    if (typeof log === 'function' && turn) {
        log({actor: 'user', kind: 'user', message: turn});
    }
    markProposalTargetPending(ctx, intent);
    try {
        await runPlanAction(intent);
    } catch (e) {
        // Non-fatal: the next action re-streams and corrects state.
    }
};

/** Row icons for the "+ Add" dropdown menu. */
const ACTIVITY_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" '
    + 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
const SUBSECTION_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" '
    + 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
    + '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/></svg>';

/**
 * Build one row of the "+ Add" dropdown menu.
 *
 * @param {string} label
 * @param {string} iconSvg - Inline SVG markup for the row icon.
 * @param {Function} onPick
 * @returns {HTMLButtonElement}
 */
const buildAddMenuItem = (label, iconSvg, onPick) => {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'dp-add-menu__item';
    item.setAttribute('role', 'menuitem');
    item.innerHTML = '<span class="dp-add-menu__icon" aria-hidden="true">' + iconSvg + '</span>';
    const text = document.createElement('span');
    text.textContent = label;
    item.appendChild(text);
    item.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        onPick();
    });
    return item;
};

/**
 * Attach the two-option add menu (Activity or resource / Subsection) to a
 * trigger "+" button: the menu drops below the trigger, closes on pick,
 * outside click or Escape, and manages the trigger's aria state.
 *
 * @param {Object} options
 * @param {Object} options.texts
 * @param {HTMLButtonElement} options.btn  - The "+" trigger.
 * @param {HTMLElement} options.host      - Positioned wrapper the menu appends to.
 * @param {Function} options.onActivity
 * @param {Function} options.onSubsection
 */
const attachAddChoiceMenu = ({texts, btn, host, onActivity, onSubsection}) => {
    const menu = document.createElement('div');
    menu.className = 'dp-add-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    const closeMenu = () => {
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    };
    menu.appendChild(buildAddMenuItem(
        texts.courseai_menu_activity_or_resource || 'Activity or resource',
        ACTIVITY_ICON,
        () => {
            closeMenu();
            onActivity();
        }
    ));
    menu.appendChild(buildAddMenuItem(
        texts.courseai_subsection_label || 'Subsection',
        SUBSECTION_ICON,
        () => {
            closeMenu();
            onSubsection();
        }
    ));
    btn.setAttribute('aria-haspopup', 'menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        menu.hidden = !menu.hidden;
        btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', (event) => {
        if (!menu.hidden && !host.contains(event.target)) {
            closeMenu();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
    host.appendChild(menu);
};

/**
 * Build (once per parent section) the single Moodle-style "+ Add" control:
 * one button on the section's closing line that opens a small menu with
 * "Activity or resource" and "Subsection" — exactly like the real course's
 * "+" between items. It ADOPTS the parent's add-activity prompt panel and
 * hides the old standalone "+ Add activity" wrap, so the two stacked buttons
 * collapse into one control.
 *
 * @param {Object} ctx
 * @param {string} sectionId - Parent (top-level) section UUID.
 * @param {HTMLElement} parentBodyEl - The parent section's cmlist.
 */
const ensureAddSubsectionControl = (ctx, sectionId, parentBodyEl) => {
    const {state, texts, createTextPanel} = ctx;
    const parentMeta = state.detailedSectionMeta[sectionId];
    if (!parentMeta || parentMeta.addSubsectionWrap || !parentBodyEl
            || typeof createTextPanel !== 'function') {
        return;
    }
    const panelApi = createTextPanel({
        texts,
        onSubmit: (value) => submitAddSubsection(ctx, sectionId, parentBodyEl, value, null),
        placeholder: texts.courseai_add_subsection_placeholder || 'Describe the subsection to add…',
    });

    const wrap = document.createElement('li');
    wrap.className = 'dp-add-subsection-wrap';

    // Bare round "+" like Moodle's native divider button — the label lives in
    // aria/title only; the closing dashed line paints on hover (CSS).
    const btn = buildInsertBtn(texts.courseai_btn_add || 'Add');

    wrap.appendChild(btn);
    attachAddChoiceMenu({
        texts,
        btn,
        host: wrap,
        onActivity: () => {
            if (typeof parentMeta.openAddActivityAt === 'function') {
                parentMeta.openAddActivityAt(null, null);
            }
        },
        onSubsection: () => panelApi.open(),
    });
    wrap.appendChild(panelApi.panel);

    // ONE control serves both adds: the "+" menu replaces the parent's
    // standalone "+ Add activity" row. Its prompt panel is adopted into this
    // wrap (so the menu's "Activity or resource" opens it HERE) and the old
    // wrap is hidden — it stays in the DOM as the reconciler/append anchor.
    const addWrap = parentBodyEl.querySelector(':scope > .dp-add-activity-wrap');
    if (addWrap) {
        parentBodyEl.insertBefore(wrap, addWrap);
        if (parentMeta.addActivityPanel) {
            wrap.appendChild(parentMeta.addActivityPanel);
        }
        addWrap.classList.add('dp-add-wrap--merged');
    } else {
        parentBodyEl.appendChild(wrap);
    }
    parentMeta.addSubsectionWrap = wrap;
};

/**
 * Ensure the nested row for a subsection exists inside its parent section.
 * Idempotent: returns the existing meta when already rendered.
 *
 * @param {Object} ctx
 * @param {Object} options
 * @param {string} options.subsectionId
 * @param {string} options.sectionId    - Parent (top-level) section UUID.
 * @param {string} options.name
 * @param {HTMLElement} options.parentBodyEl - The parent section's cmlist.
 * @returns {Object|null} The meta stored in state.detailedSubsectionMeta.
 */
export const ensureSubsectionRendered = (ctx, {subsectionId, sectionId, name, parentBodyEl}) => {
    const {state, texts, runPlanAction, createTextPanel, log} = ctx;
    if (!state.detailedSubsectionMeta) {
        state.detailedSubsectionMeta = {};
    }
    const existing = state.detailedSubsectionMeta[subsectionId];
    if (existing) {
        // Re-offered row (reconcile settle pass): make sure it is wired into
        // the parent's subsection DnD — attachToRow is idempotent.
        const parentMeta = state.detailedSectionMeta[existing.sectionId];
        if (parentMeta && parentMeta.subsectionDnd && existing.row) {
            parentMeta.subsectionDnd.attachToRow(existing.row);
        }
        return existing;
    }
    if (!parentBodyEl) {
        return null;
    }

    const label = name || (texts && texts.courseai_subsection_label) || 'Subsection';
    const renderIndex = Object.keys(state.detailedSubsectionMeta).length;

    // Same skeleton the section rows use: meta badge, Boost collapse chevron,
    // sectionname title, .content.collapse body with its cmlist.
    const {
        metaEl, imagesBadgeEl, bodyEl, cmlistEl, chevronEl, titleEl, actionsEl, rowRef,
    } = buildSectionRowSkeleton(ctx, subsectionId, renderIndex, label, 0);

    // Reuse the section action controls: the service resolves subsection ids
    // for replan_section / delete_section, so the same pipeline applies.
    const {iaControl, deleteControl, sectionPanelApi} = buildSectionActionControls(
        ctx, subsectionId, label, rowRef
    );
    actionsEl.appendChild(metaEl);
    actionsEl.appendChild(imagesBadgeEl);
    actionsEl.appendChild(iaControl);
    actionsEl.appendChild(deleteControl);

    const headerEl = document.createElement('div');
    headerEl.className = 'course-section-header d-flex align-items-center position-relative';
    headerEl.setAttribute('data-for', 'section_title');
    headerEl.setAttribute('data-id', subsectionId);
    headerEl.appendChild(chevronEl);
    headerEl.appendChild(titleEl);
    headerEl.appendChild(actionsEl);

    chevronEl.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleCollapse(bodyEl, chevronEl);
    });

    const sectionItem = document.createElement('div');
    sectionItem.className = 'section-item';
    sectionItem.appendChild(headerEl);
    sectionItem.appendChild(sectionPanelApi.panel);
    sectionItem.appendChild(bodyEl);

    const row = document.createElement('li');
    // cg-subsection marks the nesting level (reconciler / CSS indent); the
    // Moodle classes make Boost style it exactly like a section card.
    row.className = 'cg-subsection section course-section main clearfix';
    row.setAttribute('data-subsection-id', subsectionId);
    row.dataset.subsectionId = subsectionId;

    // On-hover "+" divider ABOVE this subsection (add a subsection at THIS
    // slot), mirroring the between-sections divider and Moodle's add
    // affordance between subsections. Child of the row so it rides along on
    // reorder; CSS hides it on the FIRST subsection.
    const insertZone = document.createElement('div');
    insertZone.className = 'cg-insert-zone cg-insert-zone--subsection';
    insertZone.setAttribute('contenteditable', 'false');
    insertZone.setAttribute('draggable', 'false');
    const insertBtn = buildInsertBtn(texts.courseai_btn_add || 'Add');
    insertZone.appendChild(insertBtn);
    // Same two-option menu the section-closing "+" shows (Activity or
    // resource / Subsection), so every "+" behaves like the real course's.
    attachAddChoiceMenu({
        texts,
        btn: insertBtn,
        host: insertZone,
        onActivity: () => {
            // The activity belongs to the PARENT section (its direct list);
            // the panel opens inline right here at the clicked divider.
            const parentMeta = state.detailedSectionMeta[sectionId];
            if (parentMeta && typeof parentMeta.openAddActivityAt === 'function') {
                parentMeta.openAddActivityAt(null, row);
            }
        },
        onSubsection: () => {
            // Insert BEFORE this subsection: its CURRENT index among the
            // parent's rendered subsections (computed at click time,
            // reorder-safe).
            const subs = Array.prototype.filter.call(
                parentBodyEl.children,
                (el) => el.classList.contains('cg-subsection') && !el.hasAttribute('data-cg-transient')
            );
            const index = subs.indexOf(row);
            openInlineAddPanel({
                anchor: row,
                texts,
                placeholder: texts.courseai_add_subsection_placeholder || 'Describe the subsection to add…',
                onSubmit: (value) => submitAddSubsection(
                    ctx, sectionId, parentBodyEl, value, index >= 0 ? index : null
                ),
            });
        },
    });
    insertZone.addEventListener('dragstart', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });
    row.appendChild(insertZone);

    row.appendChild(sectionItem);
    rowRef.current = row;

    // Subsections always land after the direct activities, before the
    // add-subsection / add-activity sentinels (the fixed direct-first order
    // of the plan). A transient placeholder (an add at a specific position)
    // marks the exact slot: insert IN ITS PLACE, never duplicated.
    const transient = parentBodyEl.querySelector(':scope > [data-cg-transient="section"]');
    const addSubWrap = parentBodyEl.querySelector(':scope > .dp-add-subsection-wrap');
    const addWrap = parentBodyEl.querySelector(':scope > .dp-add-activity-wrap');
    if (transient) {
        parentBodyEl.insertBefore(row, transient);
        transient.remove();
    } else if (addSubWrap) {
        parentBodyEl.insertBefore(row, addSubWrap);
    } else if (addWrap) {
        parentBodyEl.insertBefore(row, addWrap);
    } else {
        parentBodyEl.appendChild(row);
    }

    // "+ Add activity" control at the bottom of this subsection's panel —
    // same flow as the section one, with the SUBSECTION as the parent
    // container (add_activity already resolves subsections server-side).
    let pendingPosition = null;
    const countActiveActivities = () => (cmlistEl
        ? cmlistEl.querySelectorAll('.activity[data-activity-id]:not([data-cg-transient])').length
        : 0);
    const composeAddTurn = (instruction, slot0) => {
        const target = (texts.courseai_log_add_activity_target || '«{$a->section}», position {$a->position}')
            .replace('{$a->section}', label)
            .replace('{$a->position}', String(slot0 + 1));
        const instr = String(instruction || '').trim();
        return instr ? instr + ' — ' + target : target;
    };
    const submitAddActivity = async(value, position) => {
        const intent = {action: 'add_activity', parent_section_id: subsectionId, instruction: value};
        if (typeof position === 'number' && position >= 0) {
            intent.position = position;
        }
        const slot0 = (typeof position === 'number' && position >= 0)
            ? position : countActiveActivities();
        if (typeof log === 'function') {
            log({actor: 'user', kind: 'user', message: composeAddTurn(value, slot0)});
        }
        markProposalTargetPending(ctx, intent);
        try {
            await runPlanAction(intent);
        } catch (e) {
            // Non-fatal: the next action re-streams and corrects state.
        }
    };
    const addActivityPanelApi = typeof createTextPanel === 'function' ? createTextPanel({
        texts,
        onSubmit: (value) => {
            const position = pendingPosition;
            pendingPosition = null;
            return submitAddActivity(value, position);
        },
        placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
    }) : null;
    const openAddActivityAt = (position, anchorEl) => {
        if (anchorEl) {
            openInlineAddPanel({
                anchor: anchorEl,
                texts,
                placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
                onSubmit: (value) => submitAddActivity(value, position),
            });
            return;
        }
        if (!addActivityPanelApi) {
            return;
        }
        pendingPosition = typeof position === 'number' ? position : null;
        addActivityPanelApi.open();
    };
    let addActivityBtn = null;
    if (addActivityPanelApi) {
        addActivityBtn = createAddTriggerBtn(texts.courseai_btn_add_activity || 'Add activity');
        addActivityBtn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            addActivityPanelApi.open();
        });
        const addActivityWrap = document.createElement('li');
        addActivityWrap.className = 'dp-add-activity-wrap';
        addActivityWrap.appendChild(addActivityBtn);
        addActivityWrap.appendChild(addActivityPanelApi.panel);
        cmlistEl.appendChild(addActivityWrap);
    }

    // The parent-level "+ Add subsection" control exists once the section has
    // at least one subsection (this call is idempotent per parent).
    ensureAddSubsectionControl(ctx, sectionId, parentBodyEl);

    // Activity drag-and-drop WITHIN this subsection: same reorder_activities
    // action, with the subsection as the parent container (the service
    // validates targets against it and cross-container drops are rejected by
    // the origin check in the drop handler).
    const activityDnd = wireDragAndDrop(
        cmlistEl,
        '.activity',
        'activityId',
        (ids, movedId) => sendReorderActivities(ctx, subsectionId, ids, movedId),
        subsectionId,
        () => !ctx.state.isStreaming,
        // An activity dragged in from ANOTHER container (its parent section,
        // a sibling subsection, or another section) lands here as a move.
        (movedId, index) => sendMoveActivity(ctx, movedId, subsectionId, index)
    );

    // Subsection drag-and-drop within the PARENT section: reorder_sections
    // with subsection ids (the service reorders them inside their parent).
    // One wirer per parent section, created with the first subsection.
    const parentMeta = state.detailedSectionMeta[sectionId];
    if (parentMeta) {
        if (!parentMeta.subsectionDnd) {
            parentMeta.subsectionDnd = wireDragAndDrop(
                parentBodyEl,
                '.cg-subsection',
                'subsectionId',
                (ids, movedId) => sendReorderSections(ctx, ids, movedId),
                sectionId,
                () => !ctx.state.isStreaming
            );
        } else {
            parentMeta.subsectionDnd.attachToRow(row);
        }
    }

    state.detailedSubsectionMeta[subsectionId] = {
        row,
        listEl: cmlistEl,
        metaEl,
        sectionId,
        name: label,
        activityDnd,
        addActivityBtn,
        // Called by the on-hover "+" divider between this subsection's
        // activities to open the add panel at a given slot.
        openAddActivityAt,
    };
    return state.detailedSubsectionMeta[subsectionId];
};

/**
 * Sync a subsection's activity-count badge to its REAL rendered rows (same
 * approach as refreshSectionMeta for sections).
 *
 * @param {Object} ctx
 * @param {string} subsectionId
 * @returns {void}
 */
export const refreshSubsectionMeta = (ctx, subsectionId) => {
    const {state, texts} = ctx;
    const meta = state.detailedSubsectionMeta && state.detailedSubsectionMeta[subsectionId];
    if (!meta || !meta.metaEl || !meta.listEl) {
        return;
    }
    const count = meta.listEl.querySelectorAll('.activity[data-activity-id]:not([data-cg-transient])').length;
    const label = (texts && texts.courseai_activities_count) || 'activities';
    meta.metaEl.textContent = count + ' ' + label;
};
