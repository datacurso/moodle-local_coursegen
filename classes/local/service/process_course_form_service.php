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

use local_coursegen\local\models\course_context;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\service\course_session_service;
use moodle_url;
use stdClass;

/**
 * Service responsible for processing the course_edit_form submission for AI.
 *
 * This service encapsulates the logic to rebuild the course/category context,
 * instantiate the course_edit_form with the provided data, validate it and,
 * when appropriate, create a planning session and trigger the AI syllabus
 * processing.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_course_form_service {
    /**
     * Process the AJAX submission of the course_edit_form.
     *
     * @param array $formdata Parsed form data (from the url-encoded payload).
     * @return array Result structure compatible with the webservice response.
     */
    public static function process(array $formdata): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/edit_form.php');

        $category = self::get_target_category($formdata);
        $course = self::get_course($formdata, $category);
        $editoroptions = self::build_editor_options();

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
            return self::process_form_data($data);
        }

        return [
            'submitted' => false,
        ];
    }

    /**
     * Process the validated form data and trigger the AI course planning when required.
     *
     * This method always calls the external init endpoint to start a planning session
     * and persists the local course_session. When the selected context type is
     * syllabus, it additionally uploads the syllabus file.
     *
     * @param stdClass $data Validated form data.
     * @return array Result structure compatible with the webservice response.
     */
    private static function process_form_data(stdClass $data): array {
        global $CFG, $USER;

        $contexttype = $data->local_coursegen_context_type ?? '';

        // Common config for the planning session.
        $selectedlang = $data->local_coursegen_lang ?? 'es';
        $generateimages = $data->local_coursegen_generate_images ?? 0;

        // Optional system instruction used for all context types.
        $useinstruction = !empty($data->local_coursegen_use_system_instruction);
        $selectedinstructionid = $useinstruction ? (int) ($data->local_coursegen_select_system_instruction ?? 0) : 0;
        $instructions = system_instruction_service::get_instruction_content($selectedinstructionid);

        // Build payload matching CourseInitPayload in the external service.
        $payload = [
            'instructions' => $instructions,
            'lang' => $selectedlang,
            'with_images' => (bool) $generateimages == 1,
            'context_type' => $contexttype,
        ];

        $apiservice = new ai_course_api_service();
        $result = $apiservice->start_course_planning($payload);

        // Persist the local planning session using the returned thread id.
        $sessionid = $result['thread_id'];
        $session = course_session_service::create_from_form_data($data, $USER->id, $sessionid);
        $recordid = $session->get('id');

        $url = new moodle_url('/local/coursegen/aicoursecreation.php', ['sessionid' => $recordid]);

        if ($contexttype === course_context::CONTEXT_TYPE_SYLLABUS) {
            self::process_syllabus_context($data, $recordid, $sessionid);
        }

        return [
            'submitted' => true,
            'data' => [
                'message' => get_string('courseformprocessed', 'local_coursegen'),
                'recordid' => $recordid,
                'redirecturl' => $url->out(false),
            ],
        ];
    }

    /**
     * Handle syllabus upload for an existing planning session.
     *
     * @param stdClass $data Validated form data.
     * @param int $recordid Local course_session id.
     * @param string $sessionid External planning session identifier.
     * @return void
     */
    private static function process_syllabus_context(stdClass $data, int $recordid, string $sessionid): void {
        $draftitemid = (int) ($data->local_coursegen_syllabus_pdf ?? 0);

        if (empty($draftitemid)) {
            return;
        }

        // Save syllabus file linked to the existing session record.
        self::save_syllabus_from_draft($recordid, $draftitemid);

        $file = self::get_syllabus_file($recordid);
        if (!$file) {
            return;
        }

        $apiservice = new ai_course_api_service();
        $apiservice->upload_syllabus($sessionid, $file);
    }

    /**
     * Save syllabus PDF file from draft area to system context.
     *
     * @param int $itemid Item ID used to store the syllabus file in system context.
     * @param int|null $draftitemid Draft item ID from the syllabus file picker
     * @return bool True if syllabus was saved successfully, false otherwise
     */
    private static function save_syllabus_from_draft(int $itemid, ?int $draftitemid = null): bool {
        if (!$draftitemid) {
            return false;
        }

        // Syllabus file options - only PDF files allowed.
        $fileoptions = [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.pdf'],
        ];

        try {
            file_save_draft_area_files(
                $draftitemid,
                \context_system::instance()->id,
                'local_coursegen',
                course_context::CONTEXT_TYPE_SYLLABUS,
                $itemid,
                $fileoptions
            );
            return true;
        } catch (\Exception $e) {
            // Log the error or handle it as needed.
            debugging('Error saving syllabus from draft: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Get the stored syllabus file for the given item ID in the system context.
     *
     * @param int $itemid Item ID used to store the syllabus file in system context.
     * @return \stored_file|null
     */
    private static function get_syllabus_file(int $itemid): ?\stored_file {
        $fs = get_file_storage();
        $syscontext = \context_system::instance();

        $files = $fs->get_area_files(
            $syscontext->id,
            'local_coursegen',
            course_context::CONTEXT_TYPE_SYLLABUS,
            $itemid,
            'itemid',
            false
        );

        if (empty($files)) {
            return null;
        }

        $file = reset($files);
        return $file instanceof \stored_file ? $file : null;
    }

    /**
     * Build the minimal course object required by course_edit_form.
     *
     * @param array $data Parsed payload.
     * @param object $category Resolved category object.
     * @return stdClass
     */
    public static function get_course(array $data, object $category): stdClass {
        $courseid = isset($data['id']) ? (int)$data['id'] : 0;

        if ($courseid) {
            // Existing course: load full record so the form and custom fields have all data.
            $course = get_course($courseid);
        } else {
            // New course: build a minimal course object.
            $course = new stdClass();
            $course->id = 0;
            $course->category = $category->id;
        }

        return $course;
    }

    /**
     * Resolve the target category for the course_edit_form.
     *
     * @param array $data Parsed payload.
     * @return object Category record or core_course_category instance.
     */
    public static function get_target_category(array $data): object {
        global $DB;

        $courseid = isset($data['id']) ? (int)$data['id'] : 0;
        $categoryid = isset($data['category']) ? (int)$data['category'] : 0;

        if ($courseid) {
            $course = get_course($courseid);
            return $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);
        }

        if ($categoryid) {
            return $DB->get_record('course_categories', ['id' => $categoryid], '*', MUST_EXIST);
        }

        return \core_course_category::get_default();
    }

    /**
     * Build the editor options used by the course_edit_form.
     *
     * @return array
     */
    public static function build_editor_options(): array {
        global $CFG;

        return [
            'maxfiles' => 0,
            'maxbytes' => $CFG->maxbytes,
            'trusttext' => false,
            'noclean' => true,
        ];
    }
}
