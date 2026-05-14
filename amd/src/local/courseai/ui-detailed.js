// This file is part of Moodle - http://moodle.org/

/**
 * Detailed planning UI helpers.
 *
 * @module     local_coursegen/local/courseai/ui-detailed
 */

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
    } = deps;

    const {
        prvSections,
        planReviewCard,
        prvLiveNote,
        prvSpinnerIcon,
        prvCheckIcon,
        prvHeader,
        prvHeaderTitle,
        prvHeaderSub,
        planningSpinner,
    } = elements;

    const formatImageCount = (count) => {
        if (count === 1) {
            return formatTemplate(texts.wizard_image_count_one, {count: 1});
        }
        return formatTemplate(texts.wizard_image_count_many, {count});
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

    const updateSectionImageBadge = (sectionIndex) => {
        const meta = state.detailedSectionMeta[sectionIndex];
        if (!meta) {
            return;
        }

        const prefix = `${sectionIndex}-`;
        const count = Object.keys(state.detailedActivityEls).reduce((total, key) => {
            if (!key.startsWith(prefix)) {
                return total;
            }
            const entry = state.detailedActivityEls[key];
            return total + (entry.imageCount || 0);
        }, 0);

        meta.imagesCount = count;
        setImageBadge(meta.imagesBadgeEl, count);
    };

    const recalculateEntryImageCount = (entry, sectionIndex) => {
        if (!entry) {
            return;
        }

        const suggestions = Array.isArray(entry.imageSuggestions) ? entry.imageSuggestions : [];
        const selectedCount = suggestions.reduce((total, suggestion) => {
            const selected = state.selectedDetailedImages[suggestion.id] !== false;
            return total + (selected ? 1 : 0);
        }, 0);

        entry.imageCount = selectedCount;
        setImageBadge(entry.imageBadgeEl, selectedCount);
        updateSectionImageBadge(sectionIndex);
        updateDetailedHeaderStats();
    };

    const updateDetailedHeaderStats = () => {
        if (!prvHeaderSub) {
            return;
        }

        const totalSections = Object.keys(state.detailedSectionMeta).length;
        const totalActivities = state.detailedTotal;

        let totalImages = 0;
        let selectedImages = 0;

        Object.values(state.detailedActivityEls).forEach((entry) => {
            const suggestions = Array.isArray(entry.imageSuggestions) ? entry.imageSuggestions : [];
            totalImages += suggestions.length;
            suggestions.forEach((suggestion) => {
                if (state.selectedDetailedImages[suggestion.id] !== false) {
                    selectedImages += 1;
                }
            });
        });

        if (totalImages > 0) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_detailed_stats, {
                sections: totalSections,
                activities: totalActivities,
                selectedImages,
                totalImages,
            });
        } else if (state.detailedCurrent >= totalActivities && totalActivities > 0) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_detailed_stats, {
                sections: totalSections,
                activities: totalActivities,
                selectedImages: 0,
                totalImages: 0,
            });
        } else {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_detailed_subtitle, {
                current: state.detailedCurrent,
                total: totalActivities,
            });
        }
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

    const createActionControl = ({variant, iconUrl, iconSvg, label, onActivate}) => {
        const control = document.createElement('span');
        control.className = `dp-action-btn dp-action-btn--${variant}`;
        control.setAttribute('role', 'button');
        control.setAttribute('tabindex', '0');
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
        textarea.placeholder = texts.wizard_adjust_placeholder || '';
        textarea.rows = 2;

        const actions = document.createElement('div');
        actions.className = 'dp-ai-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'dp-ai-btn dp-ai-btn--secondary';
        cancel.textContent = texts.wizard_btn_cancel || 'Cancel';

        const send = document.createElement('button');
        send.type = 'button';
        send.className = 'dp-ai-btn dp-ai-btn--primary';
        send.textContent = texts.wizard_btn_send_adjust || 'Send';

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

    const createImagesDetail = ({entry, sectionIndex, imageSuggestions}) => {
        const container = document.createElement('div');
        container.className = 'dp-images-container';

        const header = document.createElement('div');
        header.className = 'dp-images-header';

        const masterCheckbox = document.createElement('input');
        masterCheckbox.type = 'checkbox';
        masterCheckbox.className = 'dp-image-check-master';
        masterCheckbox.checked = true;
        masterCheckbox.setAttribute('aria-label', texts.wizard_images_select_all);

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
        headerTitle.textContent = texts.wizard_images_suggested_label.toUpperCase();

        const headerCount = document.createElement('span');
        headerCount.className = 'dp-images-header-count';
        headerCount.textContent = `${imageSuggestions.length} ${
            imageSuggestions.length === 1
                ? texts.wizard_image_count_one.replace('{count}', '').trim()
                : texts.wizard_image_count_many.replace('{count}', '').trim()
        }`;

        headerLabel.appendChild(masterCheckbox);
        headerLabel.appendChild(headerIcon);
        headerLabel.appendChild(headerTitle);
        header.appendChild(headerLabel);
        header.appendChild(headerCount);

        const list = document.createElement('div');
        list.className = 'dp-image-list';

        const checkboxes = [];

        imageSuggestions.forEach((item) => {
            const imageWrap = document.createElement('div');
            imageWrap.className = 'dp-image-wrap';

            const imageCard = document.createElement('label');
            imageCard.className = 'dp-image-card';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'dp-image-check';
            checkbox.checked = state.selectedDetailedImages[item.id] !== false;
            checkbox.setAttribute('aria-label', item.placement || texts.wizard_images_suggested_label);
            imageCard.classList.toggle('dp-image-card--off', !checkbox.checked);

            checkboxes.push({checkbox, card: imageCard, id: item.id});

            checkbox.addEventListener('change', (event) => {
                state.selectedDetailedImages[item.id] = event.target.checked;
                imageCard.classList.toggle('dp-image-card--off', !event.target.checked);
                recalculateEntryImageCount(entry, sectionIndex);

                const allChecked = checkboxes.every((cb) => cb.checkbox.checked);
                masterCheckbox.checked = allChecked;
            });

            const placement = document.createElement('p');
            placement.className = 'dp-image-placement';
            placement.textContent = item.placement || texts.wizard_activity_default;

            const description = document.createElement('p');
            description.className = 'dp-image-description';
            description.textContent = item.description || '';

            const body = document.createElement('div');
            body.className = 'dp-image-body';

            const imageActions = document.createElement('div');
            imageActions.className = 'dp-item-actions dp-item-actions--image';

            let iaControl = null;
            const imagePanelApi = createInlineAdjustmentPanel({
                onSubmit: () => {
                    imageWrap.classList.add('dp-item-has-adjustment');
                    iaControl.classList.add('is-active');
                },
            });

            iaControl = createActionControl({
                variant: 'ia',
                iconSvg: iaSparklesSvg,
                label: texts.wizard_btn_adjust || 'IA',
                onActivate: () => imagePanelApi.open(),
            });

            imageActions.appendChild(iaControl);

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
            const checked = event.target.checked;
            checkboxes.forEach(({checkbox, card, id}) => {
                checkbox.checked = checked;
                state.selectedDetailedImages[id] = checked;
                card.classList.toggle('dp-image-card--off', !checked);
            });
            recalculateEntryImageCount(entry, sectionIndex);
        });

        container.appendChild(header);
        container.appendChild(list);

        return container;
    };

    const buildActivityDetailContent = ({parsed, entry, sectionIndex}) => {
        const detailFragment = document.createDocumentFragment();

        const chapters = Array.isArray(parsed.chapters) ? parsed.chapters : [];
        if (chapters.length > 0) {
            detailFragment.appendChild(createDetailLabel(texts.wizard_chapters_label));
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
            detailFragment.appendChild(createDetailLabel(texts.wizard_questions_label));
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
                sectionIndex,
                imageSuggestions,
            }));
        }

        return detailFragment;
    };

    const normalizeInitialSections = (sections) => {
        return (sections || []).map((section, sectionidx) => ({
            id: section.id || `s${sectionidx}`,
            section_index: section.section_index ?? sectionidx,
            name: section.name || formatTemplate(texts.wizard_section_label, {section: sectionidx + 1, name: ''}),
            description: section.description || '',
            activities: (section.activities || []).map((activity, activityidx) => ({
                id: activity.id || `s${sectionidx}-a${activityidx}`,
                activity_type: activity.activity_type || activity.type || 'quiz',
                title: activity.title || activity.name || `${texts.wizard_activity_default} ${activityidx + 1}`,
                description: activity.description || ''
            }))
        }));
    };

    const createDetailedSectionRow = ({sectionIndex, renderIndex, sectionName, totalActivities}) => {
        if (!prvSections) {
            return null;
        }

        let row = null;

        const metaEl = document.createElement('p');
        metaEl.className = 'prv-section-meta';
        metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
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
        titleEl.textContent = sectionName || formatTemplate(texts.wizard_section_label, {
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
            onSubmit: () => {
                if (!row) {
                    return;
                }
                row.classList.add('dp-item-has-adjustment');
                iaControl.classList.add('is-active');
            },
        });

        iaControl = createActionControl({
            variant: 'ia',
            iconSvg: iaSparklesSvg,
            label: texts.wizard_btn_adjust || 'IA',
            onActivate: () => sectionPanelApi.open(),
        });

        deleteControl = createActionControl({
            variant: 'delete',
            iconUrl: getCoreIconUrl('t/delete'),
            label: texts.wizard_btn_cancel || 'Delete',
            onActivate: () => {
                if (!row) {
                    return;
                }
                const isDeleted = row.classList.toggle('dp-item-deleted');
                deleteControl.classList.toggle('is-active', isDeleted);
            },
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
        row.appendChild(btn);
        row.appendChild(sectionPanelApi.panel);
        row.appendChild(bodyEl);
        prvSections.appendChild(row);

        state.detailedSectionMeta[sectionIndex] = {
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

    const createDetailedActivityRow = ({sectionIndex, activityIndex, activityType, activityTitle, bodyEl}) => {
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

        let iaControl = null;
        let deleteControl = null;
        const activityPanelApi = createInlineAdjustmentPanel({
            onSubmit: () => {
                wrap.classList.add('dp-item-has-adjustment');
                iaControl.classList.add('is-active');
            },
        });

        iaControl = createActionControl({
            variant: 'ia',
                iconSvg: iaSparklesSvg,
            label: texts.wizard_btn_adjust || 'IA',
            onActivate: () => activityPanelApi.open(),
        });

        deleteControl = createActionControl({
            variant: 'delete',
            iconUrl: getCoreIconUrl('t/delete'),
            label: texts.wizard_btn_cancel || 'Delete',
            onActivate: () => {
                const isDeleted = wrap.classList.toggle('dp-item-deleted');
                deleteControl.classList.toggle('is-active', isDeleted);
            },
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
        progressEl.textContent = texts.wizard_generating_details;
        textDiv.appendChild(progressEl);

        const key = `${sectionIndex}-${activityIndex}`;
        state.detailedActivityEls[key] = {
            item,
            wrap,
            textDiv,
            progressEl,
            detailEl,
            imageBadgeEl,
            chevronEl,
            previewDescription: '',
            chapterCount: 0,
            questionCount: 0,
            imageCount: 0,
            imageSuggestions: [],
            hasDetail: false,
            done: false
        };

        item.addEventListener('click', () => {
            const entry = state.detailedActivityEls[key];
            if (!entry || !entry.hasDetail) {
                return;
            }
            const isOpen = entry.detailEl.style.display !== 'none';
            entry.detailEl.style.display = isOpen ? 'none' : 'block';
            entry.chevronEl.classList.toggle('prv-chevron--open', !isOpen);
        });

        return state.detailedActivityEls[key];
    };

    const ensureDetailedSection = (sectionIndex) => {
        let meta = state.detailedSectionMeta[sectionIndex];
        if (meta) {
            return meta;
        }

        const renderIndex = Object.keys(state.detailedSectionMeta).length;
        createDetailedSectionRow({
            sectionIndex,
            renderIndex,
            sectionName: formatTemplate(texts.wizard_section_label, {section: sectionIndex + 1, name: ''}),
            totalActivities: 0
        });
        meta = state.detailedSectionMeta[sectionIndex];
        if (meta) {
            meta.bodyEl.style.display = 'flex';
        }
        return meta;
    };

    const ensureDetailedEntry = (data) => {
        const key = `${data.section_index}-${data.activity_index}`;
        if (state.detailedActivityEls[key]) {
            return state.detailedActivityEls[key];
        }

        const meta = ensureDetailedSection(data.section_index);
        if (!meta) {
            return null;
        }

        meta.total += 1;
        meta.metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
            done: meta.done,
            total: meta.total,
            description: '',
        });

        return createDetailedActivityRow({
            sectionIndex: data.section_index,
            activityIndex: data.activity_index,
            activityType: data.activity_type || 'quiz',
            activityTitle: data.title || `${texts.wizard_activity_default} ${data.activity_index + 1}`,
            bodyEl: meta.bodyEl
        });
    };

    const initDetailedPlanView = (data) => {
        const sourceSections = normalizeInitialSections(data?.sections || []);

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
            prvLiveNote.textContent = texts.wizard_live_note_detailed;
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
        if (prvHeaderTitle) {
            prvHeaderTitle.textContent = texts.wizard_plan_detailed_title;
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_detailed_subtitle, {
                current: 0,
                total: state.detailedTotal,
            });
        }
        if (planningSpinner) {
            planningSpinner.classList.remove('done');
        }

        sourceSections.forEach((section, renderIdx) => {
            const sectionIndex = section.section_index ?? renderIdx;
            const sectionRow = createDetailedSectionRow({
                sectionIndex,
                renderIndex: renderIdx,
                sectionName: section.name,
                totalActivities: (section.activities || []).length
            });
            if (!sectionRow) {
                return;
            }

            (section.activities || []).forEach((activity, activityIdx) => {
                createDetailedActivityRow({
                    sectionIndex,
                    activityIndex: activityIdx,
                    activityType: activity.activity_type || activity.type || 'quiz',
                    activityTitle: activity.title
                        || activity.name
                        || `${texts.wizard_activity_default} ${activityIdx + 1}`,
                    bodyEl: sectionRow.bodyEl
                });
            });
        });
    };

    const handleDetailedPlanField = (data) => {
        if (state.planningMode !== 'detailed') {
            initDetailedPlanView({sections: state.latestInitialSections});
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
            updateSectionImageBadge(data.section_index);
        } else if (data.field === 'details' && typeof data.value === 'string' && !entry.previewDescription) {
            entry.previewDescription = data.value.trim();
        }

        const summary = [];
        if (entry.chapterCount > 0) {
            summary.push(`${entry.chapterCount} ${texts.wizard_chapters_label}`);
        }
        if (entry.questionCount > 0) {
            summary.push(`${entry.questionCount} ${texts.wizard_questions_label}`);
        }
        if (entry.imageCount > 0) {
            summary.push(formatImageCount(entry.imageCount));
        }
        let text = entry.previewDescription || texts.wizard_generating_details;
        if (summary.length > 0) {
            text = `${text} (${summary.join(' · ')})`;
        }
        if (entry.progressEl) {
            entry.progressEl.textContent = text;
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_generating_details_for, {
                name: data.title || texts.wizard_activity_default,
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
        entry.imageSuggestions = imageSuggestions.map((item, index) => {
            const suggestionId = `${data.section_index}-${data.activity_index}-${index}`;
            if (typeof state.selectedDetailedImages[suggestionId] === 'undefined') {
                state.selectedDetailedImages[suggestionId] = true;
            }

            return {
                id: suggestionId,
                placement: item.placement || '',
                description: item.description || '',
            };
        });
        parsed.image_suggestions = entry.imageSuggestions;
        recalculateEntryImageCount(entry, data.section_index);

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
            sectionIndex: data.section_index,
        });
        if (detailContent.childNodes.length > 0) {
            entry.detailEl.innerHTML = '';
            entry.detailEl.appendChild(detailContent);
            entry.hasDetail = true;
            entry.item.classList.add('prv-activity-item--has-detail');
            entry.chevronEl.style.visibility = 'visible';
        }

        const meta = state.detailedSectionMeta[data.section_index];
        if (meta) {
            meta.done += 1;
            meta.metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
                done: meta.done,
                total: meta.total,
                description: '',
            });
            setImageBadge(meta.imagesBadgeEl, meta.imagesCount || 0);
        }
        updateDetailedHeaderStats();
    };

    const handleDetailedPlanActivity = (data) => {
        if (state.planningMode !== 'detailed') {
            initDetailedPlanView({sections: state.latestInitialSections});
        }
        markActivityPlanned(data);
    };

    return {
        normalizeInitialSections,
        initDetailedPlanView,
        handleDetailedPlanField,
        handleDetailedPlanActivity,
        updateDetailedHeaderStats,
    };
};
