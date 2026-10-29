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
use local_coursegen\local\service\template_run_history;

/**
 * Unit tests for template_run_history.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_run_history
 */
final class template_run_history_test extends \advanced_testcase {
    /**
     * Each answer to the review is kept in order, and recording one keeps what the session already held.
     */
    public function test_the_answers_to_the_review_are_kept_in_order(): void {
        $this->resetAfterTest(true);
        $sessionid = $this->create_session(['payload' => ['lang' => 'es']]);

        template_run_history::record_review($sessionid, 'replan_activity', 'Shorter options');
        template_run_history::record_review($sessionid, 'accept', '');

        $session = new course_session($sessionid);
        $history = template_run_history::for_page($session);
        $this->assertCount(2, $history['reviews']);
        $this->assertFalse($history['reviews'][0]['approved']);
        $this->assertSame('Shorter options', $history['reviews'][0]['instruction']);
        $this->assertTrue($history['reviews'][1]['approved']);
        $this->assertSame('es', json_decode($session->get('coursedata'), true)['payload']['lang']);
    }

    /**
     * The built course is kept with the activities the AI wrote, section by section, and nothing else of the course.
     */
    public function test_the_built_course_keeps_the_written_activities_by_section(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $written = $generator->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'Written page']);
        $generator->create_module('label', ['course' => $course->id, 'section' => 1]);
        $sessionid = $this->create_session([]);

        template_run_history::record_completed($sessionid, (int) $course->id, [-1 => (int) $written->cmid]);

        $history = template_run_history::for_page(new course_session($sessionid));
        $this->assertGreaterThan(0, $history['completed']);
        $this->assertCount(1, $history['sections']);
        $this->assertSame('Written page', $history['sections'][0]['activities'][0]['title']);
        $this->assertSame(get_string('modulename', 'page'), $history['sections'][0]['activities'][0]['type']);
    }

    /**
     * Recording keeps the status another step gave the session meanwhile.
     */
    public function test_recording_keeps_the_status_set_meanwhile(): void {
        $this->resetAfterTest(true);
        $sessionid = $this->create_session([]);
        $stale = new course_session($sessionid);
        $fresh = new course_session($sessionid);
        $fresh->set('status', course_session::STATUS_CREATED);
        $fresh->update();

        template_run_history::record_review((int) $stale->get('id'), 'accept', '');

        $this->assertSame(course_session::STATUS_CREATED, (int) (new course_session($sessionid))->get('status'));
    }

    /**
     * A course built before its steps were kept says it was approved and built, and lists no activities.
     */
    public function test_a_course_built_before_the_steps_were_kept_shows_what_is_known(): void {
        $this->resetAfterTest(true);
        $sessionid = $this->create_session([], course_session::STATUS_CREATED);

        $history = template_run_history::for_page(new course_session($sessionid));

        $this->assertGreaterThan(0, $history['completed']);
        $this->assertCount(1, $history['reviews']);
        $this->assertTrue($history['reviews'][0]['approved']);
        $this->assertSame([], $history['sections']);
    }

    /**
     * Create a session of a generation from a template.
     *
     * @param array $coursedata
     * @param int $status
     * @return int The session id.
     */
    private function create_session(array $coursedata, int $status = course_session::STATUS_PENDING): int {
        $user = $this->getDataGenerator()->create_user();
        $session = new course_session(0, (object) [
            'userid' => (int) $user->id,
            'session_id' => 'thread-' . random_string(8),
            'status' => $status,
            'templateid' => 1,
            'coursedata' => json_encode($coursedata),
        ]);
        $session->create();
        return (int) $session->get('id');
    }
}
