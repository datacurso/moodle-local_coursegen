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

use local_coursegen\external\create_mod;
use local_coursegen\local\service\module_job_service;
use local_coursegen\tests\api_testcase;

/**
 * Permission tests for creating the H5P activity through the generator.
 *
 * The AI service and the download HTTP client are both mocked, so no network
 * request is ever performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\create_mod
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\create_mod::class)]
final class create_mod_permissions_test extends api_testcase {
    /**
     * Give the front page real section rows.
     */
    protected function setUp(): void {
        parent::setUp();

        // The module edit form resolves section info through the global $COURSE,
        // which is the site course here. Give the front page the section rows a
        // real site has.
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        course_create_sections_if_missing(get_site(), [0, 1]);
    }

    /**
     * MDL-INT-007: A user with course management permissions can create the H5P
     * activity through the generator.
     */
    public function test_user_with_manageactivities_can_create_activity(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        module_job_service::create_job($course->id, $teacher->id, 'job-ok', 0, null, null, 1, null, 'completed');
        $this->inject_api_service(['get_activity_result' => $this->h5p_activity_result()]);
        $this->inject_download_client();

        $result = create_mod::execute($course->id, 1, 'job-ok');
        // One pre-existing developer notice: execute_parameters() declares
        // top-level VALUE_OPTIONAL values instead of VALUE_DEFAULT.
        $this->assertDebuggingCalledCount(1);

        $this->assertTrue($result['ok'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $this->assertSame('h5pactivity', $result['data']['modname']);
        // A clean creation reports no warning (the key is always present on success).
        $this->assertSame([], $result['warnings']);

        $records = $DB->get_records('h5pactivity', ['course' => $course->id]);
        $this->assertCount(1, $records);
        $this->assertSame('AI generated H5P', reset($records)->name);
    }

    /**
     * MDL-INT-007: A user without activity management permissions receives a
     * clear permission error and nothing is created.
     */
    public function test_user_without_manageactivities_cannot_create_activity(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        module_job_service::create_job($course->id, $student->id, 'job-denied', 0, null, null, 1, null, 'completed');
        $this->inject_api_service(['get_activity_result' => $this->h5p_activity_result()]);
        $this->inject_download_client();

        $result = create_mod::execute($course->id, 1, 'job-denied');
        // One pre-existing developer notice from execute_parameters() plus the
        // debugging call from the permission failure handler.
        $this->assertDebuggingCalledCount(2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            get_string('nopermissions', 'error', get_capability_string('moodle/course:manageactivities')),
            $result['message']
        );

        // No residue: nothing was created in the course.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('h5pactivity', ['course' => $course->id]));
    }
}
