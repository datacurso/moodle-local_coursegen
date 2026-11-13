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
 * Starts a course generation from a saved template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_course_image_slots;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_reference_uploads;
use local_coursegen\local\service\template_units;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Exports a template, sends it to the AI service, attaches the syllabus and starts the run.
 */
class start_template_generation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
            'prompt' => new external_value(PARAM_RAW, 'The professor\'s general instruction', VALUE_DEFAULT, ''),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the syllabus, 0 for none', VALUE_DEFAULT, 0),
            'lang' => new external_value(
                PARAM_LANG,
                'Language of the generated course, empty for the template\'s',
                VALUE_DEFAULT,
                ''
            ),
            'withimages' => new external_value(PARAM_BOOL, 'Whether to generate images', VALUE_DEFAULT, false),
            'units' => new external_value(
                PARAM_INT,
                'Number of units wanted; only a template with units (Flexible sections) takes it, null for its own',
                VALUE_DEFAULT,
                null
            ),
            'systeminstructionid' => new external_value(
                PARAM_INT,
                'Institutional guideline (system instruction) id; its text is read here, never taken from the client',
                VALUE_DEFAULT,
                null
            ),
        ]);
    }

    /**
     * Start the generation.
     *
     * @param int $templateid
     * @param string $prompt
     * @param int $draftitemid
     * @param string $lang
     * @param bool $withimages
     * @param int|null $units Number of units wanted, null for the template's own.
     * @param int|null $systeminstructionid Institutional guideline id, null for none.
     * @return array
     */
    public static function execute(
        $templateid,
        $prompt = '',
        $draftitemid = 0,
        $lang = '',
        $withimages = false,
        $units = null,
        $systeminstructionid = null
    ) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'templateid' => $templateid,
            'prompt' => $prompt,
            'draftitemid' => $draftitemid,
            'lang' => $lang,
            'withimages' => $withimages,
            'units' => $units,
            'systeminstructionid' => $systeminstructionid,
        ]);
        $guidelineid = (int) ($params['systeminstructionid'] ?? 0);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);
        if ($params['draftitemid'] > 0) {
            require_capability('local/coursegen:uploadcoursesyllabus', $context);
        }
        if ($params['withimages']) {
            require_capability('local/coursegen:generatecourseimages', $context);
        }
        [$payload, $coursedata] = self::prepare(
            (int) $params['templateid'],
            $params['prompt'],
            $params['lang'],
            (bool) $params['withimages'],
            $params['units'],
            $guidelineid
        );

        // Read first: a reference marker with no usable target is refused before a session exists.
        $referenceuploads = template_reference_uploads::plan($payload);
        $template = template::get_record(['id' => $params['templateid']]);
        $slots = template_course_image_slots::for_course(get_course((int) $template->get('courseid')));
        $referenceuploads = array_merge($referenceuploads, template_course_image_slots::uploads($slots));

        $api = new template_ai_api_service();
        $threadid = $api->init($payload);
        template_reference_uploads::send($api, $threadid, $referenceuploads);

        $file = self::draft_file($params['draftitemid']);
        if ($file !== null) {
            $api->upload_reference_file($threadid, $file);
        }

        // The exact payload the run was given, kept alongside the session:
        // a preview reading it back later must see what the run saw, not the
        // template's course as it happens to look whenever the preview loads.
        // The syllabus's name is kept too, so the course says later what it was made from.
        $coursedata['syllabus'] = $file !== null ? $file->get_filename() : '';
        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => $threadid,
            'status' => course_session::STATUS_PENDING,
            'templateid' => (int) $params['templateid'],
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();

        // Nothing has run yet: consuming the stream is what drives the
        // generation, so the caller opens this URL and watches it happen,
        // rather than starting a blind run and asking whether it is done.
        return [
            'threadid' => $threadid,
            'sessionid' => (int) $session->get('id'),
            'streamurl' => $api->stream_url($threadid),
        ];
    }

    /**
     * The payload of the run and the data its session keeps, both built from the same guideline id.
     *
     * The syllabus name is not known yet, so it starts empty and the caller sets it once the file is attached.
     *
     * @param int $templateid
     * @param string $prompt
     * @param string $lang
     * @param bool $withimages
     * @param int|null $units
     * @param int|null $systeminstructionid Institutional guideline id, null for none.
     * @return array [payload, coursedata]
     */
    public static function prepare(
        int $templateid,
        string $prompt,
        string $lang,
        bool $withimages,
        ?int $units,
        ?int $systeminstructionid = null
    ): array {
        $guidelineid = (int) $systeminstructionid;
        $payload = self::payload_for($templateid, $prompt, $lang, $withimages, $units, $guidelineid);
        // A deleted or empty guideline sends no text, so the session must not claim it was used.
        $kept = isset($payload['instructions']) ? $guidelineid : 0;
        return [$payload, self::coursedata_for($templateid, $payload, '', $kept)];
    }

    /**
     * The payload of the run, with the units the professor asked for when they differ from the template's.
     *
     * The number comes from the client, so it is kept between 1 and the maximum here, and a template
     * with no units (any other format) or an unchanged count is exported exactly as it is.
     *
     * @param int $templateid
     * @param string $prompt
     * @param string $lang
     * @param bool $withimages
     * @param int|null $units
     * @param int|null $systeminstructionid Institutional guideline id; only its stored text travels, and only when it resolves.
     * @return array
     */
    public static function payload_for(
        int $templateid,
        string $prompt,
        string $lang,
        bool $withimages,
        ?int $units,
        ?int $systeminstructionid = null
    ): array {
        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }
        $modinfo = get_fast_modinfo(get_course((int) $template->get('courseid')));
        return template_export_service::build_init_payload(
            $templateid,
            $prompt,
            $lang,
            $withimages,
            template_units::requested($modinfo, self::resolve_units($units, (int) $systeminstructionid)),
            self::guideline_text((int) $systeminstructionid)
        );
    }

    /**
     * The units the run asks for: an explicit number wins, then the units of the chosen guideline, else none.
     *
     * Null leaves the template's own count; whether the template has units at all is decided afterwards.
     *
     * @param int|null $units What the professor asked for; only a number above zero counts.
     * @param int $systeminstructionid Institutional guideline id, 0 for none.
     * @return int|null
     */
    private static function resolve_units(?int $units, int $systeminstructionid): ?int {
        if ($units !== null && $units > 0) {
            return $units;
        }
        if ($systeminstructionid <= 0) {
            return null;
        }
        return system_instruction_service::get_units($systeminstructionid);
    }

    /**
     * The stored text of a guideline, empty when none was chosen or it is missing, deleted or empty.
     *
     * Same resolution as the free mode, which ignores such an id silently.
     *
     * @param int $systeminstructionid
     * @return string
     */
    private static function guideline_text(int $systeminstructionid): string {
        if ($systeminstructionid <= 0) {
            return '';
        }
        return system_instruction_service::get_instruction_content($systeminstructionid);
    }

    /**
     * What the session keeps of the run: the template, the exact payload, the syllabus and, when chosen, the guideline.
     *
     * The guideline keys are the ones the free mode stores, and are only there when a guideline was chosen.
     *
     * @param int $templateid
     * @param array $payload
     * @param string $syllabus Name of the attached syllabus, empty for none.
     * @param int $systeminstructionid
     * @return array
     */
    public static function coursedata_for(int $templateid, array $payload, string $syllabus, int $systeminstructionid): array {
        $coursedata = [
            'templateid' => $templateid,
            'payload' => $payload,
            'syllabus' => $syllabus,
        ];
        if ($systeminstructionid > 0) {
            $coursedata['local_coursegen_use_system_instruction'] = 1;
            $coursedata['local_coursegen_select_system_instruction'] = $systeminstructionid;
        }
        return $coursedata;
    }

    /**
     * The first real file in a draft area, or null when there is none.
     *
     * @param int $draftitemid
     * @return \stored_file|null
     */
    private static function draft_file(int $draftitemid): ?\stored_file {
        if ($draftitemid <= 0) {
            return null;
        }
        global $USER;
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'itemid', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_TEXT, 'Generation thread id'),
            'sessionid' => new external_value(PARAM_INT, 'Local session id'),
            'streamurl' => new external_value(PARAM_URL, 'SSE URL whose consumption runs the generation'),
        ]);
    }
}
