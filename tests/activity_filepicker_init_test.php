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

use core\context\user;
use core\exception\require_login_exception;
use core\exception\required_capability_exception;
use local_coursegen\external\activity_filepicker_init;

/**
 * Tests for the activity upload filepicker initialisation web service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_filepicker_init
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\activity_filepicker_init::class)]
final class activity_filepicker_init_test extends \advanced_testcase {
    /**
     * A logged-out caller is rejected by the context validation.
     */
    public function test_not_logged_in_user_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser(null);

        $this->expectException(require_login_exception::class);
        activity_filepicker_init::execute($course->id);
    }

    /**
     * An enrolled student lacks the activity generation capabilities.
     */
    public function test_student_without_capability_is_rejected(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        activity_filepicker_init::execute($course->id);
    }

    /**
     * A teacher receives a fresh draft area and the filepicker options accepting any file type.
     */
    public function test_teacher_receives_filepicker_options(): void {
        global $USER;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = activity_filepicker_init::execute($course->id);

        $this->assertStringStartsWith('local_coursegen_activity_upload_', $result['clientid']);
        $this->assertGreaterThan(0, $result['draftitemid']);

        // The draft area is new and empty.
        $files = get_file_storage()->get_area_files(
            user::instance($USER->id)->id,
            'user',
            'draft',
            $result['draftitemid'],
            'id',
            false
        );
        $this->assertEmpty($files);

        $options = json_decode($result['options'], true);
        $this->assertIsArray($options);
        // Any file type is accepted: the '*' wildcard resolves to no extension restriction.
        $this->assertSame([], $options['accepted_types']);
        $this->assertSame(FILE_INTERNAL, $options['return_types']);
        $this->assertArrayHasKey('repositories', $options);
        $this->assertArrayHasKey('userprefs', $options);

        $templates = json_decode($result['templates'], true);
        $this->assertIsArray($templates);
        $this->assertNotEmpty($templates);
    }

    /**
     * Every initialisation hands out its own client id and draft area.
     */
    public function test_each_initialisation_is_independent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $first = activity_filepicker_init::execute($course->id);
        $second = activity_filepicker_init::execute($course->id);

        $this->assertNotSame($first['clientid'], $second['clientid']);
        $this->assertNotSame($first['draftitemid'], $second['draftitemid']);
    }
}
