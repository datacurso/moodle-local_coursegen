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

use core\context\system;
use core\context\user;
use core\exception\require_login_exception;
use core\exception\required_capability_exception;
use local_coursegen\external\courseai_filepicker_init;

/**
 * Tests for the syllabus upload filepicker initialisation web service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\courseai_filepicker_init
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\external\courseai_filepicker_init::class)]
final class courseai_filepicker_init_test extends \advanced_testcase {
    /**
     * A logged-out caller is rejected by the context validation.
     */
    public function test_not_logged_in_user_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser(null);

        $this->expectException(require_login_exception::class);
        courseai_filepicker_init::execute();
    }

    /**
     * A user holding moodle/course:create but not the plugin capability is rejected.
     */
    public function test_user_without_createcoursewithai_is_rejected(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, system::instance());
        role_assign($roleid, $user->id, system::instance());
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        courseai_filepicker_init::execute();
    }

    /**
     * A course creator receives a fresh draft area and the syllabus filepicker options.
     */
    public function test_course_creator_receives_filepicker_options(): void {
        global $USER;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('moodle/course:create', CAP_ALLOW, $roleid, system::instance());
        assign_capability('local/coursegen:createcoursewithai', CAP_ALLOW, $roleid, system::instance());
        role_assign($roleid, $user->id, system::instance());
        $this->setUser($user);

        $result = courseai_filepicker_init::execute();

        $this->assertStringStartsWith('local_coursegen_courseai_syllabus_', $result['clientid']);
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
        // Only syllabus document types are accepted.
        $this->assertEqualsCanonicalizing(['.pdf', '.docx', '.txt'], $options['accepted_types']);
        $this->assertSame(FILE_INTERNAL, $options['return_types']);
        $this->assertArrayHasKey('repositories', $options);

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

        $first = courseai_filepicker_init::execute();
        $second = courseai_filepicker_init::execute();

        $this->assertNotSame($first['clientid'], $second['clientid']);
        $this->assertNotSame($first['draftitemid'], $second['draftitemid']);
    }
}
