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
require_once($CFG->dirroot . '/local/coursegen/db/upgrade.php');

/**
 * Tests for the plugin upgrade steps.
 *
 * Sites upgraded through db/upgrade.php got a `model_name` column on
 * local_coursegen_module_jobs, while fresh installs (db/install.xml) and the
 * code use `system_instruction_name`. The upgrade step must converge both.
 *
 * DDL changes are not rolled back by resetAfterTest(), so every test restores
 * the install.xml schema before finishing.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_coursegen_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /** @var int Last savepoint before the schema drift fix. */
    private const PREVIOUS_VERSION = 2026090900;

    /**
     * Restore the install.xml schema of local_coursegen_module_jobs.
     */
    protected function tearDown(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursegen_module_jobs');
        $legacy = $this->legacy_field();
        $current = $this->current_field();
        if ($dbman->field_exists($table, $legacy)) {
            if ($dbman->field_exists($table, $current)) {
                $dbman->drop_field($table, $legacy);
            } else {
                $dbman->rename_field($table, $legacy, 'system_instruction_name');
            }
        }
        parent::tearDown();
    }

    /**
     * Definition of the legacy column created by the 2025092401 upgrade step.
     *
     * @return \xmldb_field
     */
    private function legacy_field(): \xmldb_field {
        return new \xmldb_field('model_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'context_type');
    }

    /**
     * Definition of the column declared in install.xml.
     *
     * @return \xmldb_field
     */
    private function current_field(): \xmldb_field {
        return new \xmldb_field('system_instruction_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'context_type');
    }

    /**
     * Insert a minimal module job row with the given extra columns.
     *
     * @param array $extra Extra column values.
     * @return int Record id.
     */
    private function insert_job(array $extra): int {
        global $DB;

        return $DB->insert_record('local_coursegen_module_jobs', (object) array_merge([
            'courseid' => 1,
            'userid' => 2,
            'job_id' => 'job-' . uniqid(),
            'status' => 'done',
            'generate_images' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $extra));
    }

    /**
     * Rewind the recorded plugin version so the new savepoint is reachable.
     */
    private function rewind_plugin_version(): void {
        set_config('version', self::PREVIOUS_VERSION, 'local_coursegen');
    }

    /**
     * A site that only has the legacy column gets it renamed, keeping the data.
     */
    public function test_upgrade_renames_legacy_model_name_column(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursegen_module_jobs');

        // Recreate the drifted schema: only model_name exists.
        $dbman->rename_field($table, $this->current_field(), 'model_name');
        $id = $this->insert_job(['model_name' => 'Legacy instruction']);
        $this->rewind_plugin_version();

        $this->assertTrue(xmldb_local_coursegen_upgrade(self::PREVIOUS_VERSION));

        $this->assertTrue($dbman->field_exists($table, $this->current_field()));
        $this->assertFalse($dbman->field_exists($table, $this->legacy_field()));
        $this->assertSame(
            'Legacy instruction',
            $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $id])
        );
    }

    /**
     * A site with both columns keeps system_instruction_name, back-fills it from
     * model_name where empty, and drops the legacy column.
     */
    public function test_upgrade_merges_and_drops_legacy_column_when_both_exist(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursegen_module_jobs');

        $dbman->add_field($table, $this->legacy_field());
        $onlylegacy = $this->insert_job(['model_name' => 'From legacy', 'system_instruction_name' => null]);
        $both = $this->insert_job(['model_name' => 'Ignored', 'system_instruction_name' => 'Kept']);
        $same = $this->insert_job(['model_name' => 'Same', 'system_instruction_name' => 'Same']);
        $this->rewind_plugin_version();

        $this->assertTrue(xmldb_local_coursegen_upgrade(self::PREVIOUS_VERSION));

        // Exactly one row had two different values: its discarded legacy value is reported.
        $this->assertDebuggingCalled(
            'local_coursegen upgrade: 1 module job(s) had a legacy model_name different from '
                . 'system_instruction_name; the legacy value was discarded.',
            DEBUG_NORMAL
        );

        $this->assertTrue($dbman->field_exists($table, $this->current_field()));
        $this->assertFalse($dbman->field_exists($table, $this->legacy_field()));
        $this->assertSame(
            'From legacy',
            $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $onlylegacy])
        );
        $this->assertSame(
            'Kept',
            $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $both])
        );
        $this->assertSame(
            'Same',
            $DB->get_field('local_coursegen_module_jobs', 'system_instruction_name', ['id' => $same])
        );
    }

    /**
     * Without conflicting values the merge is silent.
     */
    public function test_upgrade_merge_is_silent_without_conflicts(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursegen_module_jobs');

        $dbman->add_field($table, $this->legacy_field());
        $this->insert_job(['model_name' => 'From legacy', 'system_instruction_name' => null]);
        $this->rewind_plugin_version();

        $this->assertTrue(xmldb_local_coursegen_upgrade(self::PREVIOUS_VERSION));

        $this->assertDebuggingNotCalled();
        $this->assertFalse($dbman->field_exists($table, $this->legacy_field()));
    }

    /**
     * A fresh install (install.xml schema) is left untouched by the step.
     */
    public function test_upgrade_is_noop_on_install_xml_schema(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursegen_module_jobs');
        $this->rewind_plugin_version();

        $this->assertTrue(xmldb_local_coursegen_upgrade(self::PREVIOUS_VERSION));

        $this->assertTrue($dbman->field_exists($table, $this->current_field()));
        $this->assertFalse($dbman->field_exists($table, $this->legacy_field()));
    }
}
