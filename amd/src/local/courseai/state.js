// This file is part of Moodle - http://moodle.org/

/**
 * Courseai state factory.
 *
 * @module     local_coursegen/local/courseai/state
 */

/**
 * Create initial courseai state.
 *
 * @param {Object} args
 * @param {string} args.defaultLang
 * @param {Array} args.guidelines
 * @param {Array} args.languages
 * @returns {Object}
 */
export const createInitialState = ({defaultLang, guidelines, languages}) => {
    return {
        defaultLang,
        syllabusFile: null,
        syllabusFilename: null,
        draftitemid: null,
        sessionid: 0,
        threadid: '',
        streamingurl: '',
        selectedGuidelineId: null,
        guidelinePopoverOpen: false,
        guidelineSearchQuery: '',
        initialPrompt: '',
        lang: defaultLang,
        withImages: false,
        guidelines,
        languages,
        sseSource: null,
        planningMode: null,
        planBuffer: '',
        planDetailsOpen: false,
        totalSections: 0,
        totalActivities: 0,
        detailedTotal: 0,
        detailedCurrent: 0,
        planSectionsData: [],
        latestInitialSections: [],
        detailedActivityEls: {},
        detailedSectionMeta: {},
        selectedDetailedImages: {},
        structuredActivityProgress: false,
        activityProgressTotal: 0,
        activityProgressStarted: 0,
        activityProgressDone: 0,
        imageProgressDone: 0,
        imageProgressTotal: 0,
        completionStats: null,
        createdCourseUrl: '',
        createdCourseResult: null,
        courseTitle: '',
        currentStage: 'planning'
    };
};
