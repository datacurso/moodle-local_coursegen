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
 * Resume bootstrap helpers for the Course AI entrypoint.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-helpers
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create resume-related helper functions.
 *
 * @param {Object} params
 * @param {Object} params.state
 * @param {Object} params.elements
 * @param {Object} params.texts
 * @param {Object} params.params - Raw page init params
 * @returns {{
 *   getResumeSessionId: Function,
 *   setResumeBootLoading: Function,
 *   setPlanningStreamVisible: Function,
 *   parseJsonField: Function,
 *   applyCourseTitleToHeader: Function,
 *   buildCourseUrlFromResume: Function,
 *   normalizeSnapshotStatus: Function
 * }}
 */
export const makeResumeHelpers = ({state, elements, texts, params}) => {
    // Suppress unused-variable lint: texts is provided for future use.
    void texts;

    /**
     * Resolve the session id to resume from URL params or query string.
     *
     * @returns {number}
     */
    const getResumeSessionId = () => {
        const fromParams = Number(params?.resumesessionid || 0);
        if (fromParams > 0) {
            return fromParams;
        }

        const fromUrl = Number(new URLSearchParams(window.location.search).get('sessionid') || 0);
        return fromUrl > 0 ? fromUrl : 0;
    };

    const resumeLoadingView = document.getElementById('resumeLoadingView');

    /**
     * Show or hide the resume boot loading overlay.
     *
     * @param {boolean} loading
     * @returns {void}
     */
    const setResumeBootLoading = (loading) => {
        if (!resumeLoadingView) {
            return;
        }
        resumeLoadingView.style.display = loading ? '' : 'none';
    };

    /**
     * Make the planning stream content visible and hide the loading overlay.
     *
     * @returns {void}
     */
    const setPlanningStreamVisible = () => {
        const loadingEl = document.getElementById('planningLoading');
        const streamContentEl = document.getElementById('planningStreamContent');
        if (loadingEl) {
            loadingEl.style.display = 'none';
        }
        if (streamContentEl) {
            streamContentEl.style.display = '';
        }
    };

    /**
     * Parse a JSON string field with a fallback value.
     *
     * @param {string|null|undefined} value
     * @param {*} fallback
     * @returns {*}
     */
    const parseJsonField = (value, fallback) => {
        if (!value) {
            return fallback;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    };

    /**
     * Apply the stored course title to the header element.
     *
     * @returns {void}
     */
    const applyCourseTitleToHeader = () => {
        const title = String(state.courseTitle || '').trim();
        if (!title || !elements.prvHeaderTitle) {
            return;
        }
        elements.prvHeaderTitle.textContent = title;
    };

    /**
     * Build an absolute course URL from a resume snapshot.
     *
     * @param {Object} resume
     * @returns {string}
     */
    const buildCourseUrlFromResume = (resume) => {
        const explicitUrl = String(resume?.courseurl || '').trim();
        if (explicitUrl) {
            return explicitUrl;
        }

        const courseId = Number(resume?.courseid || 0);
        if (courseId <= 0) {
            return '';
        }

        const baseUrl = String(window?.M?.cfg?.wwwroot || '').replace(/\/$/, '');
        if (!baseUrl) {
            return `/course/view.php?id=${courseId}`;
        }

        return `${baseUrl}/course/view.php?id=${courseId}`;
    };

    /**
     * Normalize a dotted snapshot status string to its last segment in uppercase.
     *
     * @param {string|null|undefined} rawStatus
     * @returns {string}
     */
    const normalizeSnapshotStatus = (rawStatus) => {
        const upper = String(rawStatus || '').toUpperCase();
        if (!upper) {
            return '';
        }

        const parts = upper.split('.');
        return parts[parts.length - 1] || upper;
    };

    return {
        getResumeSessionId,
        setResumeBootLoading,
        setPlanningStreamVisible,
        parseJsonField,
        applyCourseTitleToHeader,
        buildCourseUrlFromResume,
        normalizeSnapshotStatus,
    };
};
