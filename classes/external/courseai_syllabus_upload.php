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

namespace local_coursegen\external;

use context_system;
use context_user;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\models\course_session;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External function to upload syllabus for courseai session.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courseai_syllabus_upload extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Course session ID'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft file area ID'),
        ]);
    }

    /**
     * Upload syllabus file to Datacurso API.
     *
     * @param int $sessionid Course session ID
     * @param int $draftitemid Draft file area ID
     * @return array Result with success status and filename
     */
    public static function execute(int $sessionid, int $draftitemid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'draftitemid' => $draftitemid,
        ]);

        // Check permissions.
        $context = context_system::instance();
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        try {
            // Get session record.
            $session = new course_session($params['sessionid']);

            // Verify session belongs to current user.
            if ($session->get('userid') != $USER->id) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_not_your_session', 'local_coursegen'),
                ];
            }

            $threadid = $session->get('session_id');
            if (empty($threadid)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_invalid_session', 'local_coursegen'),
                ];
            }

            // Save file from draft area to permanent storage.
            $fs = get_file_storage();
            $syscontext = context_system::instance();
            $usercontext = context_user::instance($USER->id);

            // Prepare draft area.
            $draftfiles = $fs->get_area_files(
                $usercontext->id,
                'user',
                'draft',
                $params['draftitemid'],
                'id',
                false
            );

            if (empty($draftfiles)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_no_file_uploaded', 'local_coursegen'),
                ];
            }

            // Save to syllabus area.
            file_save_draft_area_files(
                $params['draftitemid'],
                $syscontext->id,
                'local_coursegen',
                'syllabus',
                $params['sessionid'],
                ['subdirs' => 0, 'maxfiles' => 1]
            );

            // Get the saved file.
            $files = $fs->get_area_files(
                $syscontext->id,
                'local_coursegen',
                'syllabus',
                $params['sessionid'],
                'id',
                false
            );

            if (empty($files)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_file_save_failed', 'local_coursegen'),
                ];
            }

            $file = reset($files);
            $filename = $file->get_filename();

            // Upload to Datacurso API.
            $apiservice = new ai_course_api_service();
            $response = $apiservice->upload_syllabus($threadid, $file);

            // Update session coursedata to include syllabus context type.
            $coursedata = json_decode($session->get('coursedata'), true);
            $coursedata['local_coursegen_context_type'] = 'syllabus';
            $session->set('coursedata', json_encode($coursedata));
            $session->update();

            return [
                'success' => true,
                'filename' => $filename,
                'message' => get_string('courseai_syllabus_upload_success', 'local_coursegen'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'filename' => '',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'filename' => new external_value(PARAM_TEXT, 'Uploaded filename'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }
}
