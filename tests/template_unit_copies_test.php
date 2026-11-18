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

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * Unit tests for template_unit_copies.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_unit_copies
 */
final class template_unit_copies_test extends \advanced_testcase {
    /**
     * A session with the sections that were sent.
     *
     * @return course_session
     */
    private function session(): course_session {
        global $USER;
        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-copies',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['payload' => ['sections_info' => [
                ['section' => 0, 'name' => 'General'],
                ['section' => 1, 'name' => 'Unidad'],
                ['section' => 2, 'name' => 'Cierre'],
            ]]]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();
        return $session;
    }

    /**
     * Sections that hold copies of a template section take the place of the ones that were sent.
     */
    public function test_the_answered_sections_with_copies_are_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->session();
        $answered = [
            ['section' => 0, 'name' => 'General', 'source_section' => 0],
            ['section' => 1, 'name' => 'Algoritmos', 'source_section' => 1],
            ['section' => 2, 'name' => 'Control', 'source_section' => 1, 'cloned' => true],
            ['section' => 3, 'name' => 'Cierre', 'source_section' => 2],
        ];

        $this->assertTrue(template_unit_copies::adopt($session, $answered));

        $stored = json_decode((string) (new course_session($session->get('id')))->get('coursedata'), true);
        $this->assertSame($answered, $stored['payload']['sections_info']);
        $map = template_section_map::from_sections_info($stored['payload']['sections_info']);
        $this->assertSame(3, $map->target_of_source(2));
        $this->assertTrue($map->is_cloned(2));
    }

    /**
     * Without copies the session keeps the sections it sent.
     */
    public function test_sections_without_copies_leave_the_session_alone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->session();

        $this->assertFalse(template_unit_copies::adopt($session, [['section' => 0, 'name' => 'Otra']]));

        $stored = json_decode((string) (new course_session($session->get('id')))->get('coursedata'), true);
        $this->assertSame('General', $stored['payload']['sections_info'][0]['name']);
        $this->assertCount(3, $stored['payload']['sections_info']);
    }

    /**
     * A copy follows the order of the first copy; what repeats nothing stays after, in its own order.
     */
    public function test_a_copy_follows_the_order_of_the_first_one(): void {
        $positions = array_flip([10, 11, 12, 13]);
        $original = [21 => 10, 22 => 11, 23 => 12, 24 => 13];

        $ordered = template_unit_copies::ordered_like([21, 23, 30, 24, 22], $positions, $original);

        $this->assertSame([21, 22, 23, 24, 30], $ordered);
    }
}
