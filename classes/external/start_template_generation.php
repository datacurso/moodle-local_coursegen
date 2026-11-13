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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_course_image_slots;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_reference_uploads;
use local_coursegen\local\space\activity_spaces;
use local_coursegen\local\space\space_files_request;

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
            'spacefiles' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id of the space in the template course'),
                    'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the file the teacher brought'),
                ]),
                'The files the teacher brought for the spaces of the template',
                VALUE_DEFAULT,
                []
            ),
            'spaceactivities' => new external_multiple_structure(
                new external_single_structure([
                    'uid' => new external_value(PARAM_ALPHANUMEXT, 'Uid of the space of the template'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id of the activity the teacher brings'),
                ]),
                'The activities the teacher brings to the spaces of the template',
                VALUE_DEFAULT,
                []
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
     * @param array $spacefiles Each: cmid, draftitemid.
     * @param array $spaceactivities Each: uid (of the space), cmid (of the activity).
     * @return array
     */
    public static function execute($templateid, $prompt = '', $draftitemid = 0, $lang = '', $withimages = false, $spacefiles = [],
            $spaceactivities = []) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'templateid' => $templateid,
            'prompt' => $prompt,
            'draftitemid' => $draftitemid,
            'lang' => $lang,
            'withimages' => $withimages,
            'spacefiles' => $spacefiles,
            'spaceactivities' => $spaceactivities,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);
        if ($params['draftitemid'] > 0 || !empty($params['spacefiles'])) {
            require_capability('local/coursegen:uploadcoursesyllabus', $context);
        }
        // Refused before anything is sent: a space that is not the template's, or a required one with no file.
        $spaces = space_files_request::validated($params['templateid'], (int) $USER->id, $params['spacefiles']);
        // The same for the activities the teacher brings to the spaces: each one fits its space, none is missing.
        $spaceactivities = activity_spaces::validated($params['templateid'], (int) $USER->id, $params['spaceactivities']);

        if ($params['withimages']) {
            require_capability('local/coursegen:generatecourseimages', $context);
        }
        $payload = template_export_service::build_init_payload(
            $params['templateid'],
            $params['prompt'],
            $params['lang'],
            (bool) $params['withimages']
        );

        // Read first: a reference marker with no usable target is refused before a session exists.
        $referenceuploads = template_reference_uploads::plan($payload);
        $template = \local_coursegen\local\models\template::get_record(['id' => $params['templateid']]);
        $slots = $params['withimages']
            ? template_course_image_slots::for_course(get_course((int) $template->get('courseid')))
            : [];
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
        $coursedata = [
            'templateid' => $params['templateid'],
            'payload' => $payload,
            'syllabus' => $file !== null ? $file->get_filename() : '',
            // Copied into the course when it is created, each in the place of its space.
            'space_activities' => $spaceactivities,
        ];
        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => $threadid,
            'status' => course_session::STATUS_PENDING,
            'templateid' => (int) $params['templateid'],
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();
        if ($file !== null) {
            course_session_service::keep_syllabus((int) $session->get('id'), $file);
        }
        $sessionid = (int) $session->get('id');
        space_files_request::store($spaces, (int) $USER->id, $sessionid);

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
     * The first real file in a draft area, or null when there is none.
     *
     * @param int $draftitemid
     * @return \stored_file|null
     */
    private static function draft_file(int $draftitemid): ?\stored_file {
        return \local_coursegen\local\service\draft_file::first($draftitemid);
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
