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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once(__DIR__ . '/../db/upgrade.php');

/**
 * Tests for the plugin upgrade steps.
 *
 * The 2025092401 step created local_coursegen_module_jobs.model_name while
 * install.xml and module_job_service use system_instruction_name, and no
 * later step renamed it. The reconciliation step is exercised from each
 * legacy state the column can be in. DDL changes are not rolled back by the
 * test framework, so every test restores the install.xml schema on teardown.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_coursegen_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /** @var int Savepoint that reconciles the module_jobs column name. */
    private const SAVEPOINT = 2026100100;

    /** @var int Last savepoint before the reconciliation step. */
    private const PREVIOUS = 2026090900;

    /** @var string Debugging message emitted when both columns hold different values. */
    private const CONFLICTMESSAGE = 'local_coursegen: 1 module job(s) had different values in model_name and '
        . 'system_instruction_name; system_instruction_name was kept and model_name dropped.';

    /**
     * Put the module_jobs table back to its install.xml shape.
     */
    protected function tearDown(): void {
        $this->restore_install_schema();
        parent::tearDown();
    }

    /**
     * The module jobs table definition.
     *
     * @return \xmldb_table
     */
    private function jobs_table(): \xmldb_table {
        return new \xmldb_table('local_coursegen_module_jobs');
    }

    /**
     * The legacy column created by the 2025092401 step.
     *
     * @return \xmldb_field
     */
    private function legacy_field(): \xmldb_field {
        return new \xmldb_field('model_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'context_type');
    }

    /**
     * The column declared in install.xml.
     *
     * @return \xmldb_field
     */
    private function current_field(): \xmldb_field {
        return new \xmldb_field('system_instruction_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'context_type');
    }

    /**
     * Drop the legacy column and re-add the install.xml one when missing.
     *
     * @return void
     */
    private function restore_install_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = $this->jobs_table();
        if ($dbman->field_exists($table, $this->legacy_field())) {
            $dbman->drop_field($table, $this->legacy_field());
        }
        if (!$dbman->field_exists($table, $this->current_field())) {
            $dbman->add_field($table, $this->current_field());
        }
    }

    /**
     * Insert a module job row with the given column overrides.
     *
     * @param array $overrides Column values to override.
     * @return int Inserted record id.
     */
    private function insert_job(array $overrides): int {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'courseid' => SITEID,
            'userid' => get_admin()->id,
            'job_id' => 'job-' . count($DB->get_records('local_coursegen_module_jobs')),
            'status' => 'completed',
            'generate_images' => 0,
            'context_type' => null,
            'sectionnum' => null,
            'beforemod' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('local_coursegen_module_jobs', $record);
    }

    /**
     * Rewind the installed version and run the upgrade from the previous savepoint.
     *
     * @return void
     */
    private function run_upgrade_from_previous(): void {
        set_config('version', self::PREVIOUS, 'local_coursegen');
        xmldb_local_coursegen_upgrade(self::PREVIOUS);
    }

    /**
     * Assert the table carries system_instruction_name and no model_name column.
     *
     * @return void
     */
    private function assert_install_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->field_exists($this->jobs_table(), $this->current_field()));
        $this->assertFalse($dbman->field_exists($this->jobs_table(), $this->legacy_field()));
    }

    /**
     * The installed schema declares system_instruction_name and no model_name column.
     */
    public function test_install_schema_uses_system_instruction_name(): void {
        $this->assert_install_schema();
    }

    /**
     * A site that still carries the legacy column gets it renamed, keeping its data.
     */
    public function test_upgrade_renames_legacy_model_name_column(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $dbman->drop_field($this->jobs_table(), $this->current_field());
        $dbman->add_field($this->jobs_table(), $this->legacy_field());
        $id = $this->insert_job(['job_id' => 'job-legacy', 'model_name' => 'Legacy guideline']);

        $this->run_upgrade_from_previous();

        $this->assert_install_schema();
        $this->assertSame(
            'Legacy guideline',
            $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $id])
        );
        $this->assertDebuggingNotCalled();
        $this->assertEquals(self::SAVEPOINT, get_config('local_coursegen', 'version'));
    }

    /**
     * When both columns exist the legacy values fill the gaps, conflicts are
     * reported and the legacy column is dropped.
     */
    public function test_upgrade_merges_and_drops_legacy_column_when_both_exist(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $dbman->add_field($this->jobs_table(), $this->legacy_field());
        $onlylegacy = $this->insert_job([
            'job_id' => 'job-a', 'system_instruction_name' => null, 'model_name' => 'Only legacy',
        ]);
        $conflict = $this->insert_job([
            'job_id' => 'job-b', 'system_instruction_name' => 'Kept', 'model_name' => 'Conflicting',
        ]);
        $same = $this->insert_job([
            'job_id' => 'job-c', 'system_instruction_name' => 'Same', 'model_name' => 'Same',
        ]);
        $empty = $this->insert_job([
            'job_id' => 'job-d', 'system_instruction_name' => null, 'model_name' => null,
        ]);

        $this->run_upgrade_from_previous();

        $this->assert_install_schema();
        $this->assertDebuggingCalled(self::CONFLICTMESSAGE, DEBUG_NORMAL);
        $names = $DB->get_records_menu('local_coursegen_module_jobs', null, '', 'id, system_instruction_name');
        $this->assertSame('Only legacy', $names[$onlylegacy]);
        $this->assertSame('Kept', $names[$conflict]);
        $this->assertSame('Same', $names[$same]);
        $this->assertNull($names[$empty]);
        $this->assertEquals(self::SAVEPOINT, get_config('local_coursegen', 'version'));
    }

    /**
     * A site already on the install.xml schema is left untouched.
     */
    public function test_upgrade_is_a_noop_on_current_schema(): void {
        global $DB;

        $this->resetAfterTest();
        $id = $this->insert_job(['job_id' => 'job-current', 'system_instruction_name' => 'Current']);

        $this->run_upgrade_from_previous();

        $this->assert_install_schema();
        $this->assertSame('Current', $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $id]));
        $this->assertDebuggingNotCalled();
        $this->assertEquals(self::SAVEPOINT, get_config('local_coursegen', 'version'));
    }
}
