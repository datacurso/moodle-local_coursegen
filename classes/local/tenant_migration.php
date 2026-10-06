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
 * Moves the former site-wide plugin data to the Workplace default tenant.
 *
 * Before every tenant became fully independent, the plugin settings lived in
 * config_plugins and the shared system instructions had tenant id 0. The
 * upgrade hands both to the default tenant, so the site keeps behaving as
 * before for its users, and nothing remains outside a real tenant.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_migration {
    /** @var string Plugin whose config_plugins rows are migrated. */
    private const PLUGIN = 'local_coursegen';

    /** @var string[] Tenant-scoped settings with a fixed name. */
    private const SETTINGS = [
        'enablesubsections',
        'datacurso_service_url',
        'datacurso_service_url_eu',
        'generationmode',
        'overridecourse',
        'overrideactivity',
    ];

    /** @var string[] Prefixes of the per-activity image generation settings. */
    private const SETTING_PREFIXES = ['enableimg', 'maximg'];

    /** @var int Tenant id the former site instructions were stored with. */
    private const LEGACY_SITE_TENANT = 0;

    /**
     * Moves the site settings and site system instructions to the default tenant.
     *
     * A value the default tenant already stores wins over the site value; the
     * site value is removed from config_plugins either way. Safe to run more
     * than once.
     *
     * @return void
     */
    public static function migrate_site_data_to_default_tenant(): void {
        global $DB;

        $tenantid = tenancy::get_default_tenant_id();

        foreach (get_config(self::PLUGIN) as $name => $value) {
            if (!self::is_tenant_setting((string) $name)) {
                continue;
            }
            if (tenant_config::get_raw((string) $name, $tenantid) === null) {
                tenant_config::set((string) $name, $value, $tenantid);
            }
            unset_config((string) $name, self::PLUGIN);
        }

        $DB->set_field(
            'local_coursegen_system_instruction',
            'tenantid',
            $tenantid,
            ['tenantid' => self::LEGACY_SITE_TENANT]
        );
    }

    /**
     * Whether a config_plugins name is one of the tenant-scoped settings.
     *
     * @param string $name Setting name.
     * @return bool
     */
    private static function is_tenant_setting(string $name): bool {
        if (in_array($name, self::SETTINGS, true)) {
            return true;
        }
        foreach (self::SETTING_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
