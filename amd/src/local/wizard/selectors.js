// This file is part of Moodle - http://moodle.org/

/**
 * Wizard DOM selectors helper.
 *
 * @module     local_coursegen/local/wizard/selectors
 */

/**
 * Return all wizard DOM elements.
 *
 * @returns {Object}
 */
export const getWizardElements = () => {
    const btnWithImages = document.getElementById('btnWithImages');
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
        imgToggleWrap: btnWithImages ? btnWithImages.closest('label') : null,
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
        adjustPanel: document.getElementById('adjustPanel'),
        adjustInput: document.getElementById('adjustInput'),
        btnApprove: document.getElementById('btnApprove'),
        btnAdjust: document.getElementById('btnAdjust'),
        btnAdjustCancel: document.getElementById('btnAdjustCancel'),
        btnAdjustSend: document.getElementById('btnAdjustSend'),
        btnBackFlow: document.getElementById('btnBackFlow'),
        btnCancelFlow: document.getElementById('btnCancelFlow'),
        planningNavRow: document.getElementById('planningNavRow'),
        completionView: document.getElementById('completionView'),
        completionSummary: document.getElementById('completionSummary'),
        btnOpenMoodleCourse: document.getElementById('btnOpenMoodleCourse'),
        btnCreateAnotherCourse: document.getElementById('btnCreateAnotherCourse'),
    };
};
