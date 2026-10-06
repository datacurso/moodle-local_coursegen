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

use local_coursegen\local\tenancy;
use local_coursegen\local\tenant_config;

/**
 * Stores an admin setting in the configuration of the current tenant instead of config_plugins.
 *
 * Meant for admin_setting subclasses of this plugin: it replaces
 * config_read()/config_write(), which every core setting type uses to load
 * and save its value, so the admin settings pages read and save the values
 * of the tenant the user is currently in ({@see tenancy::get_tenant_id()}).
 *
 * A tenant without a stored value reads the setting default, so the setting
 * is never reported as new by the upgrade settings check. Writing the default
 * while the tenant stores nothing is a no-op, so applying the admin defaults
 * on install or upgrade creates no tenant rows.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait tenant_scoped_setting {
    /**
     * Reads the value of the current tenant, falling back to the setting default.
     *
     * @param string $name Setting name.
     * @return mixed
     */
    public function config_read($name) {
        $value = tenant_config::get_raw((string) $name, tenancy::get_tenant_id());
        if ($value !== null) {
            return $value;
        }
        $default = $this->get_defaultsetting();
        return $default === null ? null : (string) $default;
    }

    /**
     * Stores the value for the current tenant.
     *
     * @param string $name Setting name.
     * @param mixed $value New value, null removes the tenant value.
     * @return bool
     */
    public function config_write($name, $value) {
        if ($this->nosave) {
            return true;
        }

        $name = (string) $name;
        $tenantid = tenancy::get_tenant_id();
        $value = $value === null ? null : (string) $value;
        if ($this->config_read($name) === $value) {
            return true;
        }

        if ($value === null) {
            tenant_config::unset($name, $tenantid);
        } else {
            tenant_config::set($name, $value, $tenantid);
        }
        return true;
    }
}
