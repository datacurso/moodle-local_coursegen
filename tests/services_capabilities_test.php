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

/**
 * Keeps the web service metadata in db/services.php honest.
 *
 * The 'capabilities' entry of each function is documentation for token
 * administrators: it must only name capabilities that exist, and it must
 * name exactly the ones the external class enforces with require_capability().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class services_capabilities_test extends \advanced_testcase {
    /**
     * Load the function declarations from db/services.php.
     *
     * @return array
     */
    private static function load_functions(): array {
        $functions = [];
        require(__DIR__ . '/../db/services.php');

        return $functions;
    }

    /**
     * Every declared capability exists in db/access.php or in core.
     */
    public function test_declared_capabilities_exist(): void {
        $functions = self::load_functions();
        $this->assertCount(13, $functions);

        foreach ($functions as $name => $function) {
            $this->assertNotEmpty($function['capabilities'] ?? '', "$name must declare its capabilities");
            foreach (explode(',', $function['capabilities']) as $capability) {
                $capability = trim($capability);
                $this->assertNotNull(get_capability_info($capability), "$name declares the unknown capability $capability");
            }
        }
    }

    /**
     * The declared capabilities are the ones the external class checks.
     *
     * @dataProvider enforced_capabilities_provider
     * @param string $function Web service function name.
     * @param string $expected Comma-separated capabilities enforced by the class.
     */
    public function test_declared_capabilities_match_enforced_ones(string $function, string $expected): void {
        $functions = self::load_functions();

        $this->assertArrayHasKey($function, $functions);
        $this->assertSame($expected, $functions[$function]['capabilities']);
    }

    /**
     * Capabilities enforced by each external class (see its require_capability calls).
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function enforced_capabilities_provider(): array {
        $activity = 'moodle/course:manageactivities,local/coursegen:createactivitywithai';
        $course = 'moodle/course:create,local/coursegen:createcoursewithai';

        return [
            'create_mod' => ['local_coursegen_create_mod', $activity],
            'create_mod_stream' => ['local_coursegen_create_mod_stream', $activity],
            'activity_feedback' => ['local_coursegen_activity_feedback', $activity],
            'activity_filepicker_init' => ['local_coursegen_activity_filepicker_init', $activity],
            'activity_file_upload' => ['local_coursegen_activity_file_upload', $activity],
            'manage_image_generation' => [
                'local_coursegen_manage_image_generation', 'local/coursegen:manageimagegeneration',
            ],
            'create_course' => ['local_coursegen_create_course', $course],
            'get_course_settings' => ['local_coursegen_get_course_settings', $course],
            'course_planning_feedback' => ['local_coursegen_course_planning_feedback', $course],
            'start_course_planning' => ['local_coursegen_start_course_planning', $course],
            'courseai_syllabus_upload' => ['local_coursegen_courseai_syllabus_upload', $course],
            'courseai_filepicker_init' => ['local_coursegen_courseai_filepicker_init', $course],
            'get_course_session_state' => ['local_coursegen_get_course_session_state', $course],
        ];
    }
}
