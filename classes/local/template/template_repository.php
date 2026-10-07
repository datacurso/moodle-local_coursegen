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

namespace local_coursegen\local\template;

use local_coursegen\local\service\template_export_uids;
use stdClass;

/**
 * Reads and writes the rows of the templates and of what the AI does with each of their activities.
 *
 * It never opens a transaction: the service that calls it decides what has to be saved together.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_repository {
    /** @var string Table of the templates. */
    private const TEMPLATE_TABLE = 'local_coursegen_template';

    /** @var string Table of the activities of the templates. */
    private const ITEM_TABLE = 'local_coursegen_tpl_item';

    /**
     * Find a template.
     *
     * @param int $templateid Template id, for example 3.
     * @return stdClass|null The row, or null when there is none.
     */
    public function find(int $templateid): ?stdClass {
        global $DB;

        $record = $DB->get_record(self::TEMPLATE_TABLE, ['id' => $templateid]);
        if (!$record) {
            return null;
        }

        return $record;
    }

    /**
     * What is saved for the activities of a template.
     *
     * @param int $templateid Template id, for example 3.
     * @return stdClass[] Rows keyed by the course module id.
     */
    public function items_of(int $templateid): array {
        global $DB;

        $rows = $DB->get_records(self::ITEM_TABLE, ['templateid' => $templateid], 'id ASC');
        $bycmid = [];
        foreach ($rows as $row) {
            $bycmid[(int) $row->cmid] = $row;
        }

        return $bycmid;
    }

    /**
     * Insert a template.
     *
     * @param stdClass $record Row without id.
     * @return int The new id.
     */
    public function insert_template(stdClass $record): int {
        global $DB;

        return (int) $DB->insert_record(self::TEMPLATE_TABLE, $record);
    }

    /**
     * Update a template.
     *
     * @param stdClass $record Row with its id.
     */
    public function update_template(stdClass $record): void {
        global $DB;

        $DB->update_record(self::TEMPLATE_TABLE, $record);
    }

    /**
     * Replace what is saved for the activities of a template.
     *
     * An activity that was saved before keeps its uid, so the links and previews that name it stay valid;
     * an activity that is new gets a fresh one.
     *
     * @param int $templateid Template id, for example 3.
     * @param stdClass[] $rows Rows without id and without uid: templateid, cmid, action, instruction, timemodified.
     */
    public function replace_items(int $templateid, array $rows): void {
        global $DB;

        $uidbycmid = $this->uids_by_cmid($templateid);
        $DB->delete_records(self::ITEM_TABLE, ['templateid' => $templateid]);
        if ($rows === []) {
            return;
        }

        $DB->insert_records(self::ITEM_TABLE, $this->with_uids($rows, $uidbycmid));
    }

    /**
     * The uids saved for the activities of a template.
     *
     * @param int $templateid Template id, for example 3.
     * @return string[] The uids keyed by the course module id.
     */
    private function uids_by_cmid(int $templateid): array {
        $uids = [];
        foreach ($this->items_of($templateid) as $cmid => $item) {
            $uids[$cmid] = (string) $item->uid;
        }

        return $uids;
    }

    /**
     * Put a uid in each row: the one the activity had, or a new one.
     *
     * @param stdClass[] $rows The rows to save.
     * @param string[] $uidbycmid The uids the activities had, keyed by the course module id.
     * @return stdClass[] The rows with their uid.
     */
    private function with_uids(array $rows, array $uidbycmid): array {
        $withuids = [];
        foreach ($rows as $row) {
            $copy = clone (object) $row;
            $copy->uid = $uidbycmid[(int) $copy->cmid] ?? template_export_uids::new_item_uid();
            $withuids[] = $copy;
        }

        return $withuids;
    }

    /**
     * Delete a template and what is saved for its activities.
     *
     * @param int $templateid Template id, for example 3.
     */
    public function delete(int $templateid): void {
        global $DB;

        $DB->delete_records(self::ITEM_TABLE, ['templateid' => $templateid]);
        $DB->delete_records(self::TEMPLATE_TABLE, ['id' => $templateid]);
    }
}
