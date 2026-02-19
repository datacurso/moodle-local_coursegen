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

namespace local_coursegen;

use aiprovider_datacurso\httpclient\ai_course_api;


/**
 * AI Course class for managing AI-generated course planning sessions.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_course {
    /** @var string Config key for the persistent site UUID used by the Datacurso course service. */
    private const CONFIG_SITE_UUID = 'site_uuid';

    /**
     * Start AI course planning session by calling the /planning/start endpoint.
     *
     * @param array $sessioncreate Request payload to send as SessionCreate.
     * @return string Session ID
     */
    public static function start_course_session(array $sessioncreate): string {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $client = new ai_course_api(null, $baseurl, $baseurleu);
        $result = $client->request('POST', '/course/planning/start', $sessioncreate);

        if (!isset($result['session_id'])) {
            throw new \moodle_exception('error_starting_course_planning', 'local_coursegen');
        }

        $sessionid = $result['session_id'];

        if (isset($sessioncreate['course_id'])) {
            // Store session_id in database.
            $success = self::save_course_session((int) $sessioncreate['course_id'], $sessionid);

            if (!$success) {
                throw new \moodle_exception('error_saving_session', 'local_coursegen');
            }
        }

        return $sessionid;
    }

    /**
     * Returns a persistent site UUID for the Datacurso course service.
     *
     * @return string
     */
    public static function get_site_uuid(): string {
        $siteuuid = get_config('local_coursegen', self::CONFIG_SITE_UUID);
        if (!empty($siteuuid)) {
            return (string) $siteuuid;
        }

        $siteuuid = \core\uuid::generate();
        set_config(self::CONFIG_SITE_UUID, $siteuuid, 'local_coursegen');
        return $siteuuid;
    }

    /**
     * Save course planning session to database.
     *
     * @param int $courseid Course ID
     * @param string $sessionid Session ID from AI service
     * @return bool Success status
     */
    public static function save_course_session($courseid, $sessionid) {
        global $DB, $USER;

        try {
            // Check if a session already exists for this course.
            $existingsession = $DB->get_record('local_coursegen_course_sessions', ['courseid' => $courseid]);

            $sessiondata = new \stdClass();
            $sessiondata->courseid = $courseid;
            $sessiondata->session_id = $sessionid;
            $sessiondata->userid = $USER->id;
            // Status: 1 planning, 2 creating, 3 created, 4 failed.
            $sessiondata->status = 1;
            $sessiondata->timemodified = time();

            if ($existingsession) {
                // Update existing session.
                $sessiondata->id = $existingsession->id;
                return $DB->update_record('local_coursegen_course_sessions', $sessiondata);
            } else {
                // Create new session record.
                $sessiondata->timecreated = time();
                return $DB->insert_record('local_coursegen_course_sessions', $sessiondata);
            }
        } catch (\Exception $e) {
            debugging("Error saving course session: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get course planning session by course ID.
     *
     * @param int $courseid Course ID
     * @return object|false Session record or false if not found
     */
    public static function get_course_session($courseid) {
        global $DB;

        try {
            return $DB->get_record('local_coursegen_course_sessions', ['courseid' => $courseid]);
        } catch (\Exception $e) {
            debugging("Error getting course session: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update the status of a course session.
     *
     * @param int $sessionid Session ID
     * @param int $status Status code (1=planning, 2=creating, 3=created, 4=failed)
     */
    public static function update_session_status($sessionid, $status) {
        global $DB;

        $updatedata = new \stdClass();
        $updatedata->id = $sessionid;
        $updatedata->status = $status;
        $updatedata->timemodified = time();

        $DB->update_record('local_coursegen_course_sessions', $updatedata);
    }
}
