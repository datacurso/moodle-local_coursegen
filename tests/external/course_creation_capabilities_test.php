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

use local_coursegen\external\course_planning_feedback;
use local_coursegen\external\courseai_filepicker_init;
use local_coursegen\external\courseai_syllabus_upload;
use local_coursegen\external\create_course;
use local_coursegen\external\create_mod_stream;
use local_coursegen\external\finish_template_generation;
use local_coursegen\external\get_course_session_state;
use local_coursegen\external\get_course_settings;
use local_coursegen\external\get_template_course_settings;
use local_coursegen\external\get_template_reference_slots;
use local_coursegen\external\get_template_structure;
use local_coursegen\external\manage_image_generation;
use local_coursegen\external\start_course_planning;
use local_coursegen\external\start_template_generation;
use local_coursegen\external\template_review_feedback;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../capability_user_trait.php');

/**
 * The web services that create a course with AI ask for the capability of their mode.
 *
 * Holding every other capability of the plugin (and the core capabilities the function also asks
 * for) must not be enough. The allowed side cannot be shown here without calling the AI service,
 * so it is covered by reading the functions and by the review steps.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_course_planning
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\create_course
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\get_template_course_settings
 * @covers     \local_coursegen\external\get_template_structure
 * @covers     \local_coursegen\external\get_template_reference_slots
 * @covers     \local_coursegen\external\start_template_generation
 * @covers     \local_coursegen\external\template_review_feedback
 * @covers     \local_coursegen\external\finish_template_generation
 * @covers     \local_coursegen\external\courseai_filepicker_init
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 * @covers     \local_coursegen\external\get_course_session_state
 * @covers     \local_coursegen\external\manage_image_generation
 * @covers     \local_coursegen\external\create_mod_stream
 *
 * @runTestsInSeparateProcesses
 */
final class course_creation_capabilities_test extends \advanced_testcase {
    use capability_user_trait;

    /**
     * A user with everything else is refused a function whose own capability they lack.
     *
     * @dataProvider function_provider
     * @param string $class The external function class.
     * @param array $arguments Valid arguments for it.
     * @param string $capability The capability it asks for.
     * @param string[] $corecapabilities The core capabilities it also asks for.
     */
    public function test_a_function_refuses_a_user_who_lacks_its_own_capability(
        string $class,
        array $arguments,
        string $capability,
        array $corecapabilities
    ): void {
        $this->resetAfterTest();
        $this->login_without($capability, $corecapabilities);

        $this->expectException(\required_capability_exception::class);
        call_user_func_array([$class, 'execute'], $arguments);
    }

    /**
     * A user with no capability at all is refused every function.
     *
     * @dataProvider function_provider
     * @param string $class The external function class.
     * @param array $arguments Valid arguments for it.
     */
    public function test_a_function_refuses_a_user_with_no_capability(string $class, array $arguments): void {
        $this->resetAfterTest();
        $this->login_user_with([]);

        $this->expectException(\required_capability_exception::class);
        call_user_func_array([$class, 'execute'], $arguments);
    }

    /**
     * The functions, their own capability and the core capabilities they also ask for.
     *
     * @return array
     */
    public static function function_provider(): array {
        $createcourse = ['moodle/course:create'];
        $free = 'local/coursegen:createfreecoursewithai';
        $template = 'local/coursegen:createtemplatecoursewithai';
        $syllabus = 'local/coursegen:uploadcoursesyllabus';
        return [
            'plan' => [start_course_planning::class, ['A course about fractions'], $free, $createcourse],
            'feedback' => [
                course_planning_feedback::class, [1, ['action' => 'accept', 'target_ids' => []]], $free, [],
            ],
            'create' => [create_course::class, [1], $free, []],
            'settings' => [get_course_settings::class, [1], $free, []],
            'structure' => [get_template_structure::class, [1], $template, []],
            'referenceslots' => [get_template_reference_slots::class, [1], $template, []],
            'templatestart' => [start_template_generation::class, [1], $template, []],
            'templatefeedback' => [template_review_feedback::class, [1, 'accept'], $template, []],
            'templatesettings' => [get_template_course_settings::class, [1], $template, []],
            'templatefinish' => [finish_template_generation::class, [1], $template, []],
            'filepicker' => [courseai_filepicker_init::class, [], $syllabus, $createcourse],
            'syllabus' => [courseai_syllabus_upload::class, [1, 1], $syllabus, $createcourse],
            'images' => [
                manage_image_generation::class, [0, 0, 'disabled', []],
                'local/coursegen:editimagegenerationsettings', ['moodle/site:config'],
            ],
        ];
    }

    /**
     * The state of a session is open to either mode, so a user with neither mode is refused.
     */
    public function test_the_session_state_refuses_a_user_with_neither_mode(): void {
        $this->resetAfterTest();
        $others = $this->all_capabilities_except([
            'local/coursegen:createfreecoursewithai',
            'local/coursegen:createtemplatecoursewithai',
        ]);
        $this->login_user_with($others);

        $this->expectException(\required_capability_exception::class);
        get_course_session_state::execute(1);
    }

    /**
     * Planning with the images option needs the capability to generate course images.
     */
    public function test_planning_with_images_needs_the_images_capability(): void {
        $this->resetAfterTest();
        $this->login_without('local/coursegen:generatecourseimages', ['moodle/course:create']);

        $this->expectException(\required_capability_exception::class);
        start_course_planning::execute('A course about fractions', 'es', true);
    }

    /**
     * Starting a template generation with a syllabus needs the capability to upload one.
     */
    public function test_a_template_generation_with_a_syllabus_needs_the_syllabus_capability(): void {
        $this->resetAfterTest();
        $this->login_without('local/coursegen:uploadcoursesyllabus');

        $this->expectException(\required_capability_exception::class);
        start_template_generation::execute(1, '', 5);
    }

    /**
     * Starting an activity generation with images needs the capability to generate activity images.
     * The function reports a refusal in its answer instead of throwing.
     */
    public function test_an_activity_generation_with_images_needs_the_images_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $context = \context_course::instance($course->id);
        $roleid = $generator->create_role();
        assign_capability('local/coursegen:generateactivityimages', CAP_PROHIBIT, $roleid, $context->id, true);
        $generator->role_assign($roleid, $teacher->id, $context->id);
        $this->setUser($teacher);

        $result = create_mod_stream::execute((int) $course->id, 1, 'Create a page', 1);
        // The function reports the refusal with a debugging notice, and so does its parameter description.
        $this->resetDebugging();

        $this->assertFalse($result['ok']);
    }
}
