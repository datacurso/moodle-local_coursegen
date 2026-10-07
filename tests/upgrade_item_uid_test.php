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
 * The upgrade step that gives every saved activity of a template an opaque uid.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_coursegen_add_item_uid
 */
final class upgrade_item_uid_test extends \advanced_testcase {
    /** @var string Table that stands for the activities table as it was before the step. */
    private const TABLE = 'local_coursegen_zzuid';

    /**
     * Create the table as it was before the step: rows with a template id and no uid.
     */
    private function create_old_table(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/coursegen/db/upgrade.php');
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
    }

    /**
     * Every old row gets its own uid, the new column is unique per template and the step can run twice.
     */
    public function test_every_old_row_gets_its_own_uid_and_the_step_is_repeatable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->create_old_table();
        $dbman = $DB->get_manager();
        $DB->insert_record(self::TABLE, ['templateid' => 1]);
        $DB->insert_record(self::TABLE, ['templateid' => 1]);
        $DB->insert_record(self::TABLE, ['templateid' => 2]);

        local_coursegen_add_item_uid($dbman, self::TABLE);
        $first = $DB->get_fieldset_select(self::TABLE, 'uid', '1 = 1 ORDER BY id');
        local_coursegen_add_item_uid($dbman, self::TABLE);
        $second = $DB->get_fieldset_select(self::TABLE, 'uid', '1 = 1 ORDER BY id');
        $dbman->drop_table(new \xmldb_table(self::TABLE));

        $this->assertCount(3, array_unique($first));
        $this->assertSame($first, $second);
        foreach ($first as $uid) {
            $this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uid));
        }
    }
}
