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

use core_course_category;
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
     * Start a course planning session and persist local session data.
     *
     * @param string $prompt Course description prompt.
     * @param string $lang Language code.
     * @param bool $withimages Include image suggestions.
     * @param int $systeminstructionid Optional system instruction id.
     * @param int $userid Current user id.
     * @return array
     */
    public static function start_course_planning(
        string $prompt,
        string $lang,
        bool $withimages,
        int $systeminstructionid,
        int $userid
    ): array {
        $instructions = null;
        if ($systeminstructionid > 0) {
            $content = system_instruction_service::get_instruction_content($systeminstructionid);
            $instructions = $content !== '' ? $content : null;
        }

        $apiservice = new ai_course_api_service();
        $response = $apiservice->start_course_planning([
            'prompt' => $prompt,
            'instructions' => $instructions,
            'lang' => $lang,
            'with_images' => $withimages,
        ]);

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

        $defaultcategory = core_course_category::get_default();
        $defaultcategoryid = $defaultcategory ? (int)$defaultcategory->id : 0;

        $prompttitle = self::build_course_title_from_prompt($prompt, $lang);
        $generatedshortname = self::build_shortname_from_title($prompttitle, $userid);

        $session = new course_session();
        $session->set('userid', $userid);
        $session->set('session_id', $threadid);
        $session->set('status', course_session::STATUS_PENDING);
        $session->set('coursedata', json_encode([
            'category' => $defaultcategoryid,
            'fullname' => $prompttitle,
            'shortname' => $generatedshortname,
            'local_coursegen_lang' => $lang,
            'local_coursegen_generate_images' => $withimages ? 1 : 0,
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
            'message' => get_string('wizard_init_success', 'local_coursegen'),
        ];
    }

    /**
     * Build a user-friendly course title from a free-form prompt.
     *
     * @param string $prompt Free-form prompt from the wizard.
     * @param string $lang Language code from request context.
     * @return string
     */
    private static function build_course_title_from_prompt(string $prompt, string $lang = 'es'): string {
        $normalized = trim((string)preg_replace('/\s+/u', ' ', $prompt));
        $normalized = preg_replace('/^[\p{P}\p{Zs}]+/u', '', $normalized);
        $normalized = preg_replace(
            '/^(please\s+)?(create|generate|build|design|make|draft|crea|crear|genera|generar|disena|diseña|elabora|desarrolla|haz)\s+/iu',
            '',
            $normalized
        );
        $normalized = preg_replace('/^(an?|un|una)\s+/iu', '', $normalized);
        $normalized = preg_replace('/^(course|curso)\s*(about|on|of|sobre|de|acerca de)?\s*/iu', '', $normalized);
        $normalized = trim((string)$normalized, " \t\n\r\0\x0B.,;:!¡?¿-_");

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
     * Build a unique-ish shortname candidate from a title and user.
     *
     * @param string $title Course title.
     * @param int $userid Current user id.
     * @return string
     */
    private static function build_shortname_from_title(string $title, int $userid): string {
        $shortnamebase = \core_text::strtolower($title);
        $shortnamebase = preg_replace('/[^a-z0-9]+/i', '-', $shortnamebase);
        $shortnamebase = trim((string)$shortnamebase, '-');

        if ($shortnamebase === '') {
            $shortnamebase = 'ai-course';
        }

        $shortnamebase = (string)\core_text::substr($shortnamebase, 0, 40);
        return $shortnamebase . '-' . $userid . '-' . time();
    }
}
