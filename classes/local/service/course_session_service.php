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

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * Service class for handling course sessions using the persistent model.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_session_service {
    /**
     * Create a new course session from the given course form data.
     *
     * @param \stdClass $data Validated course form data.
     * @param int $userid User ID owning the session.
     * @param string $sessionid External session identifier.
     * @return course_session
     */
    public static function create_from_form_data(\stdClass $data, int $userid, string $sessionid): course_session {
        $courseid = !empty($data->id) ? (int)$data->id : null;

        $record = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'session_id' => $sessionid,
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ];

        $session = new course_session(0, $record);
        $session->create();

        return $session;
    }

    /**
     * Update the status of a course session.
     *
     * @param int $id Session record ID.
     * @param int $status New status (use course_session::STATUS_* constants).
     * @return void
     */
    public static function update_status(int $id, int $status): void {
        $session = new course_session($id);
        $session->set('status', $status);
        $session->set('timemodified', time());
        $session->update();
    }

    /**
     * Get a course planning session for the given user.
     *
     * @param int $id Session record ID.
     * @param int $userid User ID.
     * @return course_session
     */
    public static function get_user_session(int $id, int $userid): course_session {
        $session = course_session::get_record([
            'id' => $id,
            'userid' => $userid,
        ]);

        if (!$session) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        return $session;
    }
}
