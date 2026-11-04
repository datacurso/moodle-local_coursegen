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

use xmldb_field;
use xmldb_index;
use xmldb_table;

/**
 * Tests for the course sessions table schema repair.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\schema_repair
 */
final class schema_repair_test extends \advanced_testcase {
    /** @var string Table under repair. */
    private const TABLE = 'local_coursegen_course_sessions';

    /**
     * Schema changes cannot be rolled back, so each test resets the database itself.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
    }

    /**
     * Whether courseid is currently NOT NULL.
     *
     * @return bool
     */
    private function courseid_is_not_null(): bool {
        global $DB;
        return !empty($DB->get_columns(self::TABLE, false)['courseid']->not_null);
    }

    /**
     * Whether the coursedata column exists.
     *
     * @return bool
     */
    private function coursedata_exists(): bool {
        global $DB;
        return array_key_exists('coursedata', $DB->get_columns(self::TABLE, false));
    }

    /**
     * Reproduces the broken production schema: courseid NOT NULL and/or no coursedata.
     *
     * @param bool $notnull Make courseid NOT NULL.
     * @param bool $dropdata Drop coursedata.
     */
    private function break_table(bool $notnull, bool $dropdata): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new xmldb_table(self::TABLE);
        if ($dropdata) {
            $dbman->drop_field($table, new xmldb_field('coursedata'));
        }
        if ($notnull) {
            $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
            $dbman->change_field_notnull($table, $field);
            $dbman->add_index($table, $index);
        }
    }

    /**
     * Inserts a session without a course, as start_course_planning() does.
     *
     * @return int New row id.
     */
    private function insert_session_without_course(): int {
        global $DB;
        return $DB->insert_record(self::TABLE, (object) ([
            'courseid' => null,
            'coursedata' => '{}',
            'timecreated' => time(),
            'timemodified' => time(),
        ] + $this->required_defaults()));
    }

    /**
     * Values for the remaining NOT NULL columns without default.
     *
     * @return array
     */
    private function required_defaults(): array {
        global $DB;
        $defaults = [];
        foreach ($DB->get_columns(self::TABLE, false) as $name => $column) {
            if ($name === 'id' || !$column->not_null || $column->has_default || in_array($name, ['timecreated', 'timemodified'])) {
                continue;
            }
            $defaults[$name] = $column->meta_type === 'I' || $column->meta_type === 'N' ? 0 : 'x';
        }
        return $defaults;
    }

    /**
     * Both defects are fixed and the repair is a no-op the second time.
     */
    public function test_repairs_notnull_courseid_and_missing_coursedata(): void {
        global $DB;
        $this->resetAfterTest();
        $this->break_table(true, true);
        $this->assertTrue($this->courseid_is_not_null());
        $this->assertFalse($this->coursedata_exists());

        $this->assertTrue(schema_repair::repair_course_sessions_table($DB, $DB->get_manager()));

        $this->assertFalse($this->courseid_is_not_null());
        $this->assertTrue($this->coursedata_exists());
        $id = $this->insert_session_without_course();
        $this->assertNull($DB->get_field(self::TABLE, 'courseid', ['id' => $id]));
        $this->assertTrue($DB->get_manager()->index_exists(
            new xmldb_table(self::TABLE),
            new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid'])
        ));

        $this->assertFalse(schema_repair::repair_course_sessions_table($DB, $DB->get_manager()));
        $this->assertFalse($this->courseid_is_not_null());
        $this->assertTrue($this->coursedata_exists());
    }

    /**
     * Only courseid is broken.
     */
    public function test_repairs_only_notnull_courseid(): void {
        global $DB;
        $this->break_table(true, false);

        $this->assertTrue(schema_repair::repair_course_sessions_table($DB, $DB->get_manager()));

        $this->assertFalse($this->courseid_is_not_null());
        $this->assertNotEmpty($this->insert_session_without_course());
    }

    /**
     * Only coursedata is missing.
     */
    public function test_repairs_only_missing_coursedata(): void {
        global $DB;
        $this->break_table(false, true);

        $this->assertTrue(schema_repair::repair_course_sessions_table($DB, $DB->get_manager()));

        $this->assertTrue($this->coursedata_exists());
        $this->assertNotEmpty($this->insert_session_without_course());
    }

    /**
     * A healthy table is left untouched.
     */
    public function test_healthy_table_is_left_untouched(): void {
        global $DB;
        $before = array_keys($DB->get_columns(self::TABLE, false));

        $this->assertFalse(schema_repair::repair_course_sessions_table($DB, $DB->get_manager()));

        $this->assertSame($before, array_keys($DB->get_columns(self::TABLE, false)));
        $this->assertFalse($this->courseid_is_not_null());
        $this->assertTrue($this->coursedata_exists());
    }
}
