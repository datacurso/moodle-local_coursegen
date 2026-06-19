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
 * Course AI DOM selectors helper.
 *
 * @module     local_coursegen/local/courseai/selectors
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return all courseai DOM elements.
 *
 * @returns {Object}
 */
export const getCourseaiElements = () => {
    const btnWithImages = document.getElementById('btnWithImages');
    const btnCompactWithImages = document.getElementById('btnCompactWithImages');

    const resolveToggleWrap = (input) => {
        if (!input) {
            return null;
        }

        return input.closest('.tbar-toggle-wrap') || input.closest('label');
    };

    return {
        promptInput: document.getElementById('promptInput'),
        btnGenerate: document.getElementById('btnGenerate'),
        btnSyllabus: document.getElementById('btnSyllabus'),
        btnDirectrices: document.getElementById('btnDirectrices'),
        guidelinesPopover: document.getElementById('guidelinesPopover'),
        guidelineSearch: document.getElementById('guidelineSearch'),
        guidelineList: document.getElementById('guidelineList'),
        langSelect: document.getElementById('langSelect'),
        btnWithImages,
        imgToggleWrap: resolveToggleWrap(btnWithImages),
        courseaiWorkspace: document.getElementById('courseaiWorkspace'),
        contextView: document.getElementById('contextView'),
        planningView: document.getElementById('planningView'),
        planningProgressCard: document.getElementById('planningProgressCard'),
        pcIconWrap: document.getElementById('pcIconWrap'),
        planningSpinner: document.getElementById('planningSpinner'),
        planningCheckIcon: document.getElementById('planningCheckIcon'),
        pcStep: document.getElementById('pcStep'),
        pcTitle: document.getElementById('pcTitle'),
        pcSubtitle: document.getElementById('pcSubtitle'),
        pcPct: document.getElementById('pcPct'),
        pcBarFill: document.getElementById('pcBarFill'),
        pcToggleRow: document.getElementById('pcToggleRow'),
        pcToggleBtn: document.getElementById('pcToggleBtn'),
        pcChevron: document.getElementById('pcChevron'),
        pcDetailsPanel: document.getElementById('pcDetailsPanel'),
        planSectionsView: document.getElementById('planSectionsView'),
        planSectionsList: document.getElementById('planSectionsList'),
        planDetailedView: document.getElementById('planDetailedView'),
        planDetailedList: document.getElementById('planDetailedList'),
        planMarkdownView: document.getElementById('planMarkdownView'),
        planMarkdown: document.getElementById('planMarkdown'),
        typingCursor: document.getElementById('typingCursor'),
        planReviewCard: document.getElementById('planReviewCard'),
        prvHeader: document.getElementById('prvHeader'),
        prvHeaderTitle: document.getElementById('prvHeaderTitle'),
        prvHeaderSub: document.getElementById('prvHeaderSub'),
        prvLiveNote: document.getElementById('prvLiveNote'),
        prvSections: document.getElementById('prvSections'),
        prvSpinnerIcon: document.getElementById('prvSpinnerIcon'),
        prvCheckIcon: document.getElementById('prvCheckIcon'),
        planActions: document.getElementById('planActions'),
        planActionsHint: document.getElementById('planActionsHint'),
        btnApprove: document.getElementById('btnApprove'),
        compactChatCard: document.getElementById('compactChatCard'),
        compactPromptInput: document.getElementById('compactPromptInput'),
        initialPromptHistory: document.getElementById('courseaiInitialPromptHistory'),
        initialPromptText: document.getElementById('courseaiInitialPromptText'),
        checklist: document.getElementById('courseaiChecklist'),
        checklistList: document.getElementById('courseaiChecklistList'),
        adjustmentHistory: document.getElementById('courseaiAdjustmentHistory'),
        compactChipsRow: document.getElementById('compactChipsRow'),
        compactToolbarLeft: document.getElementById('compactToolbarLeft'),
        btnCompactRegenerate: document.getElementById('btnCompactRegenerate'),
        btnStopExec: document.getElementById('btnStopExec'),
        btnResumeExec: document.getElementById('btnResumeExec'),
        compactLangSelect: document.getElementById('compactLangSelect'),
        btnCompactWithImages,
        compactImgToggleWrap: resolveToggleWrap(btnCompactWithImages),
        btnCompactSyllabus: document.getElementById('btnCompactSyllabus'),
        btnCompactDirectrices: document.getElementById('btnCompactDirectrices'),
        completionView: document.getElementById('completionView'),
        completionSummary: document.getElementById('completionSummary'),
        btnOpenMoodleCourse: document.getElementById('btnOpenMoodleCourse'),
        btnCreateAnotherCourse: document.getElementById('btnCreateAnotherCourse'),
    };
};
