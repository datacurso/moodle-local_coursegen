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

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\template_activities_guard;

/**
 * A course from a template is created with every activity the AI wrote, or not at all, saying which ones and why.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_activities_guard
 */
final class template_activities_guard_test extends \advanced_testcase {
    /**
     * A session of the current user.
     *
     * @param int $courseid
     * @return course_session
     */
    private function session(int $courseid): course_session {
        global $USER;
        $session = new course_session(0);
        $session->set('userid', (int) $USER->id);
        $session->set('session_id', 'thread-1');
        $session->set('courseid', $courseid);
        $session->set('status', course_session::STATUS_CREATED);
        $session->create();
        return $session;
    }

    /**
     * Nothing happens when every activity was created.
     */
    public function test_nothing_happens_when_every_activity_was_created(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $course->id);

        template_activities_guard::ensure_all_created($session, (int) $course->id, []);

        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertSame(course_session::STATUS_CREATED, (int) (new course_session($session->get('id')))->get('status'));
    }

    /**
     * Activities whose files could not be fetched stop the creation: the course is removed, the session failed, and the
     * message names them and says to generate the course again.
     */
    public function test_activities_whose_files_could_not_be_fetched_stop_the_creation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->session((int) $course->id);
        $errors = [];
        foreach (range(1, 7) as $number) {
            $errors[] = ['title' => 'Video interactivo · Lección ' . $number, 'cause' => 'download'];
        }

        try {
            template_activities_guard::ensure_all_created($session, (int) $course->id, $errors);
            $this->fail('A missing activity must stop the creation.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_activities_not_created', $exception->errorcode);
            $this->assertStringContainsString('7', $exception->getMessage());
            $this->assertStringContainsString('«Video interactivo · Lección 1»', $exception->getMessage());
            $this->assertStringNotContainsString('Lección 6»', $exception->getMessage());
            $this->assertStringContainsString(
                get_string('template_activities_not_created_more', 'local_coursegen', 2),
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                get_string('template_activities_not_created_download', 'local_coursegen'),
                $exception->getMessage()
            );
        }
        $this->assertDebuggingCalled();
        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
        $this->assertSame(course_session::STATUS_FAILED, (int) (new course_session($session->get('id')))->get('status'));
    }

    /**
     * A failure that is not a missing file says so without asking to generate again.
     */
    public function test_other_failures_give_the_other_reason(): void {
        $details = template_activities_guard::details([
            ['title' => 'Quiz', 'cause' => 'other'],
            ['title' => 'Video', 'cause' => 'download'],
        ]);

        $this->assertSame(2, $details->count);
        $this->assertSame('«Quiz», «Video»', $details->names);
        $this->assertSame(get_string('template_activities_not_created_other', 'local_coursegen'), $details->reason);
    }
}
