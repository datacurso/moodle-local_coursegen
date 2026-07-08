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
 * Activityai i18n loader.
 *
 * @module     local_coursegen/local/activityai/i18n
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const STRING_KEYS = [
    'activityai_status_plan_accepted_generating',
    'activityai_status_generating_content',
    'activityai_status_generating_images_simple',
    'activityai_status_generating_images_progress',
    'activityai_status_images_generated',
    'activityai_status_waiting_review',
    'activityai_status_completed',
    'activityai_status_retrying',
    'activityai_error_unknown',
    'activityai_error_high_demand',
    'activityai_error_disconnected',
    'activityai_error_create_activity',
    'activityai_prompt_prefix',
    'activityai_retry_slow_warning',
    'activityai_retry_action',
    'accept_planning_create_activity',
    'adjust_course_planning',
];

let cachedTexts = null;
let loadingPromise = null;

/**
 * Load all activityai strings from lang pack.
 *
 * @returns {Promise<Object>}
 */
export const loadActivityaiStrings = async() => {
    if (cachedTexts) {
        return cachedTexts;
    }

    if (!loadingPromise) {
        loadingPromise = getStrings(STRING_KEYS.map((key) => ({
            key,
            component: 'local_coursegen',
        }))).then((values) => {
            cachedTexts = STRING_KEYS.reduce((acc, key, index) => {
                acc[key] = values[index];
                return acc;
            }, {});
            return cachedTexts;
        });
    }

    return loadingPromise;
};
