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

        $apiservice = new ai_course_api_service();

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

        if ($withimages) {
            $payload['image_policy'] = self::build_image_policy();
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
     * Build the image generation policy from plugin settings.
     *
     * Reads the current image generation configuration stored in
     * local_coursegen plugin settings and returns a structured array
     * with the global mode, override flags, and per-activity policies.
     *
     * @return array
     */
    private static function build_image_policy(): array {
        $mode = get_config('local_coursegen', 'generationmode') ?: activities::MODE_DISABLED;
        $overridecourse = (bool) ((int) get_config('local_coursegen', 'overridecourse') === 1);
        $overrideactivity = (bool) ((int) get_config('local_coursegen', 'overrideactivity') === 1);

        $activitiesconfig = array_map(
            fn(array $definition): array => self::build_activity_policy($definition),
            activities::get_definitions()
        );

        return [
            'mode' => $mode,
            'overridecourse' => $overridecourse,
            'overrideactivity' => $overrideactivity,
            'activities' => $activitiesconfig,
        ];
    }

    /**
     * Build the image policy for a single activity type from its definition.
     *
     * @param array $definition Activity definition from activities::get_definitions()
     * @return array
     */
    private static function build_activity_policy(array $definition): array {
        $enabled = (int) get_config('local_coursegen', $definition['configenable']) === 1;

        $partsconfig = array_map(
            fn(array $part): array => self::build_part_policy($part),
            $definition['parts'] ?? []
        );

        return [
            'id' => $definition['id'],
            'enabled' => $enabled,
            'parts' => $partsconfig,
        ];
    }

    /**
     * Build the image policy for a single part from its definition.
     *
     * @param array $part Part definition from activities::get_definitions()
     * @return array
     */
    private static function build_part_policy(array $part): array {
        $enabled = (int) get_config('local_coursegen', $part['configenable']) === 1;

        $maximages = 0;
        $configmaximages = $part['configmaximages'] ?? null;
        if ($configmaximages !== null) {
            $savedmax = (int) get_config('local_coursegen', $configmaximages);
            if ($savedmax > 0) {
                $maximages = $savedmax;
            }
        }

        return [
            'id' => $part['id'],
            'enabled' => $enabled,
            'maximages' => $maximages,
        ];
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
