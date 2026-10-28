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

use context_course;
use context_user;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\draft_files;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Upload the picture a teacher chose for one slide of a Course Presentation, for an AI activity generation thread.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_slide_image_upload extends external_api {
    /** @var int The most slides a presentation has, as the AI service accepts them. */
    public const MAX_SLIDE = 30;

    /**
     * Describes the parameters of execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id containing the picture', VALUE_DEFAULT, 0),
            'slide' => new external_value(PARAM_INT, 'Number of the slide the picture is for, 1 is the first'),
            'filekey' => new external_value(PARAM_RAW, 'Key of a picture kept by the draft of the editor', VALUE_DEFAULT, ''),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'Kind of activity of that draft', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send the picture of a slide to the AI service.
     *
     * @param int $courseid
     * @param string $jobid
     * @param int $draftitemid
     * @param int $slide
     * @param string $filekey Key of a picture kept by the draft of the editor, instead of a draft item
     * @param string $kind Kind of activity of that draft
     * @return array
     */
    public static function execute(
        int $courseid,
        string $jobid,
        int $draftitemid,
        int $slide,
        string $filekey = '',
        string $kind = ''
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'draftitemid' => $draftitemid,
            'slide' => $slide,
            'filekey' => $filekey,
            'kind' => $kind,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Same flow, same credits: gate this step behind the same capabilities as the rest of the generation.
        require_capability('moodle/course:manageactivities', $context);
        require_capability('local/coursegen:createactivitywithai', $context);

        if ($params['slide'] < 1 || $params['slide'] > self::MAX_SLIDE) {
            throw new \moodle_exception('error_invalid_slide', 'local_coursegen');
        }

        $job = module_job_service::get_user_job($params['jobid'], $params['courseid'], $USER->id);
        $threadid = $job->get('job_id');
        if (!$threadid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }

        if ($params['filekey'] !== '') {
            $file = draft_files::get($USER->id, $params['courseid'], $params['kind'], $params['filekey']);
        } else {
            $files = get_file_storage()->get_area_files(
                context_user::instance($USER->id)->id,
                'user',
                'draft',
                $params['draftitemid'],
                'id',
                false
            );
            $file = reset($files);
        }
        if (!$file instanceof \stored_file) {
            throw new \moodle_exception('nofile', 'error');
        }

        try {
            (new ai_course_api_service())->upload_activity_slide_image($threadid, $file, $params['slide']);
        } catch (\moodle_exception $e) {
            $details = $e->debuginfo ?: $e->getMessage();
            throw new \moodle_exception('error_sending_activity_file', 'local_coursegen', '', $details);
        }

        return [
            'success' => true,
            'message' => get_string('activity_file_uploaded', 'local_coursegen'),
        ];
    }

    /**
     * Describes the return value of execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
