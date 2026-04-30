// This file is part of Moodle - http://moodle.org/

/**
 * Detailed planning UI helpers.
 *
 * @module     local_coursegen/local/wizard/ui-detailed
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
        escapeHtml,
        switchPlanMode,
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

        const metaEl = document.createElement('p');
        metaEl.className = 'prv-section-meta';
        metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
            done: 0,
            total: totalActivities,
            description: '',
        });

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
        infoDiv.appendChild(metaEl);
        btn.appendChild(infoDiv);
        btn.appendChild(chevronEl);

        btn.addEventListener('click', () => {
            const isOpen = bodyEl.style.display !== 'none';
            bodyEl.style.display = isOpen ? 'none' : 'flex';
            chevronEl.classList.toggle('prv-chevron--open', !isOpen);
        });

        const row = document.createElement('div');
        row.className = 'prv-section-row';
        row.appendChild(btn);
        row.appendChild(bodyEl);
        prvSections.appendChild(row);

        state.detailedSectionMeta[sectionIndex] = {
            done: 0,
            total: totalActivities,
            metaEl,
            bodyEl,
            row
        };

        return {bodyEl};
    };

    const createDetailedActivityRow = ({sectionIndex, activityIndex, activityType, activityTitle, bodyEl}) => {
        const item = document.createElement('div');
        item.className = 'prv-activity-item prv-activity-item--pending';
        item.innerHTML = `
            <span class="ps-badge ps-badge--${escapeHtml(activityType)}">
                ${escapeHtml(activityLabels[activityType] || activityType)}
            </span>
            <div class="prv-activity-text">
                <p class="prv-activity-name">${escapeHtml(activityTitle)}</p>
            </div>
        `;

        const wrap = document.createElement('div');
        wrap.className = 'dp-activity-wrap';
        wrap.appendChild(item);
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
            previewDescription: '',
            chapterCount: 0,
            questionCount: 0,
            done: false
        };

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
        let sourceSections = normalizeInitialSections(data?.sections || []);
        if (sourceSections.length === 0) {
            sourceSections = normalizeInitialSections(state.latestInitialSections || []);
        }

        if (prvSections) {
            prvSections.innerHTML = '';
        }
        state.detailedActivityEls = {};
        state.detailedSectionMeta = {};
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
                    activityTitle: activity.title || activity.name || `${texts.wizard_activity_default} ${activityIdx + 1}`,
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

        if (entry.progressEl) {
            entry.progressEl.remove();
            entry.progressEl = null;
        }

        const parsed = data.data || {};
        const descriptionText = parsed.activity_description || entry.previewDescription || '';
        if (descriptionText) {
            const desc = document.createElement('p');
            desc.className = 'prv-activity-desc';
            desc.textContent = descriptionText;
            entry.textDiv.appendChild(desc);
        }

        const meta = state.detailedSectionMeta[data.section_index];
        if (meta) {
            meta.done += 1;
            meta.metaEl.textContent = formatTemplate(texts.wizard_section_progress_with_total, {
                done: meta.done,
                total: meta.total,
                description: '',
            });
        }
        if (prvHeaderSub) {
            prvHeaderSub.textContent = formatTemplate(texts.wizard_plan_detailed_subtitle, {
                current: state.detailedCurrent,
                total: state.detailedTotal,
            });
        }
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
    };
};
