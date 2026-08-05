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
 * Planning UI helpers — orchestrator.
 *
 * Re-exports setCompactChatState so existing importers remain unaffected,
 * and assembles the full createPlanningUi factory from focused submodules.
 *
 * @module     local_coursegen/local/courseai/ui-planning
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export { setCompactChatState } from './planning/compact-chat';
import { addPlanSection, addActivityToSection } from './planning/render';
import { showReviewActions } from './planning/review-actions';
import { setCompactChatState as setCompactChatStateImpl } from './planning/compact-chat';

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
        texts,
        formatTemplate,
    } = deps;

    const {
        prvSections,
        prvHeaderSub,
        planReviewCard,
    } = elements;

    /**
     * Sync main-chat state (language, chips) into the compact-chat panel.
     */
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

        // Sync subsections toggle
        const btnWithSubsections = document.getElementById('btnWithSubsections');
        const btnCompactWithSubsections = document.getElementById('btnCompactWithSubsections');
        const subToggleTrack = document.getElementById('subToggleTrack');
        const compactSubToggleTrack = document.getElementById('compactSubToggleTrack');
        if (btnWithSubsections && btnCompactWithSubsections) {
            btnCompactWithSubsections.checked = btnWithSubsections.checked;
            if (compactSubToggleTrack && subToggleTrack) {
                if (btnWithSubsections.checked) {
                    compactSubToggleTrack.parentElement.classList.add('on');
                } else {
                    compactSubToggleTrack.parentElement.classList.remove('on');
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

    /**
     * Append a section header row to the detailed review panel.
     *
     * @param {Object} sectionData
     */
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

    // Build shared render context for submodule helpers
    const renderCtx = {state, elements, activityLabels, getActivityIconUrl, escapeHtml, texts, formatTemplate, setProgress};

    return {
        addPlanSection: (section) => addPlanSection(section, renderCtx),
        addSectionHeader,
        addActivityToSection: (data) => addActivityToSection(data, renderCtx),
        showReviewActions: (mode) => showReviewActions(mode, {
            elements, texts, setProgress,
            setCompactChatState: setCompactChatStateImpl, deps,
            syncCompactChatState,
        }),
        syncCompactChatState,
    };
};
