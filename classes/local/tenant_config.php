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
 * Per-tenant plugin configuration.
 *
 * Every Workplace tenant is fully independent: its values live in
 * local_coursegen_tenant_config and there is no site-wide layer (config_plugins
 * is never read nor written for these settings). Every reader resolves
 * "tenant value -> default".
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_config {
    /** @var string Database table holding the tenant values. */
    public const TABLE = 'local_coursegen_tenant_config';

    /** @var string Plugin owning the request cache. */
    private const PLUGIN = 'local_coursegen';

    /**
     * Returns the effective value of a setting for a tenant.
     *
     * Resolution order: tenant value, provided default. A value stored as an
     * empty string counts as set.
     *
     * @param string $name Setting name.
     * @param mixed $default Value returned when the tenant does not define the setting.
     * @param int|null $tenantid Tenant id, or null for the current user's tenant.
     * @return mixed
     */
    public static function get(string $name, $default = null, ?int $tenantid = null) {
        $tenantid = $tenantid ?? tenancy::get_tenant_id();

        $values = self::load_values($tenantid);
        return array_key_exists($name, $values) ? $values[$name] : $default;
    }

    /**
     * Returns only the value stored for the tenant, null when it stores none (no default applied).
     *
     * @param string $name Setting name.
     * @param int $tenantid Tenant id.
     * @return string|null
     */
    public static function get_raw(string $name, int $tenantid): ?string {
        return self::load_values($tenantid)[$name] ?? null;
    }

    /**
     * Stores a setting for a tenant.
     *
     * @param string $name Setting name.
     * @param mixed $value Scalar value; it is stored as a string like set_config() does.
     * @param int $tenantid Tenant id.
     * @return void
     */
    public static function set(string $name, $value, int $tenantid): void {
        global $DB;

        $conditions = ['tenantid' => $tenantid, 'name' => $name];
        $record = (object) ($conditions + ['value' => (string) $value]);
        $existingid = $DB->get_field(self::TABLE, 'id', $conditions);
        if ($existingid) {
            $record->id = $existingid;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }

        self::get_cache()->delete($tenantid);
    }

    /**
     * Removes a setting of a tenant so the default applies again.
     *
     * @param string $name Setting name.
     * @param int $tenantid Tenant id.
     * @return void
     */
    public static function unset(string $name, int $tenantid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['tenantid' => $tenantid, 'name' => $name]);
        self::get_cache()->delete($tenantid);
    }

    /**
     * Returns every value stored for a tenant as name => value.
     *
     * @param int $tenantid Tenant id.
     * @return array<string, string>
     */
    public static function get_all(int $tenantid): array {
        return self::load_values($tenantid);
    }

    /**
     * Deletes every value of a tenant.
     *
     * @param int $tenantid Tenant id.
     * @return void
     */
    public static function delete_all(int $tenantid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['tenantid' => $tenantid]);
        self::get_cache()->delete($tenantid);
    }

    /**
     * Drops the per-request cache of tenant values.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::get_cache()->purge();
    }

    /**
     * Loads (and caches for the request) the values of a tenant.
     *
     * @param int $tenantid Tenant id.
     * @return array<string, string>
     */
    private static function load_values(int $tenantid): array {
        global $DB;

        $cache = self::get_cache();
        $values = $cache->get($tenantid);
        if (is_array($values)) {
            return $values;
        }

        $values = $DB->get_records_menu(self::TABLE, ['tenantid' => $tenantid], 'name', 'name, value');
        $cache->set($tenantid, $values);
        return $values;
    }

    /**
     * Returns the request-scoped cache keyed by tenant id.
     *
     * @return \cache
     */
    private static function get_cache(): \cache {
        return \cache::make(self::PLUGIN, 'tenantconfig');
    }
}
