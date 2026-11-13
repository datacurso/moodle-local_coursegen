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
use local_coursegen\local\service\create_course_service;

/**
 * The optional start date of the review step reaches the course that is created.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_startdate_test extends \advanced_testcase {
    /**
     * Create a course from a bare session with the given overrides.
     *
     * @param array $overrides
     * @return array The result of create_course().
     */
    private function create(array $overrides): array {
        global $USER;

        $this->setAdminUser();
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-startdate',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => '{}',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();
        $resultdata = ['course_configuration' => ['fullname' => 'Dated', 'shortname' => 'dated']];
        return create_course_service::create_course($session, $resultdata, $overrides);
    }

    /**
     * A start date override is the start date of the new course.
     */
    public function test_the_start_date_override_is_the_course_start_date(): void {
        $this->resetAfterTest();
        $start = gmmktime(0, 0, 0, 3, 15, 2027);

        $result = $this->create(['startdate' => $start]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame($start, (int) get_course($result['courseid'])->startdate);
    }

    /**
     * Without the override the course starts as it did before: on Moodle's default.
     */
    public function test_without_the_override_the_default_start_date_stays(): void {
        $this->resetAfterTest();

        $result = $this->create([]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, (int) get_course($result['courseid'])->startdate);
    }
}
