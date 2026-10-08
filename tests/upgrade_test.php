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

use xmldb_field;
use xmldb_index;
use xmldb_table;

/**
 * Tests for the multi-tenancy schema and role set up done on install and upgrade.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upgrade_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * The tenant configuration table exists with its fields and the (tenantid, name) unique key.
     */
    public function test_tenant_config_table_is_installed(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_coursegen_tenant_config');
        $this->assertTrue($dbman->table_exists($table));
        foreach (['id', 'tenantid', 'name', 'value'] as $fieldname) {
            $this->assertTrue($dbman->field_exists($table, new xmldb_field($fieldname)), $fieldname);
        }

        // Unique keys are backed by a unique index on the same columns.
        $this->assertTrue($dbman->index_exists($table, new xmldb_index('tenantid_name', XMLDB_INDEX_UNIQUE, ['tenantid', 'name'])));
    }

    /**
     * System instructions carry the owning tenant and are indexed by (tenantid, deleted).
     */
    public function test_system_instruction_tenant_column_is_installed(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_coursegen_system_instruction');
        $this->assertTrue($dbman->field_exists($table, new xmldb_field('tenantid')));
        $this->assertTrue(
            $dbman->index_exists($table, new xmldb_index('tenantid_deleted', XMLDB_INDEX_NOTUNIQUE, ['tenantid', 'deleted']))
        );

        $columns = $DB->get_columns('local_coursegen_system_instruction');
        $this->assertTrue($columns['tenantid']->not_null);
        $this->assertEquals(0, $columns['tenantid']->default_value);
    }

    /**
     * The plugin install hook granted the tenant administrator role all plugin capabilities.
     */
    public function test_tenant_admin_role_holds_plugin_capabilities_after_install(): void {
        $this->require_tool_tenant();
        global $DB;

        $roleid = \tool_tenant\manager::get_tenant_admin_role();
        $allowed = $DB->get_fieldset_select(
            'role_capabilities',
            'capability',
            'roleid = :roleid AND contextid = :contextid AND permission = :permission',
            ['roleid' => $roleid, 'contextid' => \context_system::instance()->id, 'permission' => CAP_ALLOW]
        );

        $expected = [
            'local/coursegen:createcoursewithai',
            'local/coursegen:managesysteminstructions',
            'local/coursegen:manageimagegeneration',
            'local/coursegen:managetenantsettings',
        ];
        foreach ($expected as $capability) {
            $this->assertContains($capability, $allowed);
        }
    }

    /**
     * A fresh install keeps no settings outside a tenant and creates no tenant rows: only the plugin version is stored.
     */
    public function test_fresh_install_stores_no_site_settings(): void {
        global $DB;

        $this->assertSame(['version'], array_keys((array) get_config('local_coursegen')));
        $this->assertSame(0, $DB->count_records('local_coursegen_tenant_config'));
        $this->assertSame(0, $DB->count_records('local_coursegen_system_instruction', ['tenantid' => 0]));
    }
}
