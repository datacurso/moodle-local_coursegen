// This file is part of Moodle - http://moodle.org/

/**
 * Planning UI helpers.
 *
 * @module     local_coursegen/local/wizard/ui-planning
 */

/**
 * Create planning UI helpers.
 *
 * @param {Object} deps
 * @returns {Object}
 */
export const createPlanningUi = (deps) => {
    const {
        state,
        elements,
        activityLabels,
        getActivityIconUrl,
        escapeHtml,
        setProgress,
        updateDetailedHeaderStats,
        texts,
        formatTemplate,
    } = deps;

    const {
        planSectionsList,
        pcStep,
        pcSubtitle,
        pcToggleRow,
        prvSections,
        prvHeaderSub,
        planReviewCard,
        prvSpinnerIcon,
        prvCheckIcon,
        prvHeader,
        prvHeaderTitle,
        planningSpinner,
        planningCheckIcon,
        pcIconWrap,
        typingCursor,
        pcTitle,
        planActionsHint,
        prvLiveNote,
        planActions,
    } = elements;

    const addPlanSection = (section) => {
        if (!section || !planSectionsList) {
            return;
        }

        const activities = Array.isArray(section.activities) ? section.activities : [];
        state.totalSections += 1;
        state.totalActivities += activities.length;

        if (pcStep) {
            pcStep.textContent = formatTemplate(texts.wizard_plan_counter, {
                sections: state.totalSections,
                activities: state.totalActivities,
            });
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = formatTemplate(texts.wizard_plan_adding, {
                name: section.name || '',
            });
        }
        const estimatedPct = Math.min(90, (state.totalActivities / (state.totalActivities + 6)) * 100);
        setProgress(estimatedPct);

        if (state.totalSections === 1 && pcToggleRow) {
            pcToggleRow.style.display = 'flex';
        }

        const sectionEl = document.createElement('div');
        sectionEl.className = 'ps-section';
        sectionEl.innerHTML = `
            <div class="ps-section-head">
                <span class="ps-section-num">${state.totalSections}</span>
                <div class="ps-section-info">
                    <h3 class="ps-section-name">${escapeHtml(section.name || '')}</h3>
                    <p class="ps-section-desc">${escapeHtml(section.description || '')}</p>
                </div>
                <span class="ps-section-count">${activities.length} ${texts.wizard_activities_count}</span>
            </div>
            <ul class="ps-activities">
                ${activities.map((activity) => {
                    const activityType = activity.type || 'resource';
                    const iconUrl = getActivityIconUrl(activityType);
                    return `
                    <li class="ps-activity">
                        <span class="ps-badge ps-badge--${escapeHtml(activityType)}">
                            <img src="${iconUrl}" 
                                 class="ps-badge-icon" 
                                 alt="" 
                                 onerror="this.style.display='none'">
                            <span class="ps-badge-text">
                                ${escapeHtml(activityLabels[activityType] || activityType || texts.wizard_activity_default)}
                            </span>
                        </span>
                        <div class="ps-activity-info">
                            <span class="ps-activity-name">${escapeHtml(activity.name || '')}</span>
                            <span class="ps-activity-desc">${escapeHtml(activity.description || '')}</span>
                        </div>
                    </li>
                `;
                }).join('')}
            </ul>
        `;
        planSectionsList.appendChild(sectionEl);
    };

    const addSectionHeader = (sectionData) => {
        if (!prvSections) {
            return;
        }

        state.totalSections += 1;
        const metaEl = document.createElement('p');
        metaEl.className = 'prv-section-meta';
        metaEl.textContent = sectionData.activity_count !== null && sectionData.activity_count !== undefined
            ? formatTemplate(texts.wizard_section_progress_with_total, {
                done: 0,
                total: sectionData.activity_count,
                description: sectionData.description || '',
            })
            : formatTemplate(texts.wizard_section_progress_no_total, {
                description: sectionData.description || '',
            });

        const body = document.createElement('div');
        body.className = 'prv-section-body';
        body.style.display = 'flex';

        const chevronEl = document.createElement('span');
        chevronEl.className = 'prv-chevron prv-chevron--open';
        chevronEl.innerHTML = [
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"',
            'stroke="currentColor" stroke-width="2.5" stroke-linecap="round"',
            'stroke-linejoin="round" aria-hidden="true">',
            '<polyline points="9 18 15 12 9 6"/></svg>'
        ].join(' ');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'prv-section-btn';
        btn.innerHTML = `<span class="prv-section-badge">${state.totalSections}</span>`;

        const infoDiv = document.createElement('div');
        infoDiv.className = 'prv-section-info';
        const titleEl = document.createElement('p');
        titleEl.className = 'prv-section-title';
        titleEl.textContent = sectionData.name || texts.wizard_plan_default_unnamed;
        infoDiv.appendChild(titleEl);
        infoDiv.appendChild(metaEl);
        btn.appendChild(infoDiv);
        btn.appendChild(chevronEl);
        btn.addEventListener('click', () => {
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'flex';
            chevronEl.classList.toggle('prv-chevron--open', !isOpen);
        });

        const row = document.createElement('div');
        row.className = 'prv-section-row';
        row.appendChild(btn);
        row.appendChild(body);
        prvSections.appendChild(row);

        state.planSectionsData.push({
            sectionIndex: sectionData.section_index,
            name: sectionData.name || texts.wizard_plan_default_unnamed,
            description: sectionData.description || '',
            activityCount: sectionData.activity_count,
            activities: [],
            metaEl,
            bodyEl: body,
            chevronEl
        });

        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_section_label, {
                section: state.totalSections,
                name: sectionData.name || texts.wizard_plan_default_unnamed,
            });
        }
        if (planReviewCard) {
            planReviewCard.style.display = '';
        }
    };

    const addActivityToSection = (data) => {
        state.totalActivities += 1;
        const sectionEntry = state.planSectionsData.find((section) => section.sectionIndex === data.section_index);
        if (!sectionEntry) {
            return;
        }

        sectionEntry.activities.push({
            type: data.activity_type || data.type,
            name: data.title || data.name,
            description: data.description || ''
        });

        const done = sectionEntry.activities.length;
        if (sectionEntry.activityCount !== null && sectionEntry.activityCount !== undefined) {
            sectionEntry.metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
                done,
                total: sectionEntry.activityCount,
                description: sectionEntry.description,
            });
        } else {
            sectionEntry.metaEl.textContent = formatTemplate(texts.wizard_section_progress_no_total, {
                description: sectionEntry.description,
            });
        }

        const activityItem = document.createElement('div');
        activityItem.className = 'prv-activity-item';
        const activityType = data.activity_type || data.type || 'quiz';
        const activityName = data.title || data.name || texts.wizard_activity_default;
        const iconUrl = getActivityIconUrl(activityType);
        activityItem.innerHTML = `
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
                <p class="prv-activity-name">${escapeHtml(activityName)}</p>
                <p class="prv-activity-desc">${escapeHtml(data.description || '')}</p>
            </div>
        `;
        sectionEntry.bodyEl.appendChild(activityItem);

        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_adding, {
                name: activityName,
            });
        }
    };

    const buildReviewCard = (sections, normalizeInitialSections) => {
        state.latestInitialSections = normalizeInitialSections(sections || []);
        if (state.latestInitialSections.length === 0) {
            return;
        }
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
        if (prvHeaderTitle) {
            prvHeaderTitle.textContent = texts.wizard_plan_review_title;
        }

        const sectionCount = state.latestInitialSections.length;
        const activityCount = state.latestInitialSections.reduce((acc, section) => {
            return acc + (section.activities || []).length;
        }, 0);
        state.totalSections = sectionCount;
        state.totalActivities = activityCount;

        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_sections_counter, {
                sections: sectionCount,
                activities: activityCount,
            });
        }
        if (planReviewCard) {
            planReviewCard.style.display = '';
        }
    };

    const showReviewActions = (mode) => {
        if (planningSpinner) {
            planningSpinner.classList.add('done');
        }
        if (typingCursor) {
            typingCursor.classList.add('hidden');
        }
        setProgress(100);

        if (mode === 'initial') {
            if (planningSpinner) {
                planningSpinner.style.display = 'none';
            }
            if (planningCheckIcon) {
                planningCheckIcon.style.display = '';
            }
            if (pcIconWrap) {
                pcIconWrap.style.background = '#16a34a';
                pcIconWrap.style.color = '#fff';
            }
            if (pcStep) {
                pcStep.textContent = formatTemplate(texts.wizard_plan_counter, {
                    sections: state.totalSections,
                    activities: state.totalActivities,
                });
            }
            if (pcTitle) {
                pcTitle.textContent = texts.wizard_plan_review_title;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.wizard_plan_review_subtitle;
            }
            if (planActionsHint) {
                planActionsHint.textContent = texts.wizard_plan_review_hint_initial;
            }
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
        } else if (mode === 'detailed') {
            if (planningSpinner) {
                planningSpinner.style.display = 'none';
            }
            if (planningCheckIcon) {
                planningCheckIcon.style.display = '';
            }
            if (pcIconWrap) {
                pcIconWrap.style.background = '#16a34a';
                pcIconWrap.style.color = '#fff';
            }
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
            if (prvHeaderTitle) {
                prvHeaderTitle.textContent = texts.wizard_plan_detailed_done_title;
            }
            if (updateDetailedHeaderStats) {
                updateDetailedHeaderStats();
            }
            if (prvLiveNote) {
                prvLiveNote.style.display = 'none';
                prvLiveNote.textContent = '';
            }
            if (planActionsHint) {
                planActionsHint.textContent = texts.wizard_plan_review_hint_detailed;
            }
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
        } else {
            if (pcStep) {
                pcStep.textContent = texts.wizard_plan_detailed_markdown_title;
            }
            if (pcTitle) {
                pcTitle.textContent = texts.wizard_plan_detailed_markdown_title;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.wizard_plan_detailed_markdown_subtitle;
            }
            if (planActionsHint) {
                planActionsHint.textContent = texts.wizard_plan_review_hint_detailed;
            }
            if (pcToggleRow) {
                pcToggleRow.style.display = 'flex';
            }
        }

        if (planActions) {
            planActions.style.display = 'flex';
        }
    };

    return {
        addPlanSection,
        addSectionHeader,
        addActivityToSection,
        buildReviewCard,
        showReviewActions,
    };
};
