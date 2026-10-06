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

/**
 * Moodle Workplace multi-tenancy callbacks.
 *
 * tool_tenant discovers this class by name and asks it which capabilities of
 * this plugin are safe to grant to the "Tenant administrator" role.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_tenant {
    /**
     * Capabilities of this plugin granted to the "Tenant administrator" role.
     *
     * @return array<string, int> Capability name => CAP_ALLOW.
     */
    public static function get_tenant_admin_capabilities(): array {
        return [
            'local/coursegen:createcoursewithai' => CAP_ALLOW,
            'local/coursegen:managesysteminstructions' => CAP_ALLOW,
            'local/coursegen:manageimagegeneration' => CAP_ALLOW,
            'local/coursegen:managetenantsettings' => CAP_ALLOW,
        ];
    }
}
