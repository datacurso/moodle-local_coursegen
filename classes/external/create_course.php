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
 * External API for creating courses with AI assistance.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use core_course_category;
use context_coursecat;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * External API for creating courses with AI assistance.
 */
class create_course extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Course planning session record ID'),
        ]);
    }

    /**
     * Create a course from stored session data and apply AI-generated content.
     *
     * @param int $recordid Session record ID in local_coursegen_course_sessions
     * @return array Result of the course content application
     * @throws moodle_exception
     */
    public static function execute($recordid) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
        ]);

        $recordid = (int)$params['recordid'];

        // Load session to validate category and capabilities.
        $session = course_session_service::get_user_session($recordid, $USER->id);
        $coursedatajson = $session->get('coursedata');
        if (empty($coursedatajson)) {
            throw new moodle_exception('error_no_coursedata_found', 'local_coursegen');
        }

        $coursedata = json_decode($coursedatajson);
        if (!is_object($coursedata)) {
            throw new moodle_exception('error_invalid_coursedata', 'local_coursegen');
        }

        $coursedata = self::hydrate_minimal_course_fields($coursedata, $recordid);

        if (empty($coursedata->category)) {
            throw new moodle_exception('error_missing_category', 'local_coursegen');
        }

        // Validate user has permission to create a course in the target category.
        $catcontext = context_coursecat::instance($coursedata->category);
        self::validate_context($catcontext);
        require_capability('moodle/course:create', $catcontext);

        return create_course_service::create_course($session, $coursedata);
    }

    /**
     * Ensure required core fields exist for courseai-created sessions.
     *
     * Wizard sessions can be created from a lightweight payload that does not
     * always contain category/fullname/shortname. This method fills safe
     * defaults so course creation can continue.
     *
     * @param \stdClass $coursedata Stored session course data.
     * @param int $recordid Session record ID.
     * @return \stdClass
     */
    private static function hydrate_minimal_course_fields(\stdClass $coursedata, int $recordid): \stdClass {
        if (empty($coursedata->category)) {
            $defaultcategory = core_course_category::get_default();
            if ($defaultcategory && !empty($defaultcategory->id)) {
                $coursedata->category = (int)$defaultcategory->id;
            }
        }

        $prompt = trim((string)($coursedata->local_coursegen_custom_prompt ?? ''));
        $lang = (string)($coursedata->local_coursegen_lang ?? 'es');
        $existingfullname = trim((string)($coursedata->fullname ?? ''));

        if ($existingfullname === '' || self::looks_like_instructional_prompt($existingfullname)) {
            $baseprompt = $prompt !== '' ? $prompt : $existingfullname;
            $coursedata->fullname = self::build_course_title_from_prompt($baseprompt, $lang);
        }

        if (empty($coursedata->shortname)) {
            $base = \core_text::strtolower((string)$coursedata->fullname);
            $base = preg_replace('/[^a-z0-9]+/i', '-', $base);
            $base = trim((string)$base, '-');

            if ($base === '') {
                $base = 'ai-course';
            }

            $base = (string)\core_text::substr($base, 0, 40);
            $coursedata->shortname = $base . '-' . $recordid;
        }

        return $coursedata;
    }

    /**
     * Return true when the text still looks like a raw instruction prompt.
     *
     * @param string $text Candidate title.
     * @return bool
     */
    private static function looks_like_instructional_prompt(string $text): bool {
        $candidate = trim((string)\core_text::strtolower($text));
        if ($candidate === '') {
            return false;
        }

        return (bool)preg_match(
            '/^(please\s+)?(create|generate|build|design|make|draft|crea|crear|genera|generar|disena|diseña|elabora|desarrolla|haz)\b/iu',
            $candidate
        );
    }

    /**
     * Build a user-friendly course title from a free-form prompt.
     *
     * @param string $prompt Free-form user prompt.
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

        $maxlen = 180;
        $topic = trim((string)\core_text::substr($normalized, 0, $maxlen));

        if ($lang === 'en') {
            return 'Course: ' . $topic;
        }

        return 'Curso: ' . $topic;
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID'),
            'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
            'fullname' => new external_value(PARAM_TEXT, 'Course fullname'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
        ]);
    }
}
