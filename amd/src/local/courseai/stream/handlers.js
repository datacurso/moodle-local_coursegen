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
 * SSE event dispatch table for the stream manager.
 *
 * Imports all individual handlers and builds the HANDLERS map.
 * Entry point called by connection.js for every incoming SSE message.
 *
 * Handler modules:
 *   handlers-content.js  — section, activity, detail, token, course_identity
 *   handlers-progress.js — activity_progress_*, image_progress_*
 *   handlers-lifecycle.js — status, error, review_needed, completed, failed
 *
 * @module     local_coursegen/local/courseai/stream/handlers
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {
    handleActivity,
    handleSection,
    handleSubsection,
    handlePlanNotice,
    handleCourseConfiguration,
    handleDetailedPlanField,
    handleDetailedPlanActivity,
    handleToken,
} from './handlers-content';

import {
    handleActivityProgressInit,
    handleActivityProgressStart,
    handleActivityProgressDone,
    handleActivityProgressFailed,
    handleImageProgressInit,
    handleImageProgressTick,
    handleImageProgressDone,
} from './handlers-progress';

import {
    handleStatus,
    handleError,
    handleReviewNeeded,
    handleCompleted,
    handleFailed,
} from './handlers-lifecycle';

const HANDLERS = {
    activity: handleActivity,
    section: handleSection,
    subsection: handleSubsection,
    plan_notice: handlePlanNotice,
    course_configuration: handleCourseConfiguration,
    detailed_plan_field: handleDetailedPlanField,
    detailed_plan_activity: handleDetailedPlanActivity,
    token: handleToken,
    status: handleStatus,
    error: handleError,
    activity_progress_init: handleActivityProgressInit,
    activity_progress_start: handleActivityProgressStart,
    activity_progress_done: handleActivityProgressDone,
    activity_progress_failed: handleActivityProgressFailed,
    image_progress_init: handleImageProgressInit,
    image_progress_tick: handleImageProgressTick,
    image_progress_done: handleImageProgressDone,
    review_needed: handleReviewNeeded,
    completed: handleCompleted,
    failed: handleFailed,
};

/**
 * Route a parsed SSE data object to its handler.
 *
 * @param {Object} data - Parsed JSON from the SSE message event
 * @param {Object} ctx  - Per-stream context object
 * @returns {Promise<void>}
 */
export const routeEvent = async(data, ctx) => {
    const handler = HANDLERS[data.type];
    if (handler) {
        await handler(data, ctx);
    }
};
