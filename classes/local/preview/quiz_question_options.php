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
            $fieldvalue = $form[$field] ?? '';
            $text = $editor($fieldvalue);
            $options[$field] = $text['text'];
            $options[$field . 'format'] = $text['format'];
        }

        $single = $form['single'] ?? 1;
        $single = (int) $single;
        $shuffleanswers = $form['shuffleanswers'] ?? 1;
        $shuffleanswers = (int) $shuffleanswers;
        $answernumbering = $form['answernumbering'] ?? 'abc';
        $answernumbering = (string) $answernumbering;
        $shownumcorrect = $form['shownumcorrect'] ?? 0;
        $shownumcorrect = (int) $shownumcorrect;
        $showstandardinstruction = $form['showstandardinstruction'] ?? 0;
        $showstandardinstruction = (int) $showstandardinstruction;

        $options += [
            'layout' => 0,
            'single' => $single,
            'shuffleanswers' => $shuffleanswers,
            'answernumbering' => $answernumbering,
            'shownumcorrect' => $shownumcorrect,
            'showstandardinstruction' => $showstandardinstruction,
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
        $correct = $form['correctanswer'] ?? 1;
        $correct = (int) $correct;
        $truefeedback = $form['feedbacktrue'] ?? '';
        $true = $editor($truefeedback);
        $falsefeedback = $form['feedbackfalse'] ?? '';
        $false = $editor($falsefeedback);
        $trueid = $id++;
        $falseid = $id++;

        $truefraction = 0.0;
        if ($correct) {
            $truefraction = 1.0;
        }
        $falsefraction = 1.0;
        if ($correct) {
            $falsefraction = 0.0;
        }

        $ownanswers = [];
        $ownanswers[$trueid] = [
            'id' => $trueid, 'question' => $questionid, 'answer' => get_string('true', 'qtype_truefalse'),
            'answerformat' => FORMAT_MOODLE, 'fraction' => $truefraction,
            'feedback' => $true['text'], 'feedbackformat' => $true['format'],
        ];
        $ownanswers[$falseid] = [
            'id' => $falseid, 'question' => $questionid, 'answer' => get_string('false', 'qtype_truefalse'),
            'answerformat' => FORMAT_MOODLE, 'fraction' => $falsefraction,
            'feedback' => $false['text'], 'feedbackformat' => $false['format'],
        ];

        $showstandardinstruction = $form['showstandardinstruction'] ?? 0;
        $showstandardinstruction = (int) $showstandardinstruction;
        return [
            'question' => $questionid, 'trueanswer' => $trueid, 'falseanswer' => $falseid,
            'showstandardinstruction' => $showstandardinstruction,
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
        $usecase = $form['usecase'] ?? 0;
        $usecase = (int) $usecase;
        return ['usecase' => $usecase, 'answers' => $answers];
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
        $tolerances = $form['tolerance'] ?? [];
        $tolerances = (array) $tolerances;
        $index = 0;
        foreach ($options['answers'] as $answerid => $answer) {
            $tolerance = $tolerances[$index] ?? 0;
            $tolerance = (float) $tolerance;
            $options['answers'][$answerid]['tolerance'] = $tolerance;
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
        $graderinfotext = $form['graderinfo'] ?? '';
        $graderinfo = $editor($graderinfotext);
        $templatetext = $form['responsetemplate'] ?? '';
        $template = $editor($templatetext);

        $responseformat = $form['responseformat'] ?? 'editor';
        $responseformat = (string) $responseformat;
        $responserequired = $form['responserequired'] ?? 1;
        $responserequired = (int) $responserequired;
        $responsefieldlines = $form['responsefieldlines'] ?? 15;
        $responsefieldlines = (int) $responsefieldlines;
        $attachments = $form['attachments'] ?? 0;
        $attachments = (int) $attachments;
        $attachmentsrequired = $form['attachmentsrequired'] ?? 0;
        $attachmentsrequired = (int) $attachmentsrequired;
        $maxbytes = $form['maxbytes'] ?? 0;
        $maxbytes = (int) $maxbytes;
        $filetypeslist = $form['filetypeslist'] ?? '';
        $filetypeslist = (string) $filetypeslist;

        return [
            'responseformat' => $responseformat,
            'responserequired' => $responserequired,
            'responsefieldlines' => $responsefieldlines,
            'minwordlimit' => $form['minwordlimit'] ?? null,
            'maxwordlimit' => $form['maxwordlimit'] ?? null,
            'attachments' => $attachments,
            'attachmentsrequired' => $attachmentsrequired,
            'graderinfo' => $graderinfo['text'], 'graderinfoformat' => $graderinfo['format'],
            'responsetemplate' => $template['text'], 'responsetemplateformat' => $template['format'],
            'maxbytes' => $maxbytes,
            'filetypeslist' => $filetypeslist,
            'answers' => [],
        ];
    }
}
