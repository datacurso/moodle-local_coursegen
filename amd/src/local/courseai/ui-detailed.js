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
 * Detailed planning UI helpers.
 *
 * @module     local_coursegen/local/courseai/ui-detailed
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DeleteCancelModal from 'core/modal_delete_cancel';
import ModalEvents from 'core/modal_events';
import {createTextPanel} from 'local_coursegen/local/courseai/ui/panel';

/**
 * Create detailed planning helpers.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createDetailedUi = (deps) => {
    const {
        state,
        elements,
        activityLabels,
        getActivityIconUrl,
        escapeHtml,
        switchPlanMode,
        setProgress,
        texts,
        formatTemplate,
        runPlanAction,
    } = deps;

    const {
        prvSections,
        planReviewCard,
        prvLiveNote,
        prvSpinnerIcon,
        prvCheckIcon,
        prvHeader,
        prvHeaderSub,
        planningSpinner,
    } = elements;

    const confirmDelete = async({title, body}) => {
        const modal = await DeleteCancelModal.create({title, body});

        return await new Promise((resolve) => {
            let resolved = false;

            modal.getRoot().on(ModalEvents.delete, () => {
                resolved = true;
                resolve(true);
            });

            modal.getRoot().on(ModalEvents.hidden, () => {
                if (!resolved) {
                    resolve(false);
                }
                modal.destroy();
            });

            modal.show();
        });
    };

    const formatImageCount = (count) => {
        if (count === 1) {
            return formatTemplate(texts.courseai_image_count_one, {count: 1});
        }
        return formatTemplate(texts.courseai_image_count_many, {count});
    };

    const setImageBadge = (badgeEl, count) => {
        if (!badgeEl) {
            return;
        }

        if (count > 0) {
            badgeEl.textContent = formatImageCount(count);
            badgeEl.style.display = 'inline-flex';
            return;
        }

        badgeEl.style.display = 'none';
    };

    const updateSectionImageBadge = (sectionId) => {
        const meta = state.detailedSectionMeta[sectionId];
        if (!meta) {
            return;
        }

        const count = Object.values(state.detailedActivityEls).reduce((total, entry) => {
            if (entry.sectionId !== sectionId) {
                return total;
            }
            return total + (entry.imageCount || 0);
        }, 0);

        meta.imagesCount = count;
        setImageBadge(meta.imagesBadgeEl, count);
    };

    const recalculateEntryImageCount = (entry, sectionId) => {
        if (!entry) {
            return;
        }

        const suggestions = Array.isArray(entry.imageSuggestions) ? entry.imageSuggestions : [];
        const activeSuggestions = suggestions.filter((suggestion) => !suggestion.deleted);
        const selectedCount = activeSuggestions.reduce((total, suggestion) => {
            const selected = state.selectedDetailedImages[suggestion.id] !== false;
            return total + (selected ? 1 : 0);
        }, 0);

        entry.imageCount = selectedCount;
        setImageBadge(entry.imageBadgeEl, selectedCount);
        updateSectionImageBadge(sectionId);
        updateDetailedHeaderStats();
    };

    const updateDetailedHeaderStats = () => {
        // Stats tracking kept internally for image badge calculations
        // Header subtitle is managed by stream.js status events
    };

    const createDetailLabel = (text) => {
        const label = document.createElement('p');
        label.className = 'dp-detail-label';
        label.textContent = text;
        return label;
    };

    const iaSparklesSvg = [
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"',
        'stroke="currentColor" stroke-width="2" stroke-linecap="round"',
        'stroke-linejoin="round" aria-hidden="true">',
        '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582' +
            'a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135' +
            'a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581' +
            'a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135' +
            'a.5.5 0 0 1-.962 0z"/>',
        '</svg>'
    ].join(' ');

    const getCoreIconUrl = (iconkey) => {
        if (!iconkey || typeof iconkey !== 'string') {
            return '';
        }
        // eslint-disable-next-line no-undef
        return M.cfg.wwwroot + '/pix/' + iconkey + '.svg';
    };

    /** SVG grip icon for drag handles (six dots, 10×14 px). */
    const gripSvg = [
        '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"',
        'aria-hidden="true">',
        '<circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/>',
        '<circle cx="2" cy="7" r="1.5"/><circle cx="8" cy="7" r="1.5"/>',
        '<circle cx="2" cy="12" r="1.5"/><circle cx="8" cy="12" r="1.5"/>',
        '</svg>'
    ].join(' ');

    /**
     * Create a dashed "+ Add …" trigger button.
     *
     * @param {string} label - Visible button text.
     * @returns {HTMLButtonElement}
     */
    const createAddTriggerBtn = (label) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dp-add-control dp-add-control--disabled';
        const icon = document.createElement('span');
        icon.className = 'dp-add-control__icon';
        icon.textContent = '+';
        icon.setAttribute('aria-hidden', 'true');
        const labelSpan = document.createElement('span');
        labelSpan.textContent = label;
        btn.appendChild(icon);
        btn.appendChild(labelSpan);
        return btn;
    };

    /**
     * Wire drag-and-drop for a container whose direct children are draggable rows.
     *
     * @param {HTMLElement} container       - Parent element whose children will be dragged.
     * @param {string}      itemSelector    - CSS selector matching direct draggable children.
     * @param {string}      idDataset       - dataset property name that holds the UUID (camelCase).
     * @param {Function}    onReorder       - Called with the array of UUIDs in new DOM order.
     * @param {string|null} parentSectionId - Section UUID for activity-level drops; null for sections.
     */
    const wireDragAndDrop = (container, itemSelector, idDataset, onReorder, parentSectionId) => {
        let dragSrcEl = null;

        const onDragStart = (event) => {
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
            // DOM reorder: insert dragSrcEl before the target.
            const parent = row.parentNode;
            parent.insertBefore(dragSrcEl, row);
        };

        const onDragEnd = (event) => {
            event.stopPropagation();
            const row = event.currentTarget;
            row.classList.remove('dp-dragging');
            container.querySelectorAll(itemSelector).forEach((el) => {
                el.classList.remove('dp-drag-over');
            });
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
                onReorder(ids);
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
     * Send a reorder_sections action and re-open the SSE stream.
     *
     * @param {string[]} targetIds - Section UUIDs in new DOM order.
     */
    const sendReorderSections = async(targetIds) => {
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
     * Send a reorder_activities action and re-open the SSE stream.
     *
     * @param {string}   sectionId - Parent section UUID.
     * @param {string[]} targetIds - Activity UUIDs in new DOM order.
     */
    const sendReorderActivities = async(sectionId, targetIds) => {
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

    const createActionControl = ({variant, iconUrl, iconSvg, label, onActivate, disabled}) => {
        const control = document.createElement('span');
        control.className = `dp-action-btn dp-action-btn--${variant}`;
        if (disabled) {
            control.classList.add('dp-action-btn--disabled');
        }
        control.setAttribute('role', 'button');
        control.setAttribute('tabindex', disabled ? '-1' : '0');
        control.setAttribute('aria-label', label);
        control.title = label;
        if (iconSvg) {
            control.innerHTML = `
                <span class="dp-action-icon dp-action-icon--${variant}" aria-hidden="true">${iconSvg}</span>
            `;
        } else {
            control.innerHTML = `
                <img src="${iconUrl}"
                     class="dp-action-icon dp-action-icon--${variant}"
                     alt=""
                     aria-hidden="true"
                     onerror="this.style.display='none'">
            `;
        }

        const activate = (event) => {
            if (control.classList.contains('dp-action-btn--disabled')) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            onActivate();
        };

        control.addEventListener('click', activate);
        control.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                activate(event);
            }
        });

        return control;
    };

    const createImagesDetail = ({imageSuggestions}) => {
        // Images are curated by discard/replan only — there is no per-image
        // selection checkbox. Discarded suggestions stay in the server tree
        // marked deleted; never render them as active cards.
        const activeImages = (imageSuggestions || []).filter((item) => !item.deleted);
        const container = document.createElement('div');
        container.className = 'dp-images-container';

        const header = document.createElement('div');
        header.className = 'dp-images-header';

        const headerLabel = document.createElement('div');
        headerLabel.className = 'dp-images-header-label';

        const headerIcon = document.createElement('span');
        headerIcon.className = 'dp-images-header-icon';
        headerIcon.innerHTML = [
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"',
            'stroke="currentColor" stroke-width="2" stroke-linecap="round"',
            'stroke-linejoin="round" aria-hidden="true">',
            '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>',
            '<circle cx="8.5" cy="8.5" r="1.5"/>',
            '<polyline points="21 15 16 10 5 21"/></svg>'
        ].join(' ');

        const headerTitle = document.createElement('span');
        headerTitle.className = 'dp-images-header-title';
        headerTitle.textContent = texts.courseai_images_suggested_label.toUpperCase();

        const headerCount = document.createElement('span');
        headerCount.className = 'dp-images-header-count';
        headerCount.textContent = `${activeImages.length} ${
            activeImages.length === 1
                ? texts.courseai_image_count_one.replace('{count}', '').trim()
                : texts.courseai_image_count_many.replace('{count}', '').trim()
        }`;

        headerLabel.appendChild(headerIcon);
        headerLabel.appendChild(headerTitle);
        header.appendChild(headerLabel);
        header.appendChild(headerCount);

        const list = document.createElement('div');
        list.className = 'dp-image-list';

        activeImages.forEach((item) => {
            const imageWrap = document.createElement('div');
            imageWrap.className = 'dp-image-wrap';

            const imageCard = document.createElement('div');
            imageCard.className = 'dp-image-card';

            const placement = document.createElement('p');
            placement.className = 'dp-image-placement';
            placement.textContent = item.placement || texts.courseai_activity_default;

            const description = document.createElement('p');
            description.className = 'dp-image-description';
            description.textContent = item.description || '';

            const body = document.createElement('div');
            body.className = 'dp-image-body';

            const imageActions = document.createElement('div');
            imageActions.className = 'dp-item-actions dp-item-actions--image';

            let iaControl = null;
            let discardControl = null;
            const imagePanelApi = createTextPanel({texts,
                onSubmit: async(value) => {
                    if (!item.id) {
                        return;
                    }
                    imageWrap.classList.add('dp-item-regenerating');
                    iaControl.classList.add('dp-action-btn--disabled');
                    try {
                        const pendingAction = {
                            action: 'replan_image',
                            target_ids: [item.id],
                            instruction: value,
                        };
                        await runPlanAction(pendingAction);
                    } catch (e) {
                        imageWrap.classList.remove('dp-item-regenerating');
                        iaControl.classList.remove('dp-action-btn--disabled');
                    }
                },
            });

            iaControl = createActionControl({
                variant: 'ia',
                iconSvg: iaSparklesSvg,
                label: texts.courseai_btn_adjust,
                onActivate: () => imagePanelApi.open(),
                disabled: true,
            });

            discardControl = createActionControl({
                variant: 'delete',
                iconUrl: getCoreIconUrl('t/delete'),
                label: texts.courseai_btn_discard,
                onActivate: async() => {
                    if (!item.id) {
                        return;
                    }
                    imageWrap.classList.add('dp-item-regenerating');
                    discardControl.classList.add('dp-action-btn--disabled');
                    try {
                        const pendingAction = {
                            action: 'discard_image',
                            target_ids: [item.id],
                        };
                        await runPlanAction(pendingAction);
                    } catch (e) {
                        imageWrap.classList.remove('dp-item-regenerating');
                        discardControl.classList.remove('dp-action-btn--disabled');
                    }
                },
                disabled: true,
            });

            imageActions.appendChild(iaControl);
            imageActions.appendChild(discardControl);

            body.appendChild(placement);
            body.appendChild(imageActions);
            body.appendChild(description);

            imageCard.appendChild(body);
            imageWrap.appendChild(imageCard);
            imageWrap.appendChild(imagePanelApi.panel);
            list.appendChild(imageWrap);
        });

        container.appendChild(header);
        container.appendChild(list);

        return container;
    };

    const buildActivityDetailContent = ({parsed}) => {
        const detailFragment = document.createDocumentFragment();

        const chapters = Array.isArray(parsed.chapters) ? parsed.chapters : [];
        if (chapters.length > 0) {
            detailFragment.appendChild(createDetailLabel(texts.courseai_chapters_label));
            const list = document.createElement('ul');
            list.className = 'dp-item-list';
            chapters.forEach((chapter, index) => {
                const item = document.createElement('li');
                item.className = 'dp-item';

                const number = document.createElement('span');
                number.className = 'dp-item-num';
                number.textContent = `${index + 1}.`;

                const body = document.createElement('div');
                const title = document.createElement('p');
                title.className = 'dp-item-title';
                title.textContent = chapter.title || '';
                body.appendChild(title);

                if (chapter.summary) {
                    const sub = document.createElement('p');
                    sub.className = 'dp-item-sub';
                    sub.textContent = chapter.summary;
                    body.appendChild(sub);
                }

                item.appendChild(number);
                item.appendChild(body);
                list.appendChild(item);
            });
            detailFragment.appendChild(list);
        }

        const questions = Array.isArray(parsed.questions) ? parsed.questions : [];
        if (questions.length > 0) {
            detailFragment.appendChild(createDetailLabel(texts.courseai_questions_label));
            const list = document.createElement('ul');
            list.className = 'dp-item-list';
            questions.forEach((question, index) => {
                const item = document.createElement('li');
                item.className = 'dp-item';

                const number = document.createElement('span');
                number.className = 'dp-item-num';
                number.textContent = `${index + 1}.`;

                const body = document.createElement('div');
                const title = document.createElement('p');
                title.className = 'dp-item-title';
                title.textContent = question.question || '';
                body.appendChild(title);

                item.appendChild(number);
                item.appendChild(body);

                if (question.type) {
                    const type = document.createElement('span');
                    type.className = 'dp-q-type';
                    type.textContent = question.type;
                    item.appendChild(type);
                }

                list.appendChild(item);
            });
            detailFragment.appendChild(list);
        }

        const imageSuggestions = Array.isArray(parsed.image_suggestions) ? parsed.image_suggestions : [];
        if (imageSuggestions.length > 0) {
            detailFragment.appendChild(createImagesDetail({imageSuggestions}));
        }

        return detailFragment;
    };

    const normalizeInitialSections = (sections) => {
        // Soft-deleted elements stay in the server tree but must never render
        // (a deleted section/activity must not be offered as an action target).
        const activeSections = (sections || []).filter((section) => !section.deleted);
        return activeSections.map((section, sectionidx) => ({
            id: section.id || `s${sectionidx}`,
            section_index: section.section_index ?? sectionidx,
            position: section.position ?? sectionidx,
            name: section.name || formatTemplate(texts.courseai_section_label, {section: sectionidx + 1, name: ''}),
            description: section.description || '',
            activities: (section.activities || [])
                .filter((activity) => !activity.deleted)
                .map((activity, activityidx) => ({
                    id: activity.id || `s${sectionidx}-a${activityidx}`,
                    position: activity.position ?? activityidx,
                    activity_type: activity.activity_type || activity.type || 'quiz',
                    title: activity.title || activity.name || `${texts.courseai_activity_default} ${activityidx + 1}`,
                    description: activity.description || ''
                }))
        }));
    };

    const createDetailedSectionRow = ({sectionId, renderIndex, sectionName, totalActivities}) => {
        if (!prvSections) {
            return null;
        }

        let row = null;

        const metaEl = document.createElement('p');
        metaEl.className = 'prv-section-meta';
        metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
            done: 0,
            total: totalActivities,
            description: '',
        });

        const imagesBadgeEl = document.createElement('span');
        imagesBadgeEl.className = 'prv-image-pill';
        imagesBadgeEl.style.display = 'none';

        const metaRowEl = document.createElement('div');
        metaRowEl.className = 'prv-section-meta-row';
        metaRowEl.appendChild(metaEl);
        metaRowEl.appendChild(imagesBadgeEl);

        const bodyEl = document.createElement('div');
        bodyEl.className = 'prv-section-body';
        bodyEl.style.display = 'none';

        const chevronEl = document.createElement('span');
        chevronEl.className = 'prv-chevron';
        chevronEl.innerHTML = [
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
            'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
            'stroke-linejoin="round" aria-hidden="true">',
            '<polyline points="9 18 15 12 9 6"/></svg>'
        ].join(' ');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'prv-section-btn';
        btn.innerHTML = `<span class="prv-section-badge">${renderIndex + 1}</span>`;

        const infoDiv = document.createElement('div');
        infoDiv.className = 'prv-section-info';

        const titleEl = document.createElement('p');
        titleEl.className = 'prv-section-title';
        titleEl.textContent = sectionName || formatTemplate(texts.courseai_section_label, {
            section: renderIndex + 1,
            name: '',
        });

        infoDiv.appendChild(titleEl);
        infoDiv.appendChild(metaRowEl);

        const actionsEl = document.createElement('div');
        actionsEl.className = 'dp-item-actions dp-item-actions--section';

        let iaControl = null;
        let deleteControl = null;

        const sectionPanelApi = createTextPanel({texts,
            onSubmit: async(value) => {
                if (!row) {
                    return;
                }
                row.classList.add('dp-item-regenerating');
                iaControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'replan_section',
                        target_ids: [sectionId],
                        instruction: value,
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    row.classList.remove('dp-item-regenerating');
                    iaControl.classList.remove('dp-action-btn--disabled');
                }
            },
        });

        iaControl = createActionControl({
            variant: 'ia',
            iconSvg: iaSparklesSvg,
            label: texts.courseai_btn_adjust,
            onActivate: () => sectionPanelApi.open(),
            disabled: true,
        });

        deleteControl = createActionControl({
            variant: 'delete',
            iconUrl: getCoreIconUrl('t/delete'),
            label: texts.courseai_btn_cancel,
            onActivate: async() => {
                if (!row) {
                    return;
                }

                const confirmed = await confirmDelete({
                    title: texts.courseai_delete_section_confirm_title,
                    body: texts.courseai_delete_section_confirm_body,
                });

                if (!confirmed) {
                    return;
                }

                row.classList.add('dp-item-regenerating');
                deleteControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'delete_section',
                        target_ids: [sectionId],
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    row.classList.remove('dp-item-regenerating');
                    deleteControl.classList.remove('dp-action-btn--disabled');
                }
            },
            disabled: true,
        });

        actionsEl.appendChild(iaControl);
        actionsEl.appendChild(deleteControl);

        btn.appendChild(infoDiv);
        btn.appendChild(actionsEl);
        btn.appendChild(chevronEl);

        btn.addEventListener('click', () => {
            const isOpen = bodyEl.style.display !== 'none';
            bodyEl.style.display = isOpen ? 'none' : 'flex';
            chevronEl.classList.toggle('prv-chevron--open', !isOpen);
        });

        // Drag handle for section row (appears to the left; only it initiates drag).
        const sectionHandle = document.createElement('span');
        sectionHandle.className = 'dp-drag-handle dp-drag-handle--section';
        sectionHandle.innerHTML = gripSvg;
        sectionHandle.setAttribute('aria-label', texts.courseai_drag_handle_label || 'Drag to reorder');
        sectionHandle.setAttribute('role', 'img');

        // "+ Add activity" control at the bottom of this section's body.
        const addActivityPanelApi = createTextPanel({texts,
            onSubmit: async(value) => {
                addActivityBtn.classList.add('dp-add-control--disabled');
                try {
                    const pendingAction = {
                        action: 'add_activity',
                        parent_section_id: sectionId,
                        instruction: value,
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    addActivityBtn.classList.remove('dp-add-control--disabled');
                }
            },
            placeholder: texts.courseai_add_activity_placeholder || 'Describe the activity to add…',
        });

        const addActivityBtn = createAddTriggerBtn(texts.courseai_btn_add_activity || 'Add activity');
        addActivityBtn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            addActivityPanelApi.open();
        });

        const addActivityWrap = document.createElement('div');
        addActivityWrap.className = 'dp-add-activity-wrap';
        addActivityWrap.appendChild(addActivityBtn);
        addActivityWrap.appendChild(addActivityPanelApi.panel);

        bodyEl.appendChild(addActivityWrap);

        row = document.createElement('div');
        row.className = 'prv-section-row';
        row.dataset.sectionId = sectionId;
        row.appendChild(sectionHandle);
        row.appendChild(btn);
        row.appendChild(sectionPanelApi.panel);
        row.appendChild(bodyEl);
        prvSections.appendChild(row);

        // Wire activity drag-and-drop within this section's body.
        // The add-activity wrap is not draggable — only dp-activity-wrap children are.
        const activityDnd = wireDragAndDrop(
            bodyEl,
            '.dp-activity-wrap',
            'activityId',
            (ids) => sendReorderActivities(sectionId, ids),
            sectionId
        );

        state.detailedSectionMeta[sectionId] = {
            done: 0,
            total: totalActivities,
            imagesCount: 0,
            metaEl,
            imagesBadgeEl,
            bodyEl,
            row,
            addActivityBtn,
            activityDnd,
        };

        return {bodyEl, activityDnd};
    };

    const createDetailedActivityRow = ({sectionId, activityId, activityType, activityTitle, bodyEl}) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'prv-activity-item prv-activity-item--pending';
        const iconUrl = getActivityIconUrl(activityType);
        item.innerHTML = `
            <span class="ps-badge ps-badge--${escapeHtml(activityType)}">
                <img src="${iconUrl}" 
                     class="ps-badge-icon" 
                     alt="" 
                     onerror="this.style.display='none'">
                <span class="ps-badge-text">
                    ${escapeHtml(activityLabels[activityType] || activityType)}
                </span>
            </span>
            <div class="prv-activity-text">
                <p class="prv-activity-name">${escapeHtml(activityTitle)}</p>
            </div>
        `;

        const rightEl = document.createElement('div');
        rightEl.className = 'dp-activity-right';

        const imageBadgeEl = document.createElement('span');
        imageBadgeEl.className = 'prv-image-pill prv-image-pill--small';
        imageBadgeEl.style.display = 'none';

        const actionsEl = document.createElement('div');
        actionsEl.className = 'dp-item-actions';

        const chevronEl = document.createElement('span');
        chevronEl.className = 'prv-chevron dp-activity-chevron';
        chevronEl.style.visibility = 'hidden';
        chevronEl.innerHTML = [
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
            'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
            'stroke-linejoin="round" aria-hidden="true">',
            '<polyline points="9 18 15 12 9 6"/></svg>'
        ].join(' ');

        rightEl.appendChild(imageBadgeEl);
        rightEl.appendChild(actionsEl);
        rightEl.appendChild(chevronEl);
        item.appendChild(rightEl);

        const detailEl = document.createElement('div');
        detailEl.className = 'dp-act-detail';
        detailEl.style.display = 'none';

        const wrap = document.createElement('div');
        wrap.className = 'dp-activity-wrap';
        wrap.dataset.activityId = activityId;

        let iaControl = null;
        let deleteControl = null;
        const activityPanelApi = createTextPanel({texts,
            onSubmit: async(value) => {
                wrap.classList.add('dp-item-regenerating');
                iaControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'replan_activity',
                        target_ids: [activityId],
                        instruction: value,
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    wrap.classList.remove('dp-item-regenerating');
                    iaControl.classList.remove('dp-action-btn--disabled');
                }
            },
        });

        iaControl = createActionControl({
            variant: 'ia',
                iconSvg: iaSparklesSvg,
            label: texts.courseai_btn_adjust,
            onActivate: () => activityPanelApi.open(),
            disabled: true,
        });

        deleteControl = createActionControl({
            variant: 'delete',
            iconUrl: getCoreIconUrl('t/delete'),
            label: texts.courseai_btn_cancel,
            onActivate: async() => {
                const entry = state.detailedActivityEls[activityId];
                if (!entry) {
                    return;
                }

                const confirmed = await confirmDelete({
                    title: texts.courseai_delete_activity_confirm_title,
                    body: texts.courseai_delete_activity_confirm_body,
                });

                if (!confirmed) {
                    return;
                }

                wrap.classList.add('dp-item-regenerating');
                deleteControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'delete_activity',
                        target_ids: [activityId],
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    wrap.classList.remove('dp-item-regenerating');
                    deleteControl.classList.remove('dp-action-btn--disabled');
                }
            },
            disabled: true,
        });

        actionsEl.appendChild(iaControl);
        actionsEl.appendChild(deleteControl);

        // Activity drag handle (appears before the item content).
        const activityHandle = document.createElement('span');
        activityHandle.className = 'dp-drag-handle dp-drag-handle--activity';
        activityHandle.innerHTML = gripSvg;
        activityHandle.setAttribute('aria-label', texts.courseai_drag_handle_label || 'Drag to reorder');
        activityHandle.setAttribute('role', 'img');

        wrap.appendChild(activityHandle);
        wrap.appendChild(item);
        wrap.appendChild(activityPanelApi.panel);
        wrap.appendChild(detailEl);

        // Insert before the add-activity wrap (last child of bodyEl when present).
        const addWrap = bodyEl.querySelector('.dp-add-activity-wrap');
        if (addWrap) {
            bodyEl.insertBefore(wrap, addWrap);
        } else {
            bodyEl.appendChild(wrap);
        }

        // Wire this new wrap into the section's existing DnD setup.
        const sectionMeta = state.detailedSectionMeta[sectionId];
        if (sectionMeta && sectionMeta.activityDnd) {
            sectionMeta.activityDnd.attachToRow(wrap);
        }

        const textDiv = item.querySelector('.prv-activity-text');
        const progressEl = document.createElement('p');
        progressEl.className = 'prv-activity-desc';
        progressEl.textContent = texts.courseai_generating_details;
        textDiv.appendChild(progressEl);

        state.detailedActivityEls[activityId] = {
            item,
            wrap,
            textDiv,
            progressEl,
            detailEl,
            imageBadgeEl,
            chevronEl,
            sectionId,
            previewDescription: '',
            chapterCount: 0,
            questionCount: 0,
            imageCount: 0,
            imageSuggestions: [],
            hasDetail: false,
            done: false
        };

        item.addEventListener('click', () => {
            const entry = state.detailedActivityEls[activityId];
            if (!entry || !entry.hasDetail) {
                return;
            }
            const isOpen = entry.detailEl.style.display !== 'none';
            entry.detailEl.style.display = isOpen ? 'none' : 'block';
            entry.chevronEl.classList.toggle('prv-chevron--open', !isOpen);
        });

        return state.detailedActivityEls[activityId];
    };

    const ensureDetailedSection = (sectionId) => {
        let meta = state.detailedSectionMeta[sectionId];
        if (meta) {
            return meta;
        }

        let sectionName = '';
        if (Array.isArray(state.latestInitialSections)) {
            const byId = state.latestInitialSections.find((s) => s.id === sectionId);
            if (byId && typeof byId.name === 'string') {
                sectionName = byId.name;
            }
        }

        if (!sectionName && Array.isArray(state.planSectionsData)) {
            const plannedSection = state.planSectionsData.find((section) => section.id === sectionId);
            if (plannedSection && typeof plannedSection.name === 'string') {
                sectionName = plannedSection.name;
            }
        }

        const renderIndex = Object.keys(state.detailedSectionMeta).length;
        createDetailedSectionRow({
            sectionId,
            sectionIndex: renderIndex,
            renderIndex,
            sectionName: sectionName || formatTemplate(texts.courseai_section_label, {section: renderIndex + 1, name: ''}),
            totalActivities: 0
        });
        meta = state.detailedSectionMeta[sectionId];
        if (meta) {
            meta.bodyEl.style.display = 'flex';
        }
        return meta;
    };

    const ensureDetailedEntry = (data) => {
        const activityId = data.activity_id;
        if (state.detailedActivityEls[activityId]) {
            return state.detailedActivityEls[activityId];
        }

        const sectionId = data.section_id;
        const meta = ensureDetailedSection(sectionId);
        if (!meta) {
            return null;
        }

        meta.total += 1;
        meta.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
            done: meta.done,
            total: meta.total,
            description: '',
        });

        return createDetailedActivityRow({
            sectionId,
            activityId,
            sectionIndex: meta.total - 1,
            activityIndex: meta.total - 1,
            activityType: data.activity_type || 'quiz',
            activityTitle: data.title || texts.courseai_activity_default,
            bodyEl: meta.bodyEl
        });
    };

    /**
     * Build and append the global "+ Add section" control into prvSections.
     * Called once per initDetailedPlanView render (after sections are created).
     * The control is identified by the class dp-add-section-wrap so it is not
     * picked up by the section DnD selector (.prv-section-row).
     */
    const appendAddSectionControl = () => {
        if (!prvSections) {
            return;
        }

        // Remove any previous instance before re-creating.
        const existing = prvSections.querySelector('.dp-add-section-wrap');
        if (existing) {
            existing.remove();
        }

        const addSectionPanelApi = createTextPanel({texts,
            onSubmit: async(value) => {
                addSectionBtn.classList.add('dp-add-control--disabled');
                try {
                    const pendingAction = {
                        action: 'add_section',
                        instruction: value,
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    addSectionBtn.classList.remove('dp-add-control--disabled');
                }
            },
            placeholder: texts.courseai_add_section_placeholder || 'Describe the section to add…',
        });

        const addSectionBtn = createAddTriggerBtn(texts.courseai_btn_add_section || 'Add section');
        addSectionBtn.classList.add('dp-add-control--disabled');
        addSectionBtn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            addSectionPanelApi.open();
        });

        const wrap = document.createElement('div');
        wrap.className = 'dp-add-section-wrap';
        wrap.appendChild(addSectionBtn);
        wrap.appendChild(addSectionPanelApi.panel);
        prvSections.appendChild(wrap);

        // Expose so enableAllActionControls can enable/disable it.
        state.addSectionBtn = addSectionBtn;
    };

    const initDetailedPlanView = (data) => {
        const sourceSections = normalizeInitialSections(data?.sections || []);
        const renderSections = data?.renderSections !== false;

        // Store sections for later use (e.g., partial regeneration)
        // but avoid wiping existing section names when the caller only
        // wants to initialize the container for progressive rendering.
        if (sourceSections.length > 0) {
            state.latestInitialSections = sourceSections;
        }

        if (prvSections) {
            prvSections.innerHTML = '';
        }
        state.detailedActivityEls = {};
        state.detailedSectionMeta = {};
        state.selectedDetailedImages = {};
        state.detailedCurrent = 0;
        state.detailedTotal = data?.total_activities ?? sourceSections.reduce(
            (acc, section) => acc + (section.activities || []).length,
            0
        );

        switchPlanMode('detailed');
        if (planReviewCard) {
            planReviewCard.style.display = '';
        }
        if (prvLiveNote) {
            prvLiveNote.style.display = 'block';
            prvLiveNote.textContent = texts.courseai_live_note_detailed;
        }
        if (prvSpinnerIcon) {
            prvSpinnerIcon.style.display = '';
        }
        if (prvCheckIcon) {
            prvCheckIcon.style.display = 'none';
        }
        if (prvHeader) {
            prvHeader.classList.remove('prv-header--done');
            prvHeader.classList.add('prv-header--stream');
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = '';
        }
        if (planningSpinner) {
            planningSpinner.classList.remove('done');
        }

        if (!renderSections) {
            return;
        }

        sourceSections.forEach((section, renderIdx) => {
            const sectionId = section.id || `s${renderIdx}`;
            const sectionRow = createDetailedSectionRow({
                sectionId,
                sectionIndex: renderIdx,
                renderIndex: renderIdx,
                sectionName: section.name,
                totalActivities: (section.activities || []).length
            });
            if (!sectionRow) {
                return;
            }

            (section.activities || []).forEach((activity, activityIdx) => {
                const activityId = activity.id || `${sectionId}-a${activityIdx}`;
                createDetailedActivityRow({
                    sectionId,
                    activityId,
                    sectionIndex: renderIdx,
                    activityIndex: activityIdx,
                    activityType: activity.activity_type || activity.type || 'quiz',
                    activityTitle: activity.title
                        || activity.name
                        || `${texts.courseai_activity_default} ${activityIdx + 1}`,
                    bodyEl: sectionRow.bodyEl
                });
            });
        });

        // "+ Add section" control — appears after all section rows.
        appendAddSectionControl();

        // Wire section-level drag-and-drop (sections as direct children of prvSections).
        wireDragAndDrop(
            prvSections,
            '.prv-section-row',
            'sectionId',
            (ids) => sendReorderSections(ids),
            null
        );
    };

    const handleDetailedPlanField = (data) => {
        if (state.planningMode !== 'detailed') {
            initDetailedPlanView({renderSections: false});
        }

        // On regeneration (round > 1), clear existing section entries once per section
        const sectionId = data.section_id;
        if (sectionId && (state.generationRound || 0) > 1) {
            const meta = state.detailedSectionMeta[sectionId];
            if (meta && !meta._prepared) {
                meta._prepared = true;
                clearSectionEntries(sectionId);
            }
        }

        const entry = ensureDetailedEntry(data);
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
            setImageBadge(entry.imageBadgeEl, entry.imageCount);
            updateSectionImageBadge(data.section_id);
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
            summary.push(formatImageCount(entry.imageCount));
        }
        let text = entry.previewDescription || texts.courseai_generating_details;
        if (summary.length > 0) {
            text = `${text} (${summary.join(' · ')})`;
        }
        if (entry.progressEl) {
            entry.progressEl.textContent = text;
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.courseai_generating_details_for, {
                name: data.title || texts.courseai_activity_default,
            });
        }
    };

    const markActivityPlanned = (data) => {
        const entry = ensureDetailedEntry(data);
        if (!entry || entry.done) {
            return;
        }

        state.detailedCurrent += 1;
        entry.done = true;
        entry.item.classList.remove('prv-activity-item--pending');
        entry.item.classList.add('prv-activity-item--done');

        // Update progress based on completed activities (cap at 95% to avoid reaching 100% prematurely)
        if (typeof setProgress === 'function') {
            const progress = Math.min(95, (state.detailedCurrent / Math.max(1, state.detailedTotal)) * 100);
            setProgress(progress);
        }

        if (entry.progressEl) {
            entry.progressEl.remove();
            entry.progressEl = null;
        }

        const parsed = data.data || {};
        const imageSuggestions = Array.isArray(parsed.image_suggestions) ? parsed.image_suggestions : [];
        entry.imageSuggestions = imageSuggestions.map((suggestion) => {
            const suggestionId = suggestion.id;
            // selectedDetailedImages is the registry of active (non-discarded)
            // suggestions, used only for image counts; discarded ones drop out.
            if (suggestion.deleted) {
                delete state.selectedDetailedImages[suggestionId];
            } else {
                state.selectedDetailedImages[suggestionId] = true;
            }

            return {
                id: suggestionId,
                placement: suggestion.placement || '',
                description: suggestion.description || '',
                deleted: suggestion.deleted || false,
            };
        });
        parsed.image_suggestions = entry.imageSuggestions;
        recalculateEntryImageCount(entry, data.section_id);

        const descriptionText = parsed.activity_description || entry.previewDescription || '';
        if (descriptionText) {
            const desc = document.createElement('p');
            desc.className = 'prv-activity-desc';
            desc.textContent = descriptionText;
            entry.textDiv.appendChild(desc);
        }

        const detailContent = buildActivityDetailContent({parsed});
        if (detailContent.childNodes.length > 0) {
            entry.detailEl.innerHTML = '';
            entry.detailEl.appendChild(detailContent);
            entry.hasDetail = true;
            entry.item.classList.add('prv-activity-item--has-detail');
            entry.chevronEl.style.visibility = 'visible';
        }

        const sectionId = data.section_id;
        const meta = state.detailedSectionMeta[sectionId];
        if (meta) {
            meta.done += 1;
            meta.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
                done: meta.done,
                total: meta.total,
                description: '',
            });
            setImageBadge(meta.imagesBadgeEl, meta.imagesCount || 0);
        }
        updateDetailedHeaderStats();
    };

    const clearSectionEntries = (sectionId) => {
        // Remove activity entries for this section so they can be recreated on regeneration
        Object.entries(state.detailedActivityEls).forEach(([key, entry]) => {
            if (entry.sectionId === sectionId) {
                if (entry.item && entry.item.parentNode) {
                    entry.item.remove();
                }
                delete state.detailedActivityEls[key];
            }
        });
        // Reset section meta so it starts counting from 0
        if (state.detailedSectionMeta[sectionId]) {
            state.detailedSectionMeta[sectionId].done = 0;
            state.detailedSectionMeta[sectionId].total = 0;
            if (state.detailedSectionMeta[sectionId].metaEl) {
                state.detailedSectionMeta[sectionId].metaEl.textContent = '';
            }
        }
    };

    const handleDetailedPlanActivity = (data) => {
        if (state.planningMode !== 'detailed') {
            initDetailedPlanView({sections: state.latestInitialSections});
        }
        markActivityPlanned(data);
    };

    const syncDetailedStructureFromSections = (sections) => {
        const normalized = normalizeInitialSections(sections || []);
        if (!normalized.length) {
            return;
        }

        if (state.planningMode !== 'detailed') {
            initDetailedPlanView({sections: normalized, renderSections: false});
        }

        const totalActivities = normalized.reduce(
            (acc, section) => acc + ((section.activities || []).length),
            0
        );
        state.detailedTotal = Math.max(state.detailedTotal || 0, totalActivities);
    };

    const finalizePlanView = () => {
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

    const enableAllActionControls = () => {
        document.querySelectorAll('.dp-action-btn--disabled').forEach(function(el) {
            el.classList.remove('dp-action-btn--disabled');
            el.setAttribute('tabindex', '0');
        });
        // Enable add-section and all add-activity controls.
        document.querySelectorAll('.dp-add-control--disabled').forEach(function(el) {
            el.classList.remove('dp-add-control--disabled');
        });
    };

    return {
        normalizeInitialSections,
        initDetailedPlanView,
        finalizePlanView,
        handleDetailedPlanField,
        handleDetailedPlanActivity,
        syncDetailedStructureFromSections,
        updateDetailedHeaderStats,
        enableAllActionControls,
    };
};
