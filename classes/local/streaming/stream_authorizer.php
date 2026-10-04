<?php
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

namespace local_coursegen\local\streaming;

use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\module_job_service;

/**
 * Decides whether the current user may read a generation stream.
 *
 * The service streams are keyed only by their thread identifier, so this check is what ties a stream to
 * the user who started it and to the capabilities that gate the paid generation.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_authorizer {
    /**
     * Check that the current user owns the stream and may run the generation behind it.
     *
     * @param string $kind Stream kind, one of the stream_kind constants.
     * @param string $id External thread identifier.
     * @throws \moodle_exception When the kind is unknown or the user has no such thread.
     * @throws \required_capability_exception When the user lacks a capability.
     */
    public function authorize(string $kind, string $id): void {
        if ($kind === stream_kind::COURSE) {
            $this->authorize_course($id);
            return;
        }

        if ($kind === stream_kind::ACTIVITY) {
            $this->authorize_activity($id);
            return;
        }

        throw new \moodle_exception('error_stream_unknown_kind', 'local_coursegen');
    }

    /**
     * Course planning streams belong to the planning session of the user.
     *
     * @param string $id External planning session identifier.
     */
    private function authorize_course(string $id): void {
        global $USER;

        course_session_service::get_user_session_by_external_id($id, (int) $USER->id);

        $context = \context_system::instance();
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);
    }

    /**
     * Activity streams belong to a generation job of the user, in a course the user can still edit.
     *
     * @param string $id External job identifier.
     */
    private function authorize_activity(string $id): void {
        global $USER;

        $job = module_job_service::get_user_job_by_external_id($id, (int) $USER->id);
        $jobcourseid = $job->get('courseid');
        $courseid = (int) $jobcourseid;
        $course = get_course($courseid);
        require_login($course, false, null, false, true);

        $context = \context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('local/coursegen:createactivitywithai', $context);
    }
}
