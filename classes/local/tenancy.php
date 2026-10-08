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

/**
 * Thin wrapper around the Moodle Workplace tenancy APIs.
 *
 * This is the only class in the plugin that talks to tool_tenant directly, so
 * every other component resolves "which tenant am I in" through it.
 *
 * The plugin targets Moodle Workplace, where tenancy is provided by tool_tenant.
 * On a site without that plugin (plain Moodle LMS, such as the CI environment
 * used to run the test suite) there is a single implicit tenant, represented by
 * tenant id 0 ({@see self::NO_TENANT}), with no category and no name.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenancy {
    /** @var int Tenant id used when the site has no tenancy support. */
    public const NO_TENANT = 0;

    /** @var bool Test-only flag simulating a site without tool_tenant. */
    private static bool $simulateunavailable = false;

    /**
     * Whether this site provides tenancy through tool_tenant.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return !self::$simulateunavailable && class_exists('\tool_tenant\tenancy');
    }

    /**
     * Returns the tenant a user belongs to (the current user when omitted).
     *
     * For a user who can switch tenants this is the tenant they are currently in.
     *
     * @param int|null $userid User id, or null for the current user.
     * @return int The tenant id, or self::NO_TENANT when tenancy is not available.
     */
    public static function get_tenant_id(?int $userid = null): int {
        if (!self::is_available()) {
            return self::NO_TENANT;
        }
        return \tool_tenant\tenancy::get_tenant_id($userid);
    }

    /**
     * Returns the course category attached to a tenant, or null when it has none or does not exist.
     *
     * @param int $tenantid Tenant id.
     * @return int|null Null as well when tenancy is not available.
     */
    public static function get_tenant_categoryid(int $tenantid): ?int {
        if (!self::is_available()) {
            return null;
        }
        $tenants = \tool_tenant\tenancy::get_tenants();
        if (!isset($tenants[$tenantid]) || empty($tenants[$tenantid]->categoryid)) {
            return null;
        }
        return (int) $tenants[$tenantid]->categoryid;
    }

    /**
     * Returns the formatted name of a tenant, an empty string when it does not exist.
     *
     * @param int $tenantid Tenant id.
     * @return string|null Null when tenancy is not available (there is no tenant to name).
     */
    public static function get_tenant_name(int $tenantid): ?string {
        if (!self::is_available()) {
            return null;
        }
        $tenants = \tool_tenant\tenancy::get_tenants();
        if (!isset($tenants[$tenantid])) {
            return '';
        }
        return format_string($tenants[$tenantid]->name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Returns the Workplace default tenant, the one unallocated users belong to.
     *
     * @return int The tenant id, or self::NO_TENANT when tenancy is not available.
     */
    public static function get_default_tenant_id(): int {
        if (!self::is_available()) {
            return self::NO_TENANT;
        }
        return \tool_tenant\tenancy::get_default_tenant_id();
    }

    /**
     * Grants the plugin capabilities to the Workplace "Tenant administrator" role.
     *
     * Used by the install and upgrade scripts. Does nothing when tenancy is not available.
     *
     * @return bool Whether the capabilities were granted.
     */
    public static function add_plugin_capabilities_to_tenant_admin_role(): bool {
        if (!self::is_available()) {
            return false;
        }
        \tool_tenant\tenancy::add_plugin_capabilities_to_tenant_admin_role('local_coursegen');
        return true;
    }

    /**
     * Test-only: makes the class behave as on a site without tool_tenant.
     *
     * Lets PHPUnit exercise the plain Moodle LMS path on a Workplace site.
     * Undo it with {@see self::reset_for_testing()}.
     *
     * @return void
     * @throws \coding_exception When called outside PHPUnit.
     */
    public static function simulate_unavailable_for_testing(): void {
        self::assert_testing();
        self::$simulateunavailable = true;
    }

    /**
     * Test-only: restores the real tool_tenant detection.
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
