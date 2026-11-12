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

namespace local_coursegen\local\streaming;

/**
 * Which failures of the creation of a course the page is told to retry.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\streaming\finish_stream
 */
final class finish_stream_test extends \basic_testcase {
    /**
     * A service that cannot be reached, or that answers with an error that passes, is worth retrying.
     */
    public function test_failures_that_waiting_mends_are_retryable(): void {
        $this->assertTrue(finish_stream::is_retryable(new \moodle_exception('curlerror', 'aiprovider_datacurso', '', 28)));
        $this->assertTrue(finish_stream::is_retryable(new \moodle_exception('emptyresponse', 'aiprovider_datacurso')));
        $this->assertTrue(finish_stream::is_retryable(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 503)));
        $this->assertTrue(finish_stream::is_retryable(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 429)));
    }

    /**
     * A refusal, a missing license or any error that is not of the service is not mended by waiting.
     */
    public function test_failures_that_waiting_does_not_mend_are_not_retryable(): void {
        $this->assertFalse(finish_stream::is_retryable(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 404)));
        $this->assertFalse(finish_stream::is_retryable(new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso')));
        $this->assertFalse(finish_stream::is_retryable(new \RuntimeException('anything else')));
    }
}
