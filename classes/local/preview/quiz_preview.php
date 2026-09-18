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

use local_coursegen\local\preview\quiz\view;

/**
 * A quiz, drawn by mod_quiz's own view code and the question engine, against the payload.
 *
 * A kept quiz brings its questions with it, each as the question bank loaded
 * it, and the engine draws them from that. A quiz the run writes brings the
 * questions the answer wrote, in the shape a question's editing form submits
 * them in, which is the shape the plugin creates them from; those are put into
 * the shape the engine builds a question from, and then drawn the same way.
 * The drawing is the engine's in both cases; only the data changes hands.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_preview extends ported_preview {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'quiz';
    }

    /**
     * The quiz page, as mod/quiz/view.php draws it, followed by its questions.
     *
     * @return string
     */
    public function render(): string {
        $quiz = $this->instance();
        if ($quiz === null) {
            return $this->nothing_yet();
        }
        $view = new view($quiz, $this->cm(), $this->course(), $this->context(), $this->slots(), $this->url_to());
        return $view->page();
    }

    /**
     * The slots, each with its question's data, from whichever side wrote them.
     *
     * @return array
     */
    protected function slots(): array {
        $drafted = $this->parameters['mod_settings']['questions'] ?? null;
        $ismould = (string) ($this->source['uid'] ?? '') === (string) $this->here->get_param('uid');
        if (!$ismould && is_array($drafted) && $drafted) {
            return $this->slots_from_draft($drafted);
        }
        return (array) (($this->source['parameters'] ?? [])['questions'] ?? []);
    }

    /**
     * The answer's questions as slots, their data in the engine's shape.
     *
     * The answer writes a question the way its editing form submits it; the
     * plugin creates the question from exactly that, through the question
     * type's own save_question(). Here the same fields are laid out the way
     * the bank loads a saved question back, which is what the engine builds a
     * question from. Answers and hints are numbered from one, and the marks
     * that decide which answers are right are the fractions the form carries.
     *
     * @param array $drafted
     * @return array
     */
    protected function slots_from_draft(array $drafted): array {
        $slots = [];
        $slotnumber = 0;
        $id = 1;
        foreach ($drafted as $question) {
            $question = (array) $question;
            $slotnumber++;
            $data = $this->question_data_from_form($question, $id);
            $slots[] = [
                'slot' => $slotnumber,
                'page' => $slotnumber,
                'maxmark' => (float) ($question['defaultmark'] ?? 1),
                'displaynumber' => null,
                'question' => $data,
            ];
        }
        return $slots;
    }

    /**
     * One question from its form fields to the bank's loaded shape.
     *
     * @param array $form
     * @param int $id Counter for the ids the engine wants every row to carry.
     * @return array|null Null for a type this cannot lay out.
     */
    protected function question_data_from_form(array $form, int &$id): ?array {
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
            'contextid' => $this->context()->id,
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
        switch ($qtype) {
            case 'multichoice':
                foreach (['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'] as $field) {
                    $text = $editor($form[$field] ?? '');
                    $options[$field] = $text['text'];
                    $options[$field . 'format'] = $text['format'];
                }
                $options += [
                    'layout' => 0,
                    'single' => (int) ($form['single'] ?? 1),
                    'shuffleanswers' => (int) ($form['shuffleanswers'] ?? 1),
                    'answernumbering' => (string) ($form['answernumbering'] ?? 'abc'),
                    'shownumcorrect' => (int) ($form['shownumcorrect'] ?? 0),
                    'showstandardinstruction' => (int) ($form['showstandardinstruction'] ?? 0),
                    'answers' => $answers,
                ];
                break;
            case 'truefalse':
                $correct = (int) ($form['correctanswer'] ?? 1);
                $true = $editor($form['feedbacktrue'] ?? '');
                $false = $editor($form['feedbackfalse'] ?? '');
                $answers = [];
                $trueid = $id++;
                $falseid = $id++;
                $answers[$trueid] = [
                    'id' => $trueid, 'question' => $questionid, 'answer' => get_string('true', 'qtype_truefalse'),
                    'answerformat' => FORMAT_MOODLE, 'fraction' => $correct ? 1.0 : 0.0,
                    'feedback' => $true['text'], 'feedbackformat' => $true['format'],
                ];
                $answers[$falseid] = [
                    'id' => $falseid, 'question' => $questionid, 'answer' => get_string('false', 'qtype_truefalse'),
                    'answerformat' => FORMAT_MOODLE, 'fraction' => $correct ? 0.0 : 1.0,
                    'feedback' => $false['text'], 'feedbackformat' => $false['format'],
                ];
                $options += [
                    'question' => $questionid, 'trueanswer' => $trueid, 'falseanswer' => $falseid,
                    'showstandardinstruction' => (int) ($form['showstandardinstruction'] ?? 0),
                    'answers' => $answers,
                ];
                break;
            case 'shortanswer':
                $options += ['usecase' => (int) ($form['usecase'] ?? 0), 'answers' => $answers];
                break;
            case 'numerical':
                $options += ['answers' => $answers, 'units' => [], 'unitgradingtype' => 0, 'unitpenalty' => 0.1,
                    'showunits' => 3, 'unitsleft' => 0];
                $tolerances = (array) ($form['tolerance'] ?? []);
                $index = 0;
                foreach ($options['answers'] as $answerid => $answer) {
                    $options['answers'][$answerid]['tolerance'] = (float) ($tolerances[$index] ?? 0);
                    $index++;
                }
                break;
            case 'essay':
                $graderinfo = $editor($form['graderinfo'] ?? '');
                $template = $editor($form['responsetemplate'] ?? '');
                $options += [
                    'responseformat' => (string) ($form['responseformat'] ?? 'editor'),
                    'responserequired' => (int) ($form['responserequired'] ?? 1),
                    'responsefieldlines' => (int) ($form['responsefieldlines'] ?? 15),
                    'minwordlimit' => $form['minwordlimit'] ?? null,
                    'maxwordlimit' => $form['maxwordlimit'] ?? null,
                    'attachments' => (int) ($form['attachments'] ?? 0),
                    'attachmentsrequired' => (int) ($form['attachmentsrequired'] ?? 0),
                    'graderinfo' => $graderinfo['text'], 'graderinfoformat' => $graderinfo['format'],
                    'responsetemplate' => $template['text'], 'responsetemplateformat' => $template['format'],
                    'maxbytes' => (int) ($form['maxbytes'] ?? 0),
                    'filetypeslist' => (string) ($form['filetypeslist'] ?? ''),
                    'answers' => [],
                ];
                break;
            default:
                // A type this does not know how to lay out is left to the
                // engine as it is; a type the engine cannot make is skipped
                // by it.
                $options += ['answers' => $answers];
                break;
        }
        $data['options'] = $options;
        return $data;
    }

    /**
     * This module reads its own page at the narrower width (mod/quiz/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
