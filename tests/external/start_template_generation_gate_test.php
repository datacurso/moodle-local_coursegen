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

namespace local_coursegen\external;

use local_coursegen\template_test_helper;
use local_coursegen\local\template\template_service;

/**
 * Starting a generation from a template while the AI service cannot run it yet.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_template_generation
 */
final class start_template_generation_gate_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service used to save the template of the tests. */
    private template_service $service;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    public function test_the_start_is_refused_with_a_clear_message_and_creates_no_session(): void {
        global $DB;
        [$course] = $this->make_course();
        $templateid = $this->save_items($course, []);

        try {
            start_template_generation::execute($templateid, 'Make a course');
            $this->fail('The start must be refused while the gate is closed.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templategenerationrebuilding', $exception->errorcode);
        }

        $sessions = $DB->count_records('local_coursegen_course_sessions');
        $this->assertSame(0, $sessions);
    }

    public function test_the_parameters_no_longer_ask_for_space_files(): void {
        $names = array_keys(start_template_generation::execute_parameters()->keys);

        $this->assertSame(['templateid', 'prompt', 'draftitemid'], $names);
    }
}
