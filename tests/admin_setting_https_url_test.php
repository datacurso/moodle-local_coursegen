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

use local_coursegen\admin\setting_https_url;

/**
 * Validation tests for the HTTPS-enforcing service URL admin setting.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\admin\setting_https_url
 */
final class admin_setting_https_url_test extends \advanced_testcase {
    /**
     * Build the setting under test.
     *
     * @return setting_https_url
     */
    private function make_setting(): setting_https_url {
        return new setting_https_url(
            'local_coursegen/datacurso_service_url',
            'Service URL',
            '',
            '',
            PARAM_URL
        );
    }

    /**
     * An empty value is accepted (the override is optional).
     */
    public function test_empty_value_is_valid(): void {
        $this->resetAfterTest();
        $this->assertTrue($this->make_setting()->validate(''));
    }

    /**
     * An HTTPS URL is accepted.
     */
    public function test_https_url_is_valid(): void {
        $this->resetAfterTest();
        $this->assertTrue($this->make_setting()->validate('https://service.datacurso.com/api'));
    }

    /**
     * A plain HTTP URL to a remote host is rejected regardless of debug mode.
     */
    public function test_http_url_is_rejected(): void {
        global $CFG;

        $this->resetAfterTest();
        $setting = $this->make_setting();

        $CFG->debugdeveloper = false;
        $this->assertIsString($setting->validate('http://service.datacurso.com/api'));

        $CFG->debugdeveloper = true;
        $this->assertIsString($setting->validate('http://service.datacurso.com/api'));
    }

    /**
     * HTTP localhost URLs are accepted only while developer debugging is enabled.
     */
    public function test_http_localhost_allowed_only_with_debugdeveloper(): void {
        global $CFG;

        $this->resetAfterTest();
        $setting = $this->make_setting();

        $CFG->debugdeveloper = true;
        $this->assertTrue($setting->validate('http://localhost'));
        $this->assertTrue($setting->validate('http://localhost:8000/api/v1'));
        $this->assertTrue($setting->validate('http://127.0.0.1:8080/service'));

        $CFG->debugdeveloper = false;
        $this->assertIsString($setting->validate('http://localhost'));
        $this->assertIsString($setting->validate('http://127.0.0.1:8080/service'));
    }

    /**
     * A host merely starting with localhost must not slip through the exception.
     */
    public function test_localhost_lookalike_host_is_rejected(): void {
        global $CFG;

        $this->resetAfterTest();
        $setting = $this->make_setting();

        $CFG->debugdeveloper = true;
        $this->assertIsString($setting->validate('http://localhost.evil.com/api'));
    }
}
