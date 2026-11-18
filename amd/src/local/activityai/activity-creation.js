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
 * Creates the Moodle activity from the generated job of an Activity AI session.
 *
 * @module     local_coursegen/local/activityai/activity-creation
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import notification from 'core/notification';
import * as repository from 'local_coursegen/local/activityai/repository';

/**
 * Create Moodle activity from job id.
 *
 * @param {StateManager} stateManager
 * @param {Object} uiTexts Texts of the interface.
 * @returns {Promise<void>}
 */
export const createActivityFromJob = async(stateManager, uiTexts) => {
    const state = stateManager.state;
    const courseid = Number(state.page.courseid) || 0;
    const jobid = String(state.session.jobid || '');

    if (!courseid || !jobid) {
        return;
    }

    const sectionnum = state.session.sectionnum;
    const beforemod = state.session.beforemod;

    try {
        const result = await repository.createActivity({
            courseid,
            sectionnum,
            jobid,
            beforemod,
        });

        if (!result || !result.ok) {
            notification.alert('', result?.message || uiTexts.activityai_error_create_activity, 'close');
            return;
        }

        const activityUrl = result?.data?.activityurl || null;
        if (activityUrl) {
            window.location.href = activityUrl;
        } else {
            window.location.reload();
        }
    } catch (error) {
        notification.exception(error);
    }
};
