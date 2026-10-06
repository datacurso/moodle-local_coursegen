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
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenancy {
    /**
     * Returns the tenant a user belongs to (the current user when omitted).
     *
     * For a user who can switch tenants this is the tenant they are currently in.
     *
     * @param int|null $userid User id, or null for the current user.
     * @return int
     */
    public static function get_tenant_id(?int $userid = null): int {
        return \tool_tenant\tenancy::get_tenant_id($userid);
    }

    /**
     * Returns the course category attached to a tenant, or null when it has none or does not exist.
     *
     * @param int $tenantid Tenant id.
     * @return int|null
     */
    public static function get_tenant_categoryid(int $tenantid): ?int {
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
     * @return string
     */
    public static function get_tenant_name(int $tenantid): string {
        $tenants = \tool_tenant\tenancy::get_tenants();
        if (!isset($tenants[$tenantid])) {
            return '';
        }
        return format_string($tenants[$tenantid]->name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Returns the Workplace default tenant, the one unallocated users belong to.
     *
     * @return int
     */
    public static function get_default_tenant_id(): int {
        return \tool_tenant\tenancy::get_default_tenant_id();
    }
}
