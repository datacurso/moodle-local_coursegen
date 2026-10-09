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

namespace local_coursegen\local\files;

/**
 * The rows of the questions a quiz asks.
 *
 * A quiz's backup structure names its questions by a reference into the
 * question bank, which a backup carries separately, so the questions, their
 * answers and hints and the options of their type are not rows of the
 * structure. They are found here through the question bank's own tables, and
 * the file areas they keep their files in are the ones the question API
 * uses: the area of a question's text is named after the column that holds it
 * (the answers' feedback is the one name that differs, "answerfeedback"), and
 * is stored under the id of the question, the answer or the hint.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_question_carriers implements carrier_provider {
    #[\Override]
    public function carriers(\stdClass $activity): array {
        $carriers = [];
        $questions = $this->questions($activity);
        foreach ($questions as $question) {
            $found = $this->carriers_of_question($question);
            $carriers = array_merge($carriers, $found);
        }
        return $carriers;
    }

    /**
     * The questions the slots of a quiz refer to: id, qtype and the context the question bank keeps them in.
     *
     * @param \stdClass $activity
     * @return \stdClass[]
     */
    private function questions(\stdClass $activity): array {
        global $DB;

        $sql = "SELECT DISTINCT q.id, q.qtype, qc.contextid
                  FROM {quiz_slots} slot
                  JOIN {question_references} ref ON ref.itemid = slot.id
                       AND ref.component = 'mod_quiz' AND ref.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = ref.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE slot.quizid = :quizid
              ORDER BY q.id";
        return $DB->get_records_sql($sql, ['quizid' => $activity->instance]);
    }

    /**
     * Every row of one question that holds text.
     *
     * @param \stdClass $question
     * @return text_carrier[]
     */
    private function carriers_of_question(\stdClass $question): array {
        $contextid = (int) $question->contextid;
        $id = (int) $question->id;
        $own = [
            new file_area($contextid, 'question', 'questiontext', $id),
            new file_area($contextid, 'question', 'generalfeedback', $id),
        ];
        $carriers = [new text_carrier('question', $id, $own)];
        $answers = $this->answers($id, $contextid);
        $hints = $this->hints($id, $contextid);
        $options = $this->options($question, $contextid);
        return array_merge($carriers, $answers, $hints, $options);
    }

    /**
     * The answers of a question.
     *
     * @param int $questionid
     * @param int $contextid
     * @return text_carrier[]
     */
    private function answers(int $questionid, int $contextid): array {
        global $DB;

        $carriers = [];
        $ids = $DB->get_fieldset_select('question_answers', 'id', 'question = ?', [$questionid]);
        foreach ($ids as $id) {
            $areas = [
                new file_area($contextid, 'question', 'answer', (int) $id),
                new file_area($contextid, 'question', 'answerfeedback', (int) $id),
            ];
            $carriers[] = new text_carrier('question_answers', (int) $id, $areas);
        }
        return $carriers;
    }

    /**
     * The hints of a question.
     *
     * @param int $questionid
     * @param int $contextid
     * @return text_carrier[]
     */
    private function hints(int $questionid, int $contextid): array {
        global $DB;

        $carriers = [];
        $ids = $DB->get_fieldset_select('question_hints', 'id', 'questionid = ?', [$questionid]);
        foreach ($ids as $id) {
            $areas = [new file_area($contextid, 'question', 'hint', (int) $id)];
            $carriers[] = new text_carrier('question_hints', (int) $id, $areas);
        }
        return $carriers;
    }

    /**
     * The rows of the options of the question's type, whose texts (the feedback for a right or wrong answer, the
     * grader's notes) are stored under the id of the question.
     *
     * A question type keeps its options in tables named after it, "qtype_<name>_...", with the id of the question
     * in "questionid". A table that holds one row per question stores its texts under the question's id, in an
     * area named after the column; a table with several rows per question (the sub-questions of a matching
     * question) stores each under its own id and is not read.
     *
     * @param \stdClass $question
     * @param int $contextid
     * @return text_carrier[]
     */
    private function options(\stdClass $question, int $contextid): array {
        global $DB;

        $carriers = [];
        $tables = $this->option_tables((string) $question->qtype);
        foreach ($tables as $table => $columns) {
            $rows = $DB->get_records($table, ['questionid' => $question->id], '', 'id');
            if (count($rows) !== 1) {
                continue;
            }
            $row = reset($rows);
            $areas = $this->areas_named($columns, $contextid, (int) $question->id);
            $carriers[] = new text_carrier($table, (int) $row->id, $areas);
        }
        return $carriers;
    }

    /**
     * The tables a question type keeps its options in, with their text columns.
     *
     * @param string $qtype
     * @return string[][] Table => text columns.
     */
    private function option_tables(string $qtype): array {
        global $DB;

        $prefix = 'qtype_' . $qtype . '_';
        $tables = [];
        $existing = $DB->get_tables();
        foreach ($existing as $table) {
            if (!str_starts_with($table, $prefix)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            if (isset($columns['id']) && isset($columns['questionid'])) {
                $tables[$table] = $this->text_columns($columns);
            }
        }
        return $tables;
    }

    /**
     * The names, among column definitions, of those that hold text.
     *
     * @param \database_column_info[] $columns
     * @return string[]
     */
    private function text_columns(array $columns): array {
        $names = [];
        foreach ($columns as $name => $column) {
            if ($column->meta_type === 'X') {
                $names[] = (string) $name;
            }
        }
        return $names;
    }

    /**
     * An area per column name, all stored under one item id.
     *
     * @param string[] $names
     * @param int $contextid
     * @param int $itemid
     * @return file_area[]
     */
    private function areas_named(array $names, int $contextid, int $itemid): array {
        $areas = [];
        foreach ($names as $name) {
            $areas[] = new file_area($contextid, 'question', (string) $name, $itemid);
        }
        return $areas;
    }
}
