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
     * Replace everything saved for the activities of a template.
     *
     * @param int $templateid Template id, for example 3.
     * @param stdClass[] $rows New rows, without id.
     */
    public function replace_items(int $templateid, array $rows): void {
        global $DB;

        $DB->delete_records(self::ITEM_TABLE, ['templateid' => $templateid]);
        if ($rows === []) {
            return;
        }

        $DB->insert_records(self::ITEM_TABLE, $rows);
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
