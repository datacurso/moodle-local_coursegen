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

namespace local_coursegen\local\placeholder;

/**
 * Looks for placeholders in the html fields of one activity of a course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_scanner {
    /**
     * Scan every field of an activity: the columns of its own table and the rows of its child tables.
     *
     * @param \cm_info $cm The activity, e.g. the page 'Guia Didactica'.
     * @return activity_scan
     */
    public static function scan(\cm_info $cm): activity_scan {
        $findings = self::empty_findings();
        $findings = self::scan_own_columns($cm, $findings);
        $findings = self::scan_child_tables($cm, $findings);

        return new activity_scan(
            (int) $cm->id,
            (string) $cm->modname,
            $findings['fields'],
            $findings['problems'],
            $findings['hidden']
        );
    }

    /**
     * The findings of an activity before any field is read.
     *
     * @return array {fields, problems, hidden}
     */
    private static function empty_findings(): array {
        return ['fields' => [], 'problems' => [], 'hidden' => 0];
    }

    /**
     * Scan the columns of the table of the module itself.
     *
     * @param \cm_info $cm The activity.
     * @param array $findings What has been found so far.
     * @return array The findings with this table added.
     */
    private static function scan_own_columns(\cm_info $cm, array $findings): array {
        global $DB;

        $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        if (!$record) {
            return $findings;
        }
        $columns = scannable_fields::own_columns($cm->modname);
        $label = $cm->modname;
        return self::scan_columns_of($record, $label, $columns, $findings);
    }

    /**
     * Scan some columns of one row.
     *
     * @param \stdClass $row The row.
     * @param string $table Table name used to name the field, e.g. 'lesson_pages'.
     * @param string[] $columns The columns to read, e.g. ['title', 'contents'].
     * @param array $findings What has been found so far.
     * @return array The findings with this row added.
     */
    private static function scan_columns_of(\stdClass $row, string $table, array $columns, array $findings): array {
        foreach ($columns as $column) {
            if (!property_exists($row, $column)) {
                continue;
            }
            $text = (string) $row->$column;
            $field = $table . '.' . $column;
            $findings = self::add_text($findings, $field, (int) $row->id, $text);
        }
        return $findings;
    }

    /**
     * Scan the rows of the child tables of the module.
     *
     * @param \cm_info $cm The activity.
     * @param array $findings What has been found so far.
     * @return array The findings with the child tables added.
     */
    private static function scan_child_tables(\cm_info $cm, array $findings): array {
        $tables = scannable_fields::child_tables($cm->modname);
        foreach ($tables as $definition) {
            $findings = self::scan_child_table($definition, (int) $cm->instance, $findings);
        }
        return $findings;
    }

    /**
     * Scan every row of one child table that belongs to the module instance.
     *
     * @param array $definition {table, key, columns}, e.g. the lesson pages of a lesson.
     * @param int $instanceid The module instance id.
     * @param array $findings What has been found so far.
     * @return array The findings with this table added.
     */
    private static function scan_child_table(array $definition, int $instanceid, array $findings): array {
        global $DB;

        $table = $definition['table'];
        $manager = $DB->get_manager();
        $exists = $manager->table_exists($table);
        if (!$exists) {
            return $findings;
        }
        $known = $DB->get_columns($table);
        $columns = self::existing_columns($definition['columns'], $known);
        if ($columns === []) {
            return $findings;
        }
        $fields = 'id, ' . implode(', ', $columns);
        $rows = $DB->get_recordset($table, [$definition['key'] => $instanceid], 'id', $fields);
        foreach ($rows as $row) {
            $findings = self::scan_columns_of($row, $table, $columns, $findings);
        }
        $rows->close();
        return $findings;
    }

    /**
     * The wanted columns that the table really has, so an older table does not break the scan.
     *
     * @param string[] $wanted The columns to read, e.g. ['title', 'contents'].
     * @param array $known The columns of the table, keyed by name.
     * @return string[]
     */
    private static function existing_columns(array $wanted, array $known): array {
        $existing = [];
        foreach ($wanted as $column) {
            if (isset($known[$column])) {
                $existing[] = $column;
            }
        }
        return $existing;
    }

    /**
     * Scan one text and add what it holds to the findings.
     *
     * @param array $findings What has been found so far.
     * @param string $field Name of the field, e.g. 'page.content'.
     * @param int $recordid Id of the row the text is from.
     * @param string $text The html.
     * @return array The findings with this text added.
     */
    private static function add_text(array $findings, string $field, int $recordid, string $text): array {
        if ($text === '') {
            return $findings;
        }
        $scan = marker_scanner::scan($text);
        if ($scan->has_placeholders()) {
            $findings['fields'][] = ['field' => $field, 'recordid' => $recordid, 'placeholders' => $scan->placeholders];
        }
        foreach ($scan->problems as $problem) {
            $findings['problems'][] = $field . '#' . $recordid . ': ' . $problem;
        }
        $findings['hidden'] += $scan->hidden;
        return $findings;
    }
}
