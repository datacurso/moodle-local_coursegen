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

use local_coursegen\local\service\course_creation_guard;

/**
 * Unit tests for course_creation_guard::ensure_created().
 *
 * create_course_service::create_course() already reports failure correctly
 * (it is caught, session marked failed, {success: false, courseid: 0,
 * message: <real reason>} returned - create_course.php's own webservice
 * response depends on exactly that shape). This guard is what a caller that
 * must not silently carry on with a synthetic courseid of 0 runs against
 * that result: previously, finish_template_generation.php read only
 * courseid, never success, so a real creation failure reported the whole
 * run as "completed" with no course and no error message.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_creation_guard
 */
final class course_creation_guard_test extends \basic_testcase {
    /**
     * A successful result returns silently - nothing is thrown.
     */
    public function test_successful_result_does_not_throw(): void {
        course_creation_guard::ensure_created(['success' => true, 'courseid' => 42]);
        $this->assertTrue(true);
    }

    /**
     * A failed result throws, carrying the real failure reason - never a
     * generic message that hides what actually went wrong.
     */
    public function test_failed_result_throws_with_the_real_reason(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Category id is invalid');

        course_creation_guard::ensure_created([
            'success' => false,
            'courseid' => 0,
            'message' => 'Category id is invalid',
        ]);
    }

    /**
     * A failed result with no message key still throws, rather than
     * crashing on the missing key or silently returning.
     */
    public function test_failed_result_with_no_message_still_throws(): void {
        $this->expectException(\moodle_exception::class);

        course_creation_guard::ensure_created(['success' => false, 'courseid' => 0]);
    }

    /**
     * A result with no success key at all is treated as a failure, the same
     * as success: false - a caller can never read a missing key as "it must
     * have worked".
     */
    public function test_result_with_no_success_key_is_treated_as_failure(): void {
        $this->expectException(\moodle_exception::class);

        course_creation_guard::ensure_created(['courseid' => 0, 'message' => 'boom']);
    }

    /**
     * success: 0 (falsy, not strictly false) is treated the same as
     * success: false.
     */
    public function test_falsy_success_value_is_treated_as_failure(): void {
        $this->expectException(\moodle_exception::class);

        course_creation_guard::ensure_created(['success' => 0, 'message' => 'boom']);
    }
}
