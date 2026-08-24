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

use local_coursegen\local\image_generation\image_policy_builder;
use local_coursegen\local\h5p_core_api;
use local_coursegen\local\image_generation\activities;
use local_coursegen\local\models\course_session;

/**
 * Service for AI course planning session orchestration.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_planning_service {
    /**
     * Whether AI-generated subsections are available on this site.
     *
     * Requires the admin setting to be enabled AND the subsection activity
     * module (Moodle 4.5 delegated sections) to be enabled.
     *
     * @return bool
     */
    public static function subsections_available(): bool {
        if (!get_config('local_coursegen', 'enablesubsections')) {
            return false;
        }
        $enabledmods = \core_plugin_manager::instance()->get_enabled_plugins('mod');
        return array_key_exists('subsection', $enabledmods);
    }

    /**
     * Start a course planning session and persist local session data.
     *
     * @param string $prompt Course description prompt.
     * @param string $lang Language code.
     * @param bool $withimages Include image suggestions.
     * @param int $systeminstructionid Optional system instruction id.
     * @param int $userid Current user id.
     * @param bool $withsubsections Allow the AI to group activities into subsections.
     * @return array
     */
    public static function start_course_planning(
        string $prompt,
        string $lang,
        bool $withimages,
        int $systeminstructionid,
        int $userid,
        bool $withsubsections = false
    ): array {
        $instructions = null;
        if ($systeminstructionid > 0) {
            $content = system_instruction_service::get_instruction_content($systeminstructionid);
            $instructions = $content !== '' ? $content : null;
        }

        $available = self::subsections_available();

        // Server-side gate: the flag only travels when the feature is enabled
        // and mod_subsection is available, whatever the client sent.
        $withsubsections = $withsubsections && $available;

        $apiservice = static::get_api_service();

        $payload = [
            'prompt' => $prompt,
            'instructions' => $instructions,
            'lang' => $lang,
            'with_images' => $withimages,
            'with_subsections' => $withsubsections,
            // Site capability (setting + mod_subsection), distinct from the
            // user's per-course choice: lets the service offer enabling
            // subsections when the prompt asks for them.
            'subsections_available' => $available,
        ];

        // A disabled (or never configured) policy is omitted: it must not
        // override the teacher's explicit image toggle by suppressing the
        // course image suggestions (regression guard: see
        // test_disabled_image_policy_should_be_omitted).
        if ($withimages) {
            $imagepolicy = image_policy_builder::build();
            if (($imagepolicy['mode'] ?? '') !== activities::MODE_DISABLED) {
                $payload['image_policy'] = $imagepolicy;
            }
        }

        // Site file-type group catalog, so activities generated in the course flow
        // (e.g. assignments) can restrict accepted file types against real groups.
        $filetypegroups = filetype_catalog_service::get_groups();
        if ($filetypegroups !== null) {
            $payload['filetype_groups'] = $filetypegroups;
        }

        // Tell the service which H5P framework (core API) this Moodle runs, so
        // generated .h5p packages use a compatible library set. Omitted when
        // unresolvable; the service then falls back to its most-compatible set.
        $h5pcoreapi = h5p_core_api::resolve();
        if ($h5pcoreapi !== null) {
            $payload['h5p_core_api'] = $h5pcoreapi;
        }

        $response = $apiservice->start_course_planning($payload);

        if (empty($response['thread_id'])) {
            return [
                'success' => false,
                'sessionid' => 0,
                'threadid' => '',
                'streamingurl' => '',
                'message' => get_string('error_api_response', 'local_coursegen'),
            ];
        }

        $threadid = (string)$response['thread_id'];
        $streamingurl = $apiservice->get_course_streaming_url($threadid);

        $session = new course_session();
        $session->set('userid', $userid);
        $session->set('session_id', $threadid);
        $session->set('status', course_session::STATUS_PENDING);
        $session->set('coursedata', json_encode([
            'local_coursegen_lang' => $lang,
            'local_coursegen_generate_images' => $withimages ? 1 : 0,
            'local_coursegen_generate_subsections' => $withsubsections ? 1 : 0,
            'local_coursegen_context_type' => 'customprompt',
            'local_coursegen_custom_prompt' => $prompt,
            'local_coursegen_use_system_instruction' => $systeminstructionid > 0 ? 1 : 0,
            'local_coursegen_select_system_instruction' => $systeminstructionid,
        ]));
        $session->create();

        return [
            'success' => true,
            'sessionid' => (int)$session->get('id'),
            'threadid' => $threadid,
            'streamingurl' => $streamingurl,
            'message' => get_string('courseai_init_success', 'local_coursegen'),
        ];
    }

    /**
     * Build the AI course API service used by this service.
     *
     * Extracted as a protected factory so PHPUnit tests can override it
     * through a testable subclass (late static binding).
     *
     * @return ai_course_api_service
     */
    protected static function get_api_service(): ai_course_api_service {
        return new ai_course_api_service();
    }

    /**
     * Build a user-friendly course title from a free-form prompt.
     *
     * @param string $prompt Free-form prompt from the courseai.
     * @param string $lang Language code from request context.
     * @return string
     */
    private static function build_course_title_from_prompt(string $prompt, string $lang = 'es'): string {
        $normalized = trim((string)preg_replace('/\s+/u', ' ', $prompt));

        if ($normalized === '') {
            return get_string('createwithai', 'local_coursegen');
        }

        $topic = trim((string)\core_text::substr($normalized, 0, 180));

        if ($lang === 'en') {
            return 'Course: ' . $topic;
        }

        return 'Curso: ' . $topic;
    }

    /**
     * Build a temporary shortname for the session payload.
     *
     * Final semantic shortname is provided by `course_configuration` and
     * normalized at course creation time.
     *
     * @param int $userid Current user id.
     * @return string
     */
    private static function build_initial_shortname(int $userid): string {
        return 'courseai-' . $userid . '-' . time();
    }
}
