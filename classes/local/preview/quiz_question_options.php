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
 * One method per question type, each laying its options out the way the
 * question bank loads a saved one. Kept apart from quiz_question_data.php
 * only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait quiz_question_options {
    /**
     * qtype_multichoice's saved-question shape.
     *
     * @param array $form
     * @param array $answers Already laid out from the form's answer/fraction/feedback lists.
     * @param int $questionid
     * @param int $id
     * @param callable $editor
     * @return array
     */
    protected static function options_for_multichoice(array $form, array $answers, int $questionid, int &$id, callable $editor): array {
        $options = [];
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
        return $options;
    }

    /**
     * qtype_truefalse's saved-question shape: its own true/false answer pair,
     * not the form's generic answer list.
     *
     * @param array $form
     * @param array $answers Unused; a true/false question answers itself.
     * @param int $questionid
     * @param int $id
     * @param callable $editor
     * @return array
     */
    protected static function options_for_truefalse(array $form, array $answers, int $questionid, int &$id, callable $editor): array {
        $correct = (int) ($form['correctanswer'] ?? 1);
        $true = $editor($form['feedbacktrue'] ?? '');
        $false = $editor($form['feedbackfalse'] ?? '');
        $trueid = $id++;
        $falseid = $id++;
        $ownanswers = [];
        $ownanswers[$trueid] = [
            'id' => $trueid, 'question' => $questionid, 'answer' => get_string('true', 'qtype_truefalse'),
            'answerformat' => FORMAT_MOODLE, 'fraction' => $correct ? 1.0 : 0.0,
            'feedback' => $true['text'], 'feedbackformat' => $true['format'],
        ];
        $ownanswers[$falseid] = [
            'id' => $falseid, 'question' => $questionid, 'answer' => get_string('false', 'qtype_truefalse'),
            'answerformat' => FORMAT_MOODLE, 'fraction' => $correct ? 0.0 : 1.0,
            'feedback' => $false['text'], 'feedbackformat' => $false['format'],
        ];
        return [
            'question' => $questionid, 'trueanswer' => $trueid, 'falseanswer' => $falseid,
            'showstandardinstruction' => (int) ($form['showstandardinstruction'] ?? 0),
            'answers' => $ownanswers,
        ];
    }

    /**
     * qtype_shortanswer's saved-question shape.
     *
     * @param array $form
     * @param array $answers
     * @param int $questionid
     * @param int $id
     * @param callable $editor
     * @return array
     */
    protected static function options_for_shortanswer(array $form, array $answers, int $questionid, int &$id, callable $editor): array {
        return ['usecase' => (int) ($form['usecase'] ?? 0), 'answers' => $answers];
    }

    /**
     * qtype_numerical's saved-question shape, with a tolerance laid onto each answer.
     *
     * @param array $form
     * @param array $answers
     * @param int $questionid
     * @param int $id
     * @param callable $editor
     * @return array
     */
    protected static function options_for_numerical(array $form, array $answers, int $questionid, int &$id, callable $editor): array {
        $options = ['answers' => $answers, 'units' => [], 'unitgradingtype' => 0, 'unitpenalty' => 0.1,
            'showunits' => 3, 'unitsleft' => 0];
        $tolerances = (array) ($form['tolerance'] ?? []);
        $index = 0;
        foreach ($options['answers'] as $answerid => $answer) {
            $options['answers'][$answerid]['tolerance'] = (float) ($tolerances[$index] ?? 0);
            $index++;
        }
        return $options;
    }

    /**
     * qtype_essay's saved-question shape.
     *
     * @param array $form
     * @param array $answers Unused; an essay question has none.
     * @param int $questionid
     * @param int $id
     * @param callable $editor
     * @return array
     */
    protected static function options_for_essay(array $form, array $answers, int $questionid, int &$id, callable $editor): array {
        $graderinfo = $editor($form['graderinfo'] ?? '');
        $template = $editor($form['responsetemplate'] ?? '');
        return [
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
    }
}
