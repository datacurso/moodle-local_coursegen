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

/**
 * Tests for the course_deleted observer cleanup.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\observer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\observer::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a course removes its coursegen rows and syllabus files, keeping other courses intact.
     */
    public function test_course_deleted_removes_plugin_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        /** @var \local_coursegen_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('local_coursegen');
        $user = $generator->create_user();
        $course = $generator->create_course();
        $othercourse = $generator->create_course();

        $session = $plugingenerator->create_course_session(['courseid' => $course->id, 'userid' => $user->id]);
        $othersession = $plugingenerator->create_course_session(['courseid' => $othercourse->id, 'userid' => $user->id]);
        $sessionid = (int)$session->get('id');
        $othersessionid = (int)$othersession->get('id');
        $plugingenerator->create_module_job(['courseid' => $course->id, 'userid' => $user->id]);
        $plugingenerator->create_module_job(['courseid' => $othercourse->id, 'userid' => $user->id]);
        $plugingenerator->create_course_context(['courseid' => $course->id, 'usermodified' => $user->id]);
        $plugingenerator->create_course_context(['courseid' => $othercourse->id, 'usermodified' => $user->id]);
        $plugingenerator->create_syllabus_file($session);
        $plugingenerator->create_syllabus_file($othersession);

        delete_course($course->id, false);

        $this->assertSame(0, $DB->count_records('local_coursegen_course_sessions', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('local_coursegen_module_jobs', ['courseid' => $course->id]));
        $this->assertSame(0, $DB->count_records('local_coursegen_course_context', ['courseid' => $course->id]));

        $fs = get_file_storage();
        $syscontextid = system::instance()->id;
        $this->assertEmpty($fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $sessionid, 'id', false));

        // The other course keeps its rows and files.
        $this->assertSame(1, $DB->count_records('local_coursegen_course_sessions', ['courseid' => $othercourse->id]));
        $this->assertSame(1, $DB->count_records('local_coursegen_module_jobs', ['courseid' => $othercourse->id]));
        $this->assertSame(1, $DB->count_records('local_coursegen_course_context', ['courseid' => $othercourse->id]));
        $this->assertNotEmpty(
            $fs->get_area_files($syscontextid, 'local_coursegen', 'syllabus', $othersessionid, 'id', false)
        );
    }
}
