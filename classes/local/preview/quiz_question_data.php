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
 * Lays a drafted question's form fields out the way the question bank loads
 * a saved one, per type - the shape the question engine's own rendering
 * code expects. Kept apart from quiz_preview.php only because together they
 * crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_question_data {
    use quiz_question_options;

    /**
     * One question from its form fields to the bank's loaded shape.
     *
     * @param array $form
     * @param int $id Counter for the ids the engine wants every row to carry.
     * @param int $contextid The question's context.
     * @return array|null Null for a type this cannot lay out.
     */
    public static function from_form(array $form, int &$id, int $contextid): ?array {
        $qtype = (string) ($form['qtype'] ?? '');
        $editor = static function ($value): array {
            if (is_array($value)) {
                return ['text' => (string) ($value['text'] ?? ''), 'format' => (int) ($value['format'] ?? FORMAT_HTML)];
            }
            return ['text' => (string) $value, 'format' => FORMAT_HTML];
        };
        $questiontext = $editor($form['questiontext'] ?? '');
        $generalfeedback = $editor($form['generalfeedback'] ?? '');

        $questionid = $id++;
        $data = [
            'id' => $questionid,
            'category' => 0,
            'parent' => 0,
            'name' => (string) ($form['name'] ?? ''),
            'questiontext' => $questiontext['text'],
            'questiontextformat' => $questiontext['format'],
            'generalfeedback' => $generalfeedback['text'],
            'generalfeedbackformat' => $generalfeedback['format'],
            'defaultmark' => (float) ($form['defaultmark'] ?? 1),
            'penalty' => (float) ($form['penalty'] ?? 0.3333333),
            'qtype' => $qtype,
            'length' => 1,
            'stamp' => '',
            'timecreated' => 0,
            'timemodified' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'idnumber' => null,
            'contextid' => $contextid,
            'status' => 'ready',
            'versionid' => 0,
            'version' => 1,
            'questionbankentryid' => 0,
            'hints' => [],
        ];
        foreach ((array) ($form['hint'] ?? []) as $hint) {
            $hint = $editor($hint);
            if (trim($hint['text']) === '') {
                continue;
            }
            $data['hints'][] = [
                'id' => $id++, 'questionid' => $questionid, 'hint' => $hint['text'], 'hintformat' => $hint['format'],
                'shownumcorrect' => 0, 'clearwrong' => 0, 'options' => null,
            ];
        }

        $answers = [];
        $answertexts = (array) ($form['answer'] ?? []);
        $fractions = (array) ($form['fraction'] ?? []);
        $feedbacks = (array) ($form['feedback'] ?? []);
        foreach (array_values($answertexts) as $index => $answer) {
            $answer = $editor($answer);
            $feedback = $editor($feedbacks[$index] ?? '');
            $answers[$id] = [
                'id' => $id, 'question' => $questionid,
                'answer' => $answer['text'], 'answerformat' => $answer['format'],
                'fraction' => (float) ($fractions[$index] ?? 0),
                'feedback' => $feedback['text'], 'feedbackformat' => $feedback['format'],
            ];
            $id++;
        }

        $options = ['id' => $id++, 'questionid' => $questionid];
        $method = self::options_builder($qtype);
        if ($method !== null) {
            $options += self::$method($form, $answers, $questionid, $id, $editor);
        } else {
            // A type this does not know how to lay out is left to the
            // engine as it is; a type the engine cannot make is skipped
            // by it.
            $options += ['answers' => $answers];
        }
        $data['options'] = $options;
        return $data;
    }

    /**
     * Which method lays out a question type's options, mapped rather than
     * switched on.
     *
     * @param string $qtype
     * @return string|null Null for a type this class does not know how to lay out.
     */
    protected static function options_builder(string $qtype): ?string {
        $builders = [
            'multichoice' => 'options_for_multichoice',
            'truefalse' => 'options_for_truefalse',
            'shortanswer' => 'options_for_shortanswer',
            'numerical' => 'options_for_numerical',
            'essay' => 'options_for_essay',
        ];
        return $builders[$qtype] ?? null;
    }

}
