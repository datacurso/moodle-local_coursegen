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
 * The upgrade step that rebuilds the template tables.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_coursegen_drop_old_template_tables
 * @covers     ::local_coursegen_install_template_tables
 */
final class upgrade_template_tables_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/coursegen/db/upgrade.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    public function test_the_final_tables_are_there_after_an_install(): void {
        $tables = $this->existing_tables();

        $this->assertSame(['local_coursegen_template' => true, 'local_coursegen_tpl_item' => true], $tables['final']);
        $this->assertSame(['local_coursegen_tpl_section' => false, 'local_coursegen_tpl_space' => false], $tables['old']);
    }

    public function test_a_site_with_the_final_tables_keeps_them_and_their_rows(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $row = (object) [
            'courseid' => 1, 'name' => 'Kept', 'description' => null,
            'timecreated' => 1, 'timemodified' => 1, 'usermodified' => 0,
        ];
        $id = $DB->insert_record('local_coursegen_template', $row);

        local_coursegen_drop_old_template_tables($dbman);
        local_coursegen_install_template_tables($dbman);

        $kept = $DB->record_exists('local_coursegen_template', ['id' => $id]);
        $this->assertTrue($kept);
    }

    public function test_a_site_with_the_previous_shape_gets_the_final_one(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $this->drop('local_coursegen_tpl_item');
        $this->drop('local_coursegen_template');
        $this->create_previous_shape($dbman);

        local_coursegen_drop_old_template_tables($dbman);
        local_coursegen_install_template_tables($dbman);

        $tables = $this->existing_tables();
        $oldcolumn = $dbman->field_exists('local_coursegen_template', 'maxsections');
        $this->assertSame(['local_coursegen_template' => true, 'local_coursegen_tpl_item' => true], $tables['final']);
        $this->assertSame(['local_coursegen_tpl_section' => false, 'local_coursegen_tpl_space' => false], $tables['old']);
        $this->assertFalse($oldcolumn);
    }

    /**
     * Whether each final and each old template table exists.
     *
     * @return array
     */
    private function existing_tables(): array {
        $final = $this->existence(['local_coursegen_template', 'local_coursegen_tpl_item']);
        $old = $this->existence(['local_coursegen_tpl_section', 'local_coursegen_tpl_space']);
        return ['final' => $final, 'old' => $old];
    }

    /**
     * Whether each of the tables exists.
     *
     * @param string[] $names
     * @return array Table name => whether it exists.
     */
    private function existence(array $names): array {
        global $DB;
        $dbman = $DB->get_manager();
        $existence = [];
        foreach ($names as $name) {
            $existence[$name] = $dbman->table_exists($name);
        }
        return $existence;
    }

    /**
     * Drop a table of the plugin.
     *
     * @param string $name
     */
    private function drop(string $name): void {
        global $DB;
        $table = new \xmldb_table($name);
        $DB->get_manager()->drop_table($table);
    }

    /**
     * The template table with its old columns and the section table, as the previous schema had them.
     *
     * @param \database_manager $dbman
     */
    private function create_previous_shape(\database_manager $dbman): void {
        $template = new \xmldb_table('local_coursegen_template');
        $template->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $template->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $template->add_field('maxsections', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $template->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($template);

        $section = new \xmldb_table('local_coursegen_tpl_section');
        $section->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $section->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $section->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($section);
    }
}
