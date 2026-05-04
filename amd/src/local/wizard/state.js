// This file is part of Moodle - http://moodle.org/

/**
 * Wizard state factory.
 *
 * @module     local_coursegen/local/wizard/state
 */

/**
 * Create initial wizard state.
 *
 * @param {Object} args
 * @param {string} args.defaultLang
 * @param {Array} args.guidelines
 * @param {Array} args.languages
 * @returns {Object}
 */
export const createInitialState = ({defaultLang, guidelines, languages}) => {
    return {
        syllabusFile: null,
        syllabusFilename: null,
        draftitemid: null,
        sessionid: 0,
        threadid: '',
        streamingurl: '',
        selectedGuidelineId: null,
        guidelinePopoverOpen: false,
        guidelineSearchQuery: '',
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
        latestInitialSections: [],
        detailedTotal: 0,
        detailedCurrent: 0,
        planSectionsData: [],
        detailedActivityEls: {},
        detailedSectionMeta: {},
        selectedDetailedImages: {},
        currentStage: 'planning'
    };
};
