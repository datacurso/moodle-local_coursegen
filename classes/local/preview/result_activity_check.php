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
 * what the AI wrote laid into the rows it came from, and the questions of a
 * quiz, which a quiz's tree only points at. A result written before the tree
 * and the record ids travelled with it cannot be drawn, and drawing it from
 * anything else would be a guess, so it is refused with a message that says
 * what to do.
 *
 * Every record the AI wrote says which template record it came from
 * (source_id) and which row of the result it is (record_id). A record that
 * names a row the result does not hold, or one the tree holds as an element
 * that has no table to read it from, is refused too, naming the activity and
 * the record.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_activity_check {
    /** @var string What the activity is called in a message. */
    private string $label;

    /** @var array Id (string) => true, for every row the preview can read, every question and every file. */
    private array $known;

    /** @var array Id (string) => true, for every element the tree holds, with or without a table. */
    private array $treeids;

    /**
     * Constructor.
     *
     * @param string $label
     * @param array $known
     * @param array $treeids
     */
    private function __construct(string $label, array $known, array $treeids) {
        $this->label = $label;
        $this->known = $known;
        $this->treeids = $treeids;
    }

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

        $treeids = [];
        self::collect_ids($structure, $treeids);
        $check = new self($label, self::known_ids($parameters), $treeids);
        $check->assert_parameters($parameters);
    }

    /**
     * Check every list of records the AI wrote, and every list of ids that goes beside a list of texts.
     *
     * @param array $parameters
     */
    private function assert_parameters(array $parameters): void {
        $modsettings = $parameters['mod_settings'] ?? [];
        foreach ((array) $modsettings as $listname => $records) {
            $this->assert_records((string) $listname, $records);
        }
        foreach ($parameters as $name => $ids) {
            $this->assert_id_list((string) $name, $ids);
        }
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
     * Every id the result holds something to show for.
     *
     * The rows the preview builds from the tree, the questions of a quiz
     * (its tree only points at the question bank) and the file entries.
     * What the AI wrote (mod_settings) is not a source of ids.
     *
     * @param array $parameters
     * @return array Id (string) => true.
     */
    private static function known_ids(array $parameters): array {
        $store = json_store::from_parameters($parameters);
        $ids = $store->row_ids();
        $questions = $parameters['questions'] ?? [];
        self::collect_ids((array) $questions, $ids);
        $files = $parameters['files'] ?? [];
        self::collect_ids((array) $files, $ids);
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
     * @param string $listname
     * @param mixed $records
     */
    private function assert_records(string $listname, $records): void {
        if (!self::is_record_list($records)) {
            return;
        }
        foreach ($records as $record) {
            $this->assert_record($listname, $record);
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
     * Check one record: it says where it came from, and the rows it names exist.
     *
     * @param string $listname
     * @param array $record
     */
    private function assert_record(string $listname, array $record): void {
        if (!array_key_exists('source_id', $record)) {
            throw self::outdated($this->label);
        }
        $this->assert_known($listname, $record['source_id']);
        $recordid = $record['record_id'] ?? null;
        $this->assert_known($listname, $recordid);
    }

    /**
     * Check a list of ids that goes beside a list of texts (a choice's option_source_ids).
     *
     * @param string $name The key of the list in the parameters.
     * @param mixed $ids
     */
    private function assert_id_list(string $name, $ids): void {
        $isidlist = str_ends_with($name, '_source_ids') || str_ends_with($name, '_record_ids');
        if (!$isidlist || !is_array($ids)) {
            return;
        }
        foreach ($ids as $id) {
            $this->assert_known($name, $id);
        }
    }

    /**
     * Check that an id names something the result can show.
     *
     * @param string $listname Where the id was found, for the message.
     * @param mixed $id Null for a record the AI wrote with no template record.
     */
    private function assert_known(string $listname, $id): void {
        if ($id === null) {
            return;
        }
        $id = (string) $id;
        if (isset($this->known[$id])) {
            return;
        }
        $where = (object) ['activity' => $this->label, 'record' => $listname . ': ' . $id];
        if (isset($this->treeids[$id])) {
            throw new \moodle_exception('courseai_preview_record_without_row', 'local_coursegen', '', $where);
        }
        throw new \moodle_exception('courseai_preview_record_unknown', 'local_coursegen', '', $where);
    }
}
