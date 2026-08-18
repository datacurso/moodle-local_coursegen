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

namespace local_coursegen\local;

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * Factory for Datacurso AI API clients.
 *
 * Centralizes client construction so PHPUnit tests can inject a test double
 * instead of a real HTTP client. Production behavior is unchanged: outside
 * of PHPUnit runs this factory always builds a real client.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client_factory {
    /** @var object|null Test double injected from PHPUnit, if any. */
    private static ?object $testclient = null;

    /** @var array|null Last base URLs passed to ai_course_api() (recorded in PHPUnit runs only). */
    private static ?array $lasturls = null;

    /**
     * Build (or return the injected test double for) the AI course API client.
     *
     * @param string|null $baseurl Optional standard-region base URL override.
     * @param string|null $baseurleu Optional EU-region base URL override.
     * @return ai_course_api
     */
    public static function ai_course_api(?string $baseurl, ?string $baseurleu): ai_course_api {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$lasturls = ['baseurl' => $baseurl, 'baseurleu' => $baseurleu];
            if (self::$testclient !== null) {
                return self::$testclient;
            }
        }

        return new ai_course_api(null, $baseurl, $baseurleu);
    }

    /**
     * Inject a test double to be returned by ai_course_api(). PHPUnit only.
     *
     * Pass null to remove the injected double and restore real construction.
     *
     * @param object|null $client Test double (mock of ai_course_api) or null to reset.
     * @return void
     * @throws \coding_exception When called outside a PHPUnit run.
     */
    public static function set_test_client(?object $client): void {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new \coding_exception('api_client_factory::set_test_client() can only be used in PHPUnit tests.');
        }

        self::$testclient = $client;
        if ($client === null) {
            self::$lasturls = null;
        }
    }

    /**
     * Return the base URLs received by the last ai_course_api() call. PHPUnit only.
     *
     * @return array|null Array with 'baseurl' and 'baseurleu' keys, or null when no call was made.
     * @throws \coding_exception When called outside a PHPUnit run.
     */
    public static function get_last_urls(): ?array {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            throw new \coding_exception('api_client_factory::get_last_urls() can only be used in PHPUnit tests.');
        }

        return self::$lasturls;
    }
}
