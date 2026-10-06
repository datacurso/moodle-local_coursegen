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

use local_coursegen\local\service\template_generation_gate;

/**
 * Starting a generation from a template while the AI service cannot run it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_generation_gate
 */
final class template_generation_gate_test extends \advanced_testcase {
    public function test_the_gate_is_closed_until_the_service_can_run_the_generation(): void {
        $this->assertFalse(template_generation_gate::OPEN);
    }

    public function test_a_closed_gate_refuses_with_its_own_message(): void {
        $this->expectException(\moodle_exception::class);
        $message = get_string('templategenerationrebuilding', 'local_coursegen');
        $this->expectExceptionMessage($message);

        template_generation_gate::assert_open();
    }
}
