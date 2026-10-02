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

namespace local_coursegen\local\preview;

/**
 * The check a finished activity passes before it is previewed.
 *
 * A preview is drawn from the finished activity alone: its tree of rows, with
 * what the AI wrote laid into the rows it came from. A result written before
 * the tree and the record ids travelled with it cannot be drawn, and drawing
 * it from anything else would be a guess, so it is refused with a message
 * that says what to do. A record that names a row the tree does not have is
 * refused too, naming the activity and the record.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_activity_check {
    /**
     * Refuse an activity that cannot be drawn from its own result.
     *
     * @param array $activity One of the result's generated activities.
     * @throws \moodle_exception When the result predates record ids or a record names an unknown row.
     */
    public static function assert_current(array $activity): void {
        $parameters = $activity['parameters'] ?? [];
        $parameters = (array) $parameters;
        $label = self::label_of($activity);

        $structure = $parameters['structure'] ?? [];
        if (!is_array($structure) || !$structure) {
            throw self::outdated($label);
        }

        $modsettings = $parameters['mod_settings'] ?? [];
        $modsettings = (array) $modsettings;
        $known = self::known_ids($parameters);
        foreach ($modsettings as $listname => $records) {
            self::assert_records($label, (string) $listname, $records, $known);
        }
    }

    /**
     * The refusal of a result written before the tree and the record ids travelled with it.
     *
     * @param string $label
     * @return \moodle_exception
     */
    private static function outdated(string $label): \moodle_exception {
        $a = (object) ['activity' => $label];
        return new \moodle_exception('courseai_preview_result_outdated', 'local_coursegen', '', $a);
    }

    /**
     * What the activity is called in a message: its name, else its uid.
     *
     * @param array $activity
     * @return string
     */
    private static function label_of(array $activity): string {
        $parameters = $activity['parameters'] ?? [];
        $name = $parameters['name'] ?? '';
        $name = (string) $name;
        if ($name !== '') {
            return $name;
        }
        $uid = $activity['uid'] ?? '';
        return (string) $uid;
    }

    /**
     * Every id the activity's own result holds a row for.
     *
     * The tree holds the rows of the module; the questions of a quiz travel
     * beside it, because a quiz's tree only points at the question bank.
     * What the AI wrote (mod_settings) is not a source of ids.
     *
     * @param array $parameters
     * @return array Id (string) => true.
     */
    private static function known_ids(array $parameters): array {
        $ids = [];
        $structure = $parameters['structure'] ?? [];
        self::collect_ids((array) $structure, $ids);
        $questions = $parameters['questions'] ?? [];
        self::collect_ids((array) $questions, $ids);
        return $ids;
    }

    /**
     * Add every id found anywhere in a nested array.
     *
     * @param array $node
     * @param array $ids Accumulator: id (string) => true.
     */
    private static function collect_ids(array $node, array &$ids): void {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::collect_ids($value, $ids);
                continue;
            }
            if ($key === 'id' && $value !== null && $value !== '') {
                $ids[(string) $value] = true;
            }
        }
    }

    /**
     * Check the records of one list the AI wrote.
     *
     * @param string $label The activity, for the message.
     * @param string $listname
     * @param mixed $records
     * @param array $known Id (string) => true.
     */
    private static function assert_records(string $label, string $listname, $records, array $known): void {
        if (!self::is_record_list($records)) {
            return;
        }
        foreach ($records as $record) {
            self::assert_record($label, $listname, $record, $known);
        }
    }

    /**
     * Whether a value is a non-empty list of records (arrays).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_record_list($value): bool {
        if (!is_array($value) || !$value || !array_is_list($value)) {
            return false;
        }
        $records = array_filter($value, 'is_array');
        return count($records) === count($value);
    }

    /**
     * Check one record: it says where it came from, and that row exists.
     *
     * @param string $label
     * @param string $listname
     * @param array $record
     * @param array $known Id (string) => true.
     */
    private static function assert_record(string $label, string $listname, array $record, array $known): void {
        if (!array_key_exists('source_id', $record)) {
            throw self::outdated($label);
        }
        $sourceid = $record['source_id'];
        if ($sourceid === null) {
            return;
        }
        $sourceid = (string) $sourceid;
        if (isset($known[$sourceid])) {
            return;
        }
        $where = (object) ['activity' => $label, 'record' => $listname . ': ' . $sourceid];
        throw new \moodle_exception('courseai_preview_record_unknown', 'local_coursegen', '', $where);
    }
}
