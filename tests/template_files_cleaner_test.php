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

/**
 * The cleanup of the files of a template run.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_files_cleaner
 */
final class template_files_cleaner_test extends \advanced_testcase {
    /**
     * The files of the run are deleted by thread.
     */
    public function test_the_files_of_the_run_are_deleted(): void {
        $api = $this->createMock(template_ai_api_service::class);
        $api->expects($this->once())->method('delete_files')->with('t-1');

        $this->assertTrue(template_files_cleaner::discard('t-1', $api));
    }

    /**
     * A service that fails does not break the course or the cancel.
     */
    public function test_a_failure_of_the_service_is_not_raised(): void {
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('delete_files')->willThrowException(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 500));

        $this->assertFalse(template_files_cleaner::discard('t-1', $api));
        $this->assertDebuggingCalled();
    }
}
