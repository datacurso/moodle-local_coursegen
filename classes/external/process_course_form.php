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

/**
 * External function to store raw course edit form data for AI processing.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use aiprovider_datacurso\httpclient\ai_course_api;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\ai_course;
use local_coursegen\ai_context;
use local_coursegen\system_instruction;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/edit_form.php');

/**
 * Web service that stores the full course form payload into local_coursegen_course_sessions.
 */
class process_course_form extends external_api {

    /**
     * Defines the parameters accepted by the web service.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'formdata' => new external_value(
                PARAM_RAW,
                'Url-encoded form data string'
            ),
        ]);
    }

    /**
     * Process the AJAX submission of the course_edit_form.
     *
     * On success, stores the validated form data as JSON in local_coursegen_course_sessions
     * and returns submitted=true with JSON-encoded result. Otherwise, returns
     * submitted=false without rendering HTML/JS.
     *
     * @param string $formdatastr Url-encoded form data.
     * @return array
     */
    public static function execute(string $formdatastr): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'formdata' => $formdatastr,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $formdata = [];
        parse_str($params['formdata'], $formdata);

        // Rebuild minimal $course and $category similar to validate_course_form.
        $course = null;
        $category = null;

        $courseid = isset($formdata['id']) ? (int) $formdata['id'] : 0;
        $categoryid = isset($formdata['category']) ? (int) $formdata['category'] : 0;

        if ($courseid) {
            $course = get_course($courseid);
            $category = $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);
        } else {
            if ($categoryid) {
                $category = $DB->get_record('course_categories', ['id' => $categoryid], '*', MUST_EXIST);
            } else {
                $category = \core_course_category::get_default();
            }

            $course = new \stdClass();
            $course->id = 0;
            $course->category = $category->id;
        }

        $editoroptions = [
            'maxfiles' => 0,
            'maxbytes' => $CFG->maxbytes,
            'trusttext' => false,
            'noclean' => true,
        ];

        $args = [
            'course' => $course,
            'category' => $category,
            'editoroptions' => $editoroptions,
            'returnto' => 0,
            'returnurl' => '',
        ];

        // Instantiate the standard course_edit_form passing ajaxformdata, so it does not rely on $_POST.
        $form = new \course_edit_form(null, $args, 'post', '', [], true, $formdata);

        if (!$form->is_cancelled() && $form->is_submitted() && $form->is_validated()) {
            $data = $form->get_data();

            $contexttype = $data->local_coursegen_context_type ?? '';
            $draftitemid = (int) ($data->local_coursegen_syllabus_pdf ?? 0);
            $selectedlang = $data->local_coursegen_lang ?? null;
            $generateimages = $data->local_coursegen_generate_images ?? 0;

            $useinstruction = !empty($data->local_coursegen_use_system_instruction);
            $selectedinstructionid = $useinstruction ? (int) ($data->local_coursegen_select_system_instruction ?? 0) : 0;

            if ($contexttype === ai_context::CONTEXT_TYPE_SYLLABUS && !empty($draftitemid)) {
                $record = new \stdClass();
                $record->userid = $USER->id;
                $record->coursedata = json_encode($data, JSON_UNESCAPED_UNICODE);
                $record->timecreated = time();
                $record->timemodified = $record->timecreated;

                $recordid = $DB->insert_record('local_coursegen_course_sessions', $record);

                $url = new moodle_url('/local/coursegen/aicoursecreation.php', ['sessionid' => $recordid]);

                if (ai_context::save_syllabus_from_draft($recordid, $draftitemid)) {
                    $fs = get_file_storage();
                    $syscontext = \context_system::instance();
                    $files = $fs->get_area_files(
                        $syscontext->id,
                        'local_coursegen',
                        ai_context::CONTEXT_TYPE_SYLLABUS,
                        $recordid,
                        'itemid',
                        false
                    );

                    $file = $files ? reset($files) : false;
                    if ($file) {
                        $filepath = $file->copy_content_to_temp();

                        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
                        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

                        $client = new ai_course_api(null, $baseurl, $baseurleu);

                        $courseconfig = [
                            'language' => $selectedlang ?? 'es',
                            'with_images' => (bool) $generateimages == 1,
                            'context_type' => $contexttype,
                            'site_id' => ai_course::get_site_uuid(),
                            'moodle_user_id' => (string) $USER->id,
                            'site_url' => $CFG->wwwroot,
                        ];

                        // Resolve instructions text from selected system instruction, if any.
                        $instructions = '';
                        if ($selectedinstructionid > 0) {
                            $instructionmodel = system_instruction::get_by_id($selectedinstructionid);
                            if ($instructionmodel && !empty($instructionmodel->content)) {
                                $instructions = (string) $instructionmodel->content;
                            }
                        }

                        $extra = [
                            'instructions' => $instructions,
                            'config' => json_encode($courseconfig, JSON_UNESCAPED_UNICODE),
                        ];

                        $result = $client->upload_file(
                            '/course/init',
                            $filepath,
                            $file->get_mimetype(),
                            $file->get_filename(),
                            $extra
                        );

                        if (!is_array($result) || empty($result['thread_id'])) {
                            throw new \moodle_exception('error_starting_course_planning', 'local_coursegen');
                        }

                        $updaterecord = new \stdClass();
                        $updaterecord->id = $recordid;
                        $updaterecord->session_id = (string) $result['thread_id'];
                        $DB->update_record('local_coursegen_course_sessions', $updaterecord);
                    }
                }
            }

            return [
                'submitted' => true,
                'data' => [
                    'message' => 'course form processed',
                    'recordid' => $recordid,
                    'redirecturl' => $url->out(false),
                ],
            ];
        }

        return [
            'submitted' => false,
        ];
    }

    /**
     * Defines the return structure for the web service response.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submitted' => new external_value(PARAM_BOOL, 'If form was submitted and validated'),
            'data' => new external_single_structure([
                'message' => new external_value(PARAM_TEXT, 'Informational message about processing'),
                'recordid' => new external_value(PARAM_INT, 'ID of the stored local_coursegen_course_sessions record'),
                'redirecturl' => new external_value(PARAM_URL, 'URL where the client should be redirected'),
            ], 'Processing result data', VALUE_OPTIONAL),
        ]);
    }
}
