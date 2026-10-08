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

namespace local_coursegen\admin;

/**
 * Builds and sets up the plugin admin external pages.
 *
 * On Moodle Workplace the pages are \tool_wp\admin_externalpage instances, whose
 * access is decided by a callback (so tenant administrators without site
 * configuration rights reach them). On a site without tool_wp (plain Moodle LMS,
 * such as the CI environment) a core \admin_externalpage gated by the plugin
 * capability at system level is used instead.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_page {
    /** @var bool Test-only flag simulating a site without tool_wp. */
    private static bool $simulateunavailable = false;

    /**
     * Whether this site provides the Workplace admin pages (tool_wp).
     *
     * @return bool
     */
    public static function is_workplace_available(): bool {
        return !self::$simulateunavailable && class_exists('\tool_wp\admin_externalpage');
    }

    /**
     * Builds an admin external page.
     *
     * @param string $name Unique page name (admin tree section).
     * @param string $visiblename Localised page name.
     * @param string $url Page URL.
     * @param string $capability Plugin capability required at system level.
     * @param callable|null $accesscheck Workplace access check, null to check $capability at system level.
     * @param bool $hidden Whether the page is hidden from the admin tree.
     * @return \admin_externalpage
     */
    public static function create(
        string $name,
        string $visiblename,
        string $url,
        string $capability,
        ?callable $accesscheck = null,
        bool $hidden = false
    ): \admin_externalpage {
        if (!self::is_workplace_available()) {
            return new \admin_externalpage($name, $visiblename, $url, $capability, $hidden);
        }
        $accesscheck = $accesscheck ?? static fn(): bool => has_capability($capability, \context_system::instance());
        return new \tool_wp\admin_externalpage($name, $visiblename, $url, $accesscheck, $hidden);
    }

    /**
     * Sets up a plugin page through its admin tree entry (login and access checks included).
     *
     * @param string $section Admin tree section of the page.
     * @param array $options Page options, such as 'pagelayout'.
     * @return void
     */
    public static function setup(string $section, array $options = []): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        if (self::is_workplace_available()) {
            \tool_wp\admin_externalpage::setup_page($section, '', null, '', $options);
            return;
        }
        admin_externalpage_setup($section, '', null, '', $options);
    }

    /**
     * Test-only: makes the class behave as on a site without tool_wp.
     *
     * Undo it with {@see self::reset_for_testing()}.
     *
     * @return void
     * @throws \coding_exception When called outside PHPUnit.
     */
    public static function simulate_workplace_unavailable_for_testing(): void {
        self::assert_testing();
        self::$simulateunavailable = true;
    }

    /**
     * Test-only: restores the real tool_wp detection.
     *
     * @return void
     * @throws \coding_exception When called outside PHPUnit.
     */
    public static function reset_for_testing(): void {
        self::assert_testing();
        self::$simulateunavailable = false;
    }

    /**
     * Throws unless running PHPUnit tests.
     *
     * @return void
     * @throws \coding_exception
     */
    private static function assert_testing(): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('This method may only be used by PHPUnit tests.');
        }
    }
}
