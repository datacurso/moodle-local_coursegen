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
 * Planning UI helpers.
 *
 * @module     local_coursegen/local/courseai/ui-planning
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create planning UI helpers.
 *
 * @param {Object} deps
 * @returns {Object}
 */
/**
 * Set compact chat visibility and control state.
 *
 * @param {Object} deps - Dependencies including state, elements, texts
 * @param {string} mode - 'hidden' | 'disabled' | 'enabled' | 'reset'
 */
export const setCompactChatState = (deps, mode) => {
    const {
        state,
        elements,
        texts,
    } = deps;

    const {
        compactChatCard,
        compactPromptInput,
        compactChipsRow,
        compactToolbarLeft,
        btnCompactRegenerate,
        compactLangSelect,
        btnCompactWithImages,
        btnCompactSyllabus,
        btnCompactDirectrices,
    } = elements;

    if (!compactChatCard) {
        return;
    }

    const sparkleIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 ' +
        '9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 ' +
        '15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 ' +
        '6.135a.5.5 0 0 1-.962 0z"/></svg>';

    switch (mode) {
        case 'hidden':
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
            }
            break;

        case 'disabled':
            compactChatCard.style.display = 'block';
            compactChatCard.classList.add('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.add('compact-controls--disabled');
                compactPromptInput.disabled = true;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.add('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.add('compact-controls--disabled');
            }
            // Disable form controls and toolbar buttons — keyboard + mouse
            if (compactLangSelect) {
                compactLangSelect.disabled = true;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = true;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = true;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = true;
            }
            // Disable Regenerar — actions.js re-enables it and switches label to Pausar
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = true;
            }
            if (state) {
                state.isStreaming = true;
            }
            break;

        case 'enabled':
            compactChatCard.style.display = 'block';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = `${sparkleIcon} ${texts.courseai_btn_regenerate}`;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;

        case 'reset':
        default:
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = `${sparkleIcon} ${texts.courseai_btn_regenerate}`;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;
    }
};

export const createPlanningUi = (deps) => {
    const {
        state,
        elements,
        activityLabels,
        getActivityIconUrl,
        escapeHtml,
        setProgress,
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
            pcStep.textContent = formatTemplate(texts.courseai_plan_counter, {
                sections: state.totalSections,
                activities: state.totalActivities,
            });
        }
        if (pcSubtitle) {
            pcSubtitle.textContent = formatTemplate(texts.courseai_plan_adding, {
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
                <span class="ps-section-count">${activities.length} ${texts.courseai_activities_count}</span>
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
                                ${escapeHtml(activityLabels[activityType] || activityType || texts.courseai_activity_default)}
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
            ? formatTemplate(texts.courseai_section_progress_with_total, {
                done: 0,
                total: sectionData.activity_count,
                description: sectionData.description || '',
            })
            : formatTemplate(texts.courseai_section_progress_no_total, {
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
        titleEl.textContent = sectionData.name || texts.courseai_plan_default_unnamed;
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
            name: sectionData.name || texts.courseai_plan_default_unnamed,
            description: sectionData.description || '',
            activityCount: sectionData.activity_count,
            activities: [],
            metaEl,
            bodyEl: body,
            chevronEl
        });

        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.courseai_section_label, {
                section: state.totalSections,
                name: sectionData.name || texts.courseai_plan_default_unnamed,
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
            sectionEntry.metaEl.textContent = formatTemplate(texts.courseai_section_progress_with_total, {
                done,
                total: sectionEntry.activityCount,
                description: sectionEntry.description,
            });
        } else {
            sectionEntry.metaEl.textContent = formatTemplate(texts.courseai_section_progress_no_total, {
                description: sectionEntry.description,
            });
        }

        const activityItem = document.createElement('div');
        activityItem.className = 'prv-activity-item';
        const activityType = data.activity_type || data.type || 'quiz';
        const activityName = data.title || data.name || texts.courseai_activity_default;
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
            prvHeaderSub.textContent = formatTemplate(texts.courseai_plan_adding, {
                name: activityName,
            });
        }
    };

    const syncCompactChatState = () => {
        // Sync language
        const langSelect = document.getElementById('langSelect');
        const compactLangSelect = document.getElementById('compactLangSelect');
        if (langSelect && compactLangSelect) {
            compactLangSelect.value = langSelect.value;
        }

        // Sync images toggle
        const btnWithImages = document.getElementById('btnWithImages');
        const btnCompactWithImages = document.getElementById('btnCompactWithImages');
        const imgToggleTrack = document.getElementById('imgToggleTrack');
        const compactImgToggleTrack = document.getElementById('compactImgToggleTrack');
        if (btnWithImages && btnCompactWithImages) {
            btnCompactWithImages.checked = btnWithImages.checked;
            if (compactImgToggleTrack && imgToggleTrack) {
                if (btnWithImages.checked) {
                    compactImgToggleTrack.parentElement.classList.add('on');
                } else {
                    compactImgToggleTrack.parentElement.classList.remove('on');
                }
            }
        }

        // Sync syllabus chip
        const chipSyllabus = document.getElementById('chipSyllabus');
        const compactChipSyllabus = document.getElementById('compactChipSyllabus');
        const chipSyllabusName = document.getElementById('chipSyllabusName');
        const compactChipSyllabusName = document.getElementById('compactChipSyllabusName');
        const compactChipsRow = document.getElementById('compactChipsRow');
        if (chipSyllabus && compactChipSyllabus && chipSyllabusName && compactChipSyllabusName) {
            if (!chipSyllabus.classList.contains('hidden')) {
                compactChipSyllabus.classList.remove('hidden');
                compactChipSyllabusName.textContent = chipSyllabusName.textContent;
                if (compactChipsRow) {
                    compactChipsRow.style.display = 'flex';
                }
            } else {
                compactChipSyllabus.classList.add('hidden');
            }
        }

        // Sync guideline chip
        const chipGuideline = document.getElementById('chipGuideline');
        const compactChipGuideline = document.getElementById('compactChipGuideline');
        const chipGuidelineName = document.getElementById('chipGuidelineName');
        const compactChipGuidelineName = document.getElementById('compactChipGuidelineName');
        const guidelineBadge = document.getElementById('guidelineBadge');
        const compactGuidelineBadge = document.getElementById('compactGuidelineBadge');
        if (chipGuideline && compactChipGuideline && chipGuidelineName && compactChipGuidelineName) {
            if (!chipGuideline.classList.contains('hidden')) {
                compactChipGuideline.classList.remove('hidden');
                compactChipGuidelineName.textContent = chipGuidelineName.textContent;
                if (compactChipsRow) {
                    compactChipsRow.style.display = 'flex';
                }
                if (guidelineBadge && compactGuidelineBadge && !guidelineBadge.classList.contains('hidden')) {
                    compactGuidelineBadge.classList.remove('hidden');
                    compactGuidelineBadge.textContent = guidelineBadge.textContent;
                }
            } else {
                compactChipGuideline.classList.add('hidden');
            }
        }

        // Keep compact prompt empty (user will write adjustments)
        const compactPromptInput = document.getElementById('compactPromptInput');
        if (compactPromptInput) {
            compactPromptInput.value = '';
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

        // Enable compact chat and show courseai cancel button when review is ready
        setCompactChatState(deps, 'enabled');
        // Sync state from main chat to compact chat (language, chips, etc.)
        syncCompactChatState();

        const courseaiCancelRow = document.getElementById('courseaiCancelRow');
        if (courseaiCancelRow) {
            courseaiCancelRow.style.display = 'flex';
        }

        if (mode === 'detailed') {
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
            if (prvHeaderSub) {
                prvHeaderSub.textContent = texts.courseai_plan_detailed_done_subtitle;
            }
            if (prvLiveNote) {
                prvLiveNote.style.display = 'none';
                prvLiveNote.textContent = '';
            }
            if (planActionsHint) {
                planActionsHint.textContent = texts.courseai_plan_review_hint_detailed;
            }
            if (planReviewCard) {
                planReviewCard.style.display = '';
            }
        } else {
            if (pcStep) {
                pcStep.textContent = texts.courseai_plan_detailed_markdown_title;
            }
            if (pcTitle) {
                pcTitle.textContent = texts.courseai_plan_detailed_markdown_title;
            }
            if (pcSubtitle) {
                pcSubtitle.textContent = texts.courseai_plan_detailed_markdown_subtitle;
            }
            if (planActionsHint) {
                planActionsHint.textContent = texts.courseai_plan_review_hint_detailed;
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
        showReviewActions,
        syncCompactChatState,
    };
};
