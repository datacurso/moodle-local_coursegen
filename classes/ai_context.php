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
 * Class ai_context
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_context {
    /** @var string Context type system instruction */
    const CONTEXT_TYPE_SYSTEM_INSTRUCTION = 'system_instruction';
    /** @var string Context type syllabus */
    const CONTEXT_TYPE_SYLLABUS = 'syllabus';
    /** @var string Context type custom prompt */
    const CONTEXT_TYPE_CUSTOM_PROMPT = 'prompt';

    /**
     * Uploads the content of the system instruction to the AI endpoint.
     *
     * @param system_instruction $model The system instruction selected.
     */
    public static function upload_model_to_ai(system_instruction $model): void {
        global $CFG;

        try {
            $siteid = md5($CFG->wwwroot);

            $postdata = [
                'model_name' => $model->id,
                'model_context' => $model->content,
                'site_id' => $siteid,
            ];

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $client->request('POST', '/context/upload-model-context', $postdata);
        } catch (\Exception $e) {
            // Show error notification to the user.
            \core\notification::error(get_string('error_upload_failed_system_instruction', 'local_coursegen', $e->getMessage()));
        }
    }

    /**
     * Get AI course context info from database.
     *
     * @param int $courseid Course ID
     * @return mixed Course AI context info
     */
    public static function get_course_context_info($courseid): mixed {
        global $DB;

        $aicontext = $DB->get_record_sql(
            'SELECT cc.context_type, cc.prompt_text, cc.lang, si.name AS system_instruction_name
            FROM
                {local_coursegen_course_context} cc
                LEFT JOIN {local_coursegen_system_instruction} si ON cc.system_instruction_id = si.id
            WHERE
                cc.courseid = ?',
            [$courseid]
        );

        if ($aicontext && !isset($aicontext->name)) {
            $aicontext->name = $aicontext->system_instruction_name;
        }

        return $aicontext;
    }

    /**
     * Returns a valid course AI context or null if not properly configured.
     * - For context type 'model': requires a non-empty model name.
     * - For context type 'syllabus': requires at least one syllabus file saved in course context.
     *
     * @param int $courseid Course ID
     * @return \stdClass|null Object with properties context_type and system_instruction_name (or null)
     */
    public static function get_valid_course_context(int $courseid): ?\stdClass {
        $aicontext = self::get_course_context_info($courseid);
        if (!$aicontext || empty($aicontext->context_type)) {
            return null;
        }

        if ($aicontext->context_type === self::CONTEXT_TYPE_SYSTEM_INSTRUCTION) {
            if (empty($aicontext->name)) {
                return null;
            }
            return (object) [
                'context_type' => self::CONTEXT_TYPE_SYSTEM_INSTRUCTION,
                'system_instruction_name' => $aicontext->name,
            ];
        }

        if ($aicontext->context_type === self::CONTEXT_TYPE_SYLLABUS) {
            if (!self::course_has_syllabus_file($courseid)) {
                return null;
            }
            return (object) [
                'context_type' => self::CONTEXT_TYPE_SYLLABUS,
            ];
        }

        if ($aicontext->context_type === self::CONTEXT_TYPE_CUSTOM_PROMPT) {
            $prompttext = $aicontext->prompt_text ?? '';
            $stripped = trim(strip_tags($prompttext));
            if ($prompttext === null || $stripped === '') {
                return null;
            }
            return (object) [
                'context_type' => self::CONTEXT_TYPE_CUSTOM_PROMPT,
                'prompt_text' => $prompttext,
            ];
        }

        return null;
    }

    /**
     * Whether any planning session of the course has a stored syllabus file.
     *
     * Syllabus files live in the SYSTEM context with the planning session id
     * as item id (see courseai_syllabus_upload).
     *
     * @param int $courseid Course ID
     * @return bool
     */
    private static function course_has_syllabus_file(int $courseid): bool {
        global $DB;

        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $sessionids = $DB->get_fieldset_select('local_coursegen_course_sessions', 'id', 'courseid = ?', [$courseid]);
        foreach ($sessionids as $sessionid) {
            $syllabusarea = self::CONTEXT_TYPE_SYLLABUS;
            $files = $fs->get_area_files($syscontextid, 'local_coursegen', $syllabusarea, (int)$sessionid, 'itemid', false);
            if (!empty($files)) {
                return true;
            }
        }

        return false;
    }
}
