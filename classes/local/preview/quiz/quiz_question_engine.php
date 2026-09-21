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

namespace local_coursegen\local\preview\quiz;

use html_writer;
use question_bank;
use question_display_options;
use question_engine;
use stdClass;

/**
 * The questions, drawn by the question engine the way an attempt draws them,
 * kept apart from view.php only because together they crossed the 250-line
 * cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait quiz_question_engine {
    /**
     * The questions, drawn by the question engine the way an attempt draws them.
     *
     * An attempt is a usage of questions, and the engine builds one in memory:
     * each question is made from its data, added at its mark, and started, and
     * the engine renders it. Nothing is saved, which is the one thing an
     * attempt does that a preview must not. What is shown is the question as
     * a reader meets it: its text and its controls, with no feedback and no
     * right answer, because nothing has been answered.
     *
     * @return string
     */
    protected function questions(): string {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $quba = question_engine::make_questions_usage_by_activity('local_coursegen', $this->context);
        $quba->set_preferred_behaviour($this->quiz->preferredbehaviour ?: 'deferredfeedback');

        $numbers = [];
        foreach ($this->slots as $slot) {
            if (empty($slot['question'])) {
                // A slot filled at random from a category names no one
                // question, and cannot be drawn as one.
                continue;
            }
            $question = question_bank::make_question(self::question_data($slot['question']));
            $number = $quba->add_question($question, (float) ($slot['maxmark'] ?? $question->defaultmark));
            $numbers[$number] = $slot['displaynumber'] ?? null;
        }
        if (!$quba->get_slots()) {
            return '';
        }
        $quba->start_all_questions();

        $options = new question_display_options();
        $options->flags = question_display_options::HIDDEN;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->markdp = $this->quiz_get_grade_format();
        $options->feedback = question_display_options::HIDDEN;
        $options->generalfeedback = question_display_options::HIDDEN;
        $options->rightanswer = question_display_options::HIDDEN;
        $options->correctness = question_display_options::HIDDEN;
        $options->numpartscorrect = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::HIDDEN;
        $options->history = question_display_options::HIDDEN;
        $options->context = $this->context;

        // The attempt page prints its questions inside the form that submits
        // them, and that is the markup the questions' own scripts expect.
        $output = html_writer::start_tag('form', [
            'action' => $this->here, 'method' => 'post', 'enctype' => 'multipart/form-data',
            'accept-charset' => 'utf-8', 'id' => 'responseform',
        ]);
        $output .= html_writer::start_tag('div');
        $index = 0;
        foreach ($quba->get_slots() as $slot) {
            $index++;
            $displaynumber = $numbers[$slot] ?? null;
            $output .= $quba->render_question($slot, $options, $displaynumber !== null && $displaynumber !== '' ? $displaynumber : $index);
        }
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('form');
        return $output;
    }

    /**
     * A question's data as the engine expects it, from how the payload carries it.
     *
     * The payload carries the question as the bank loaded it, made into plain
     * arrays. The engine wants that back as objects, except that the answers
     * and hints are lists a question type reads by key, so those stay arrays,
     * of objects.
     *
     * @param array $data
     * @return stdClass
     */
    public static function question_data(array $data): stdClass {
        return self::objectify($data);
    }

    /**
     * Arrays into objects, keeping the lists a question type indexes into.
     *
     * @param mixed $value
     * @param string $key The key this value sits under, to know a list by.
     * @return mixed
     */
    protected static function objectify($value, string $key = '') {
        if (!is_array($value)) {
            return $value;
        }
        $islist = array_keys($value) === range(0, count($value) - 1);
        if ($islist || in_array($key, ['answers', 'hints'], true)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::objectify($v, (string) $k);
            }
            return $out;
        }
        $object = new stdClass();
        foreach ($value as $k => $v) {
            $object->$k = self::objectify($v, (string) $k);
        }
        return $object;
    }
}
