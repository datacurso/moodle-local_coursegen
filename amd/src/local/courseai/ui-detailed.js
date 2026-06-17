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
        sendPlanningFeedback,
        openSSEStream,
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

    const createInlineAdjustmentPanel = ({onSubmit}) => {
        const panel = document.createElement('div');
        panel.className = 'dp-ai-inline';
        panel.style.display = 'none';

        const textarea = document.createElement('textarea');
        textarea.className = 'dp-ai-textarea';
        textarea.placeholder = texts.courseai_adjust_placeholder || '';
        textarea.rows = 2;

        const actions = document.createElement('div');
        actions.className = 'dp-ai-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'dp-ai-btn dp-ai-btn--secondary';
        cancel.textContent = texts.courseai_btn_cancel;

        const send = document.createElement('button');
        send.type = 'button';
        send.className = 'dp-ai-btn dp-ai-btn--primary';
        send.textContent = texts.courseai_btn_send_adjust;

        const closePanel = (event) => {
            event.preventDefault();
            event.stopPropagation();
            panel.style.display = 'none';
            textarea.value = '';
        };

        cancel.addEventListener('click', closePanel);
        send.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const value = textarea.value.trim();
            if (!value) {
                textarea.focus();
                return;
            }
            onSubmit(value);
            panel.style.display = 'none';
            textarea.value = '';
        });

        actions.appendChild(cancel);
        actions.appendChild(send);
        panel.appendChild(textarea);
        panel.appendChild(actions);

        return {
            panel,
            open: () => {
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
                if (panel.style.display !== 'none') {
                    textarea.focus();
                }
            },
        };
    };

    const createImagesDetail = ({entry, sectionId, imageSuggestions}) => {
        // Discarded suggestions stay in the server tree marked deleted; never
        // render them as active cards.
        const activeImages = (imageSuggestions || []).filter((item) => !item.deleted);
        const container = document.createElement('div');
        container.className = 'dp-images-container';

        const header = document.createElement('div');
        header.className = 'dp-images-header';

        const masterCheckbox = document.createElement('input');
        masterCheckbox.type = 'checkbox';
        masterCheckbox.className = 'dp-image-check-master';
        masterCheckbox.checked = true;
        masterCheckbox.disabled = Boolean(state.isStreaming);
        masterCheckbox.setAttribute('aria-label', texts.courseai_images_select_all);

        const headerLabel = document.createElement('label');
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

        headerLabel.appendChild(masterCheckbox);
        headerLabel.appendChild(headerIcon);
        headerLabel.appendChild(headerTitle);
        header.appendChild(headerLabel);
        header.appendChild(headerCount);

        const list = document.createElement('div');
        list.className = 'dp-image-list';

        const checkboxes = [];

        activeImages.forEach((item) => {
            const imageWrap = document.createElement('div');
            imageWrap.className = 'dp-image-wrap';

            const imageCard = document.createElement('label');
            imageCard.className = 'dp-image-card';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'dp-image-check';
            checkbox.checked = state.selectedDetailedImages[item.id] !== false;
            checkbox.disabled = Boolean(state.isStreaming);
            checkbox.setAttribute('aria-label', item.placement || texts.courseai_images_suggested_label);
            imageCard.classList.toggle('dp-image-card--off', !checkbox.checked);

            checkboxes.push({checkbox, card: imageCard, id: item.id});

            checkbox.addEventListener('change', (event) => {
                if (state.isStreaming) {
                    event.preventDefault();
                    event.target.checked = state.selectedDetailedImages[item.id] !== false;
                    return;
                }
                state.selectedDetailedImages[item.id] = event.target.checked;
                imageCard.classList.toggle('dp-image-card--off', !event.target.checked);
                recalculateEntryImageCount(entry, sectionId);

                const allChecked = checkboxes.every((cb) => cb.checkbox.checked);
                masterCheckbox.checked = allChecked;
            });

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
            const imagePanelApi = createInlineAdjustmentPanel({
                onSubmit: async(value) => {
                    if (!sendPlanningFeedback || !item.id) {
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
                        await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                        openSSEStream(state.streamingurl, 0, 'planning');
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
                label: texts.courseai_btn_cancel,
                onActivate: async() => {
                    if (!sendPlanningFeedback || !item.id) {
                        return;
                    }
                    imageWrap.classList.add('dp-item-regenerating');
                    discardControl.classList.add('dp-action-btn--disabled');
                    try {
                        const pendingAction = {
                            action: 'discard_image',
                            target_ids: [item.id],
                        };
                        await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                        openSSEStream(state.streamingurl, 0, 'planning');
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

            imageCard.appendChild(checkbox);
            imageCard.appendChild(body);
            imageWrap.appendChild(imageCard);
            imageWrap.appendChild(imagePanelApi.panel);
            list.appendChild(imageWrap);
        });

        masterCheckbox.addEventListener('change', (event) => {
            if (state.isStreaming) {
                event.preventDefault();
                const allChecked = checkboxes.every((cb) => cb.checkbox.checked);
                masterCheckbox.checked = allChecked;
                return;
            }
            const checked = event.target.checked;
            checkboxes.forEach(({checkbox, card, id}) => {
                checkbox.checked = checked;
                state.selectedDetailedImages[id] = checked;
                card.classList.toggle('dp-image-card--off', !checked);
            });
            recalculateEntryImageCount(entry, sectionId);
        });

        container.appendChild(header);
        container.appendChild(list);

        return container;
    };

    const buildActivityDetailContent = ({parsed, entry}) => {
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
            detailFragment.appendChild(createImagesDetail({
                entry,
                sectionId: entry.sectionId,
                imageSuggestions,
            }));
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

        const sectionPanelApi = createInlineAdjustmentPanel({
            onSubmit: async(value) => {
                if (!sendPlanningFeedback || !state.sessionid || !row) {
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
                    await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                    openSSEStream(state.streamingurl, 0, 'planning');
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

                if (!confirmed || !sendPlanningFeedback || !state.sessionid) {
                    return;
                }

                row.classList.add('dp-item-regenerating');
                deleteControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'delete_section',
                        target_ids: [sectionId],
                    };
                    await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                    openSSEStream(state.streamingurl, 0, 'planning');
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

        row = document.createElement('div');
        row.className = 'prv-section-row';
        row.dataset.sectionId = sectionId;
        row.appendChild(btn);
        row.appendChild(sectionPanelApi.panel);
        row.appendChild(bodyEl);
        prvSections.appendChild(row);

        state.detailedSectionMeta[sectionId] = {
            done: 0,
            total: totalActivities,
            imagesCount: 0,
            metaEl,
            imagesBadgeEl,
            bodyEl,
            row
        };

        return {bodyEl};
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
        const activityPanelApi = createInlineAdjustmentPanel({
            onSubmit: async(value) => {
                if (!sendPlanningFeedback || !state.sessionid) {
                    return;
                }
                wrap.classList.add('dp-item-regenerating');
                iaControl.classList.add('dp-action-btn--disabled');
                try {
                    const pendingAction = {
                        action: 'replan_activity',
                        target_ids: [activityId],
                        instruction: value,
                    };
                    await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                    openSSEStream(state.streamingurl, 0, 'planning');
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
                if (!entry || !sendPlanningFeedback || !state.sessionid) {
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
                    await sendPlanningFeedback({recordid: state.sessionid, pendingAction});
                    openSSEStream(state.streamingurl, 0, 'planning');
                } catch (e) {
                    wrap.classList.remove('dp-item-regenerating');
                    deleteControl.classList.remove('dp-action-btn--disabled');
                }
            },
            disabled: true,
        });

        actionsEl.appendChild(iaControl);
        actionsEl.appendChild(deleteControl);

        wrap.appendChild(item);
        wrap.appendChild(activityPanelApi.panel);
        wrap.appendChild(detailEl);
        bodyEl.appendChild(wrap);

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
            if (typeof state.selectedDetailedImages[suggestionId] === 'undefined') {
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

        const detailContent = buildActivityDetailContent({
            parsed,
            entry,
        });
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
    };

    const setImageSelectionEnabled = (enabled) => {
        const isEnabled = Boolean(enabled);
        document.querySelectorAll('.dp-image-check, .dp-image-check-master').forEach((el) => {
            el.disabled = !isEnabled;
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
        setImageSelectionEnabled,
    };
};
