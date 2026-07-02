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
 * Course AI state factory.
 *
 * @module     local_coursegen/local/courseai/state
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        detailedActivityEls: {}, // keyed by activity_id (UUID string)
        detailedSectionMeta: {}, // keyed by section_id (UUID string)
        selectedDetailedImages: {}, // keyed by image suggestion id (UUID string)
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
        isStreaming: false,
        currentStage: 'planning'
    };
};
