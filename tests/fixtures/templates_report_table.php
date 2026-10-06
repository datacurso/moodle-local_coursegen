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

use core_reportbuilder\table\system_report_table;

/**
 * Table of the templates report that hands its rows to a test.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates_report_table extends system_report_table {
    /**
     * Format a row, naming each value after its column instead of its alias.
     *
     * @param array|\stdClass $row Raw row.
     * @return array
     */
    public function format_row($row): array {
        $record = parent::format_row($row);
        $result = [];
        $columns = $this->report->get_columns();
        foreach ($columns as $column) {
            $alias = $column->get_column_alias();
            $result[$column->get_name()] = $record[$alias];
        }

        return $result;
    }

    /**
     * Every row of the report, or only the first page.
     *
     * @param int $pagesize Rows per page, or 0 to read them all.
     * @return array Rows formatted by column name.
     */
    public function get_table_rows(int $pagesize = 0): array {
        global $PAGE;

        $PAGE->set_url('/');
        $this->guess_base_url();
        $this->setup();
        $this->query_db($pagesize, false);

        $rows = [];
        foreach ($this->rawdata as $record) {
            $rows[] = $this->format_row($record);
        }
        $this->close_recordset();

        return $rows;
    }

    /**
     * How many rows the whole report has, whatever the page.
     *
     * @return int
     */
    public function count_all_rows(): int {
        global $PAGE;

        $PAGE->set_url('/');
        $this->guess_base_url();
        $this->setup();
        $this->query_db(1, true);
        $this->close_recordset();

        return (int) $this->totalrows;
    }
}
