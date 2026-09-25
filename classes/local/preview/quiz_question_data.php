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
        $qtype = $form['qtype'] ?? '';
        $qtype = (string) $qtype;
        $editor = self::field_editor();

        $questiontextraw = $form['questiontext'] ?? '';
        $questiontext = $editor($questiontextraw);
        $generalfeedbackraw = $form['generalfeedback'] ?? '';
        $generalfeedback = $editor($generalfeedbackraw);

        $questionid = $id++;
        $name = $form['name'] ?? '';
        $name = (string) $name;
        $defaultmark = $form['defaultmark'] ?? 1;
        $defaultmark = (float) $defaultmark;
        $penalty = $form['penalty'] ?? 0.3333333;
        $penalty = (float) $penalty;
        $hints = self::build_hints($form, $id, $questionid, $editor);

        $data = [
            'id' => $questionid,
            'category' => 0,
            'parent' => 0,
            'name' => $name,
            'questiontext' => $questiontext['text'],
            'questiontextformat' => $questiontext['format'],
            'generalfeedback' => $generalfeedback['text'],
            'generalfeedbackformat' => $generalfeedback['format'],
            'defaultmark' => $defaultmark,
            'penalty' => $penalty,
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
            'hints' => $hints,
        ];

        $answers = self::build_answers($form, $id, $questionid, $editor);

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
     * An editor field's text and format, whether the form sent it as an
     * array (editor/textarea) or a plain string.
     *
     * @return callable
     */
    protected static function field_editor(): callable {
        return static function ($value): array {
            if (is_array($value)) {
                $text = $value['text'] ?? '';
                $text = (string) $text;
                $format = $value['format'] ?? FORMAT_HTML;
                $format = (int) $format;
                return ['text' => $text, 'format' => $format];
            }
            $text = (string) $value;
            return ['text' => $text, 'format' => FORMAT_HTML];
        };
    }

    /**
     * A drafted question's hint rows, in the shape the engine expects,
     * skipping a hint whose editor field was left empty.
     *
     * @param array $form
     * @param int $id
     * @param int $questionid
     * @param callable $editor
     * @return array
     */
    protected static function build_hints(array $form, int &$id, int $questionid, callable $editor): array {
        $hints = [];
        $rawhints = $form['hint'] ?? [];
        $rawhints = (array) $rawhints;
        foreach ($rawhints as $rawhint) {
            $hint = $editor($rawhint);
            if (trim($hint['text']) === '') {
                continue;
            }
            $hints[] = [
                'id' => $id++, 'questionid' => $questionid, 'hint' => $hint['text'], 'hintformat' => $hint['format'],
                'shownumcorrect' => 0, 'clearwrong' => 0, 'options' => null,
            ];
        }
        return $hints;
    }

    /**
     * A drafted question's answer rows, in the shape the engine expects.
     *
     * @param array $form
     * @param int $id
     * @param int $questionid
     * @param callable $editor
     * @return array
     */
    protected static function build_answers(array $form, int &$id, int $questionid, callable $editor): array {
        $answers = [];
        $answertexts = $form['answer'] ?? [];
        $answertexts = (array) $answertexts;
        $fractions = $form['fraction'] ?? [];
        $fractions = (array) $fractions;
        $feedbacks = $form['feedback'] ?? [];
        $feedbacks = (array) $feedbacks;
        foreach (array_values($answertexts) as $index => $rawanswer) {
            $answer = $editor($rawanswer);
            $rawfeedback = $feedbacks[$index] ?? '';
            $feedback = $editor($rawfeedback);
            $fraction = $fractions[$index] ?? 0;
            $fraction = (float) $fraction;
            $answers[$id] = [
                'id' => $id, 'question' => $questionid,
                'answer' => $answer['text'], 'answerformat' => $answer['format'],
                'fraction' => $fraction,
                'feedback' => $feedback['text'], 'feedbackformat' => $feedback['format'],
            ];
            $id++;
        }
        return $answers;
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
