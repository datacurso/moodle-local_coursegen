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
 * Course AI utilities.
 *
 * @module     local_coursegen/local/courseai/utils
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Parse courseai data payload from params or DOM.
 *
 * @param {Object} params
 * @returns {{guidelines: Array, languages: Array, defaultLang: string}}
 */
export const parseCourseaiData = (params) => {
    let guidelines = params?.guidelines || [];
    let languages = params?.languages || [];
    const defaultLang = params?.defaultlang || 'es';

    if (guidelines.length === 0 || languages.length === 0) {
        const dataEl = document.getElementById('courseai-data');
        if (dataEl) {
            try {
                const guidelinesData = dataEl.getAttribute('data-guidelines');
                const languagesData = dataEl.getAttribute('data-languages');

                if (guidelinesData) {
                    guidelines = JSON.parse(guidelinesData);
                }
                if (languagesData) {
                    languages = JSON.parse(languagesData);
                }
            } catch (error) {
                // Ignore malformed inline bootstrap data.
            }
        }
    }

    return {guidelines, languages, defaultLang};
};

/**
 * Escape HTML entities.
 *
 * @param {string} str
 * @returns {string}
 */
export const escapeHtml = (str) => {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

/**
 * Get activity labels map.
 *
 * @param {Object} texts
 * @returns {Object}
 */
export const getActivityLabels = (texts = {}) => ({
    quiz: texts.courseai_activity_quiz || 'Quiz',
    book: texts.courseai_activity_book || 'Book',
    assign: texts.courseai_activity_assign || 'Assignment',
    forum: texts.courseai_activity_forum || 'Forum',
    lesson: texts.courseai_activity_lesson || 'Lesson',
    url: texts.courseai_activity_url || 'URL',
    resource: texts.courseai_activity_resource || 'Resource',
    page: texts.courseai_activity_page || 'Page',
    data: texts.courseai_activity_data || 'Database',
    glossary: texts.courseai_activity_glossary || 'Glossary',
});

/**
 * Get Moodle activity icon URL.
 *
 * @param {string} modname - Module name (e.g., 'quiz', 'forum', 'assign')
 * @returns {string} URL to the module's monologo icon
 */
export const getActivityIconUrl = (modname) => {
    if (!modname || typeof modname !== 'string') {
        return '';
    }
    // Use Moodle's global config to construct the URL
    // eslint-disable-next-line no-undef
    return M.cfg.wwwroot + '/mod/' + modname + '/pix/monologo.svg';
};

/**
 * Replace {tokens} in a template string.
 *
 * @param {string} template
 * @param {Object} params
 * @returns {string}
 */
export const formatTemplate = (template, params = {}) => {
    return String(template || '').replace(/\{([a-zA-Z0-9_]+)\}/g, (match, key) => {
        if (Object.prototype.hasOwnProperty.call(params, key)) {
            return String(params[key]);
        }
        return match;
    });
};

/**
 * Generate default button markup.
 *
 * @param {Object} texts
 * @returns {string}
 */
export const getGenerateButtonHtml = (texts = {}) => `
    <svg
        width="13"
        height="13"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path
            d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 
            0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 
            1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 
            .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"
        />
    </svg>
    ${texts.courseai_btn_generate || 'Generate'}
    <svg
        width="12"
        height="12"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path d="M12 19V5M5 12l7-7 7 7"/>
    </svg>
`;
