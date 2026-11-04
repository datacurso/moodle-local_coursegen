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

use database_manager;
use xmldb_field;
use xmldb_index;
use xmldb_table;

/**
 * Idempotent repair of the course sessions table schema.
 *
 * Some sites kept courseid as NOT NULL and/or lack the coursedata column even
 * though earlier upgrade steps should have fixed them, which makes inserting a
 * session without a course fail. The repair inspects the real schema and only
 * changes what is wrong.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_repair {
    /** @var string Table repaired by this class. */
    private const TABLE = 'local_coursegen_course_sessions';

    /**
     * Makes courseid nullable and ensures coursedata exists.
     *
     * @param \moodle_database $db Database connection used to read the real column metadata.
     * @param database_manager $dbman Database manager used to alter the schema.
     * @return bool True when something was changed, false when the schema was already healthy.
     */
    public static function repair_course_sessions_table(\moodle_database $db, database_manager $dbman): bool {
        $table = new xmldb_table(self::TABLE);
        if (!$dbman->table_exists($table)) {
            return false;
        }

        $changed = self::make_courseid_nullable($db, $dbman, $table);
        $changed = self::ensure_coursedata_field($dbman, $table) || $changed;

        return $changed;
    }

    /**
     * Drops the NOT NULL constraint of courseid when it is still present.
     *
     * @param \moodle_database $db Database connection.
     * @param database_manager $dbman Database manager.
     * @param xmldb_table $table Course sessions table definition.
     * @return bool True when the column was changed.
     */
    private static function make_courseid_nullable(\moodle_database $db, database_manager $dbman, xmldb_table $table): bool {
        // Columns are read uncached so a column changed earlier in the same request is seen.
        $columns = $db->get_columns(self::TABLE, false);
        if (!isset($columns['courseid']) || empty($columns['courseid']->not_null)) {
            return false;
        }

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        // The index must be dropped before altering the column and recreated afterwards.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $dbman->change_field_notnull($table, $field);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        return true;
    }

    /**
     * Adds the coursedata field when it is missing.
     *
     * @param database_manager $dbman Database manager.
     * @param xmldb_table $table Course sessions table definition.
     * @return bool True when the field was added.
     */
    private static function ensure_coursedata_field(database_manager $dbman, xmldb_table $table): bool {
        $field = new xmldb_field('coursedata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'courseid');
        if ($dbman->field_exists($table, $field)) {
            return false;
        }
        $dbman->add_field($table, $field);

        return true;
    }
}
