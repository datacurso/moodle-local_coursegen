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

use local_coursegen\local\service\license_failure;

/**
 * A refusal of the AI service because of the license is told plainly; any other error is not.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\license_failure
 */
final class license_failure_test extends \advanced_testcase {
    public function test_no_credits_says_so_and_what_to_do(): void {
        $error = new \moodle_exception('notenoughtokens', 'aiprovider_datacurso');
        $this->assertTrue(license_failure::is($error));
        $this->assertSame(get_string('courseai_license_no_credits', 'local_coursegen'), license_failure::message($error));
    }

    public function test_another_license_refusal_keeps_the_provider_words_as_plain_text(): void {
        $error = new \moodle_exception('license_not_allowed', 'aiprovider_datacurso');
        $message = license_failure::message($error);
        $this->assertNotEmpty($message);
        $this->assertStringNotContainsString('<a', $message);
    }

    public function test_any_other_error_is_not_a_license_refusal(): void {
        $this->assertNull(license_failure::message(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 500)));
        $this->assertNull(license_failure::message(new \moodle_exception('notenoughtokens', 'local_coursegen')));
        $this->assertNull(license_failure::message(new \RuntimeException('down')));
    }
}
