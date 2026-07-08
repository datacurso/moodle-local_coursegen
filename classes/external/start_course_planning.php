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

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\service\course_planning_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External function to start an AI course planning session.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_course_planning extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_TEXT, 'Course description prompt'),
            'lang' => new external_value(PARAM_TEXT, 'Language code (es, en, etc.)', VALUE_DEFAULT, 'es'),
            'withimages' => new external_value(PARAM_BOOL, 'Include image suggestions', VALUE_DEFAULT, false),
            'systeminstructionid' => new external_value(
                PARAM_INT,
                'System instruction ID (optional)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Start courseai session and create course planning thread.
     *
     * @param string $prompt Course description.
     * @param string $lang Language code.
     * @param bool $withimages Include image suggestions.
     * @param int $systeminstructionid System instruction ID.
     * @return array
     */
    public static function execute(
        string $prompt,
        string $lang = 'es',
        bool $withimages = false,
        int $systeminstructionid = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'prompt' => $prompt,
            'lang' => $lang,
            'withimages' => $withimages,
            'systeminstructionid' => $systeminstructionid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        try {
            return course_planning_service::start_course_planning(
                $params['prompt'],
                $params['lang'],
                (bool)$params['withimages'],
                (int)$params['systeminstructionid'],
                (int)$USER->id
            );
        } catch (\Exception $e) {
            return [
                'success' => false,
                'sessionid' => 0,
                'threadid' => '',
                'streamingurl' => '',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'sessionid' => new external_value(PARAM_INT, 'Moodle session ID'),
            'threadid' => new external_value(PARAM_TEXT, 'Datacurso API thread ID'),
            'streamingurl' => new external_value(PARAM_TEXT, 'Datacurso API stream URL'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }
}
