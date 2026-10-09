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

namespace local_coursegen\tests\fixtures;

/**
 * The scenarios of the modules built from assessments and structured settings: see file_scenarios.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_scenarios_more {
    /**
     * Every scenario of this set.
     *
     * @return array[]
     */
    public static function all(): array {
        return [
            self::quiz(), self::assign(), self::feedback(), self::workshop(), self::choice(), self::data(),
        ];
    }

    /**
     * The questions of a quiz: the rows of a table through the questions its slots refer to.
     *
     * @param string $table
     * @param string $column The column of the table that holds the id of the question.
     * @return callable
     */
    private static function question_rows(string $table, string $column): callable {
        return file_scenarios::rows_of(
            "SELECT x.* FROM {" . $table . "} x WHERE x." . $column . " IN (
                SELECT qv.questionid FROM {quiz_slots} sl
                  JOIN {question_references} r ON r.itemid = sl.id AND r.component = 'mod_quiz' AND r.questionarea = 'slot'
                  JOIN {question_versions} qv ON qv.questionbankentryid = r.questionbankentryid
                 WHERE sl.quizid = ?) ORDER BY x.id"
        );
    }

    /**
     * A quiz with a multiple choice question.
     *
     * @return array
     */
    private static function quiz(): array {
        $ed = [file_scenarios::class, 'editor'];
        $build = static function (array $t) use ($ed): array {
            $parameters = file_scenarios::base_params('quiz', [
                'grade' => 10, 'sumgrades' => 1, 'quizpassword' => '', 'timelimit' => 0, 'attempts' => 0,
                'questionsperpage' => 1, 'grademethod' => 1, 'navmethod' => 'free', 'preferredbehaviour' => 'deferredfeedback',
            ], $t['intro']);
            $question = [
                'qtype' => 'multichoice', 'name' => 'MC', 'questiontext' => $ed($t['questiontext']),
                'generalfeedback' => $ed($t['generalfeedback']), 'defaultmark' => 1, 'penalty' => 0.3333333,
                'single' => 1, 'shuffleanswers' => 1, 'answernumbering' => 'abc', 'showstandardinstruction' => 0,
                'correctfeedback' => $ed($t['correctfeedback']), 'partiallycorrectfeedback' => $ed('p'),
                'incorrectfeedback' => $ed('i'), 'answer' => [$ed($t['answer']), $ed('Other')], 'fraction' => [1, 0],
                'feedback' => [$ed($t['answerfeedback']), $ed('no')],
            ];
            return file_scenarios::result('quiz', $parameters, ['questions' => [$question]]);
        };
        $questions = file_scenarios::rows_of(
            "SELECT q.* FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_references} r ON r.questionbankentryid = qv.questionbankentryid
                    AND r.component = 'mod_quiz' AND r.questionarea = 'slot'
               JOIN {quiz_slots} sl ON sl.id = r.itemid
              WHERE sl.quizid = ? ORDER BY q.id"
        );
        $answers = self::question_rows('question_answers', 'question');
        $options = self::question_rows('qtype_multichoice_options', 'questionid');
        return ['module' => 'quiz', 'build' => $build, 'slots' => [
            'intro' => file_scenarios::intro('quiz'),
            'questiontext' => file_scenarios::slot('question', 'questiontext', $questions, 'question/questiontext', 'row'),
            'generalfeedback' => file_scenarios::slot('question', 'generalfeedback', $questions, 'question/generalfeedback', 'row'),
            'answer' => file_scenarios::slot('question_answers', 'answer', $answers, 'question/answer', 'row'),
            'answerfeedback' => file_scenarios::slot('question_answers', 'feedback', $answers, 'question/answerfeedback', 'row'),
            'correctfeedback' => file_scenarios::slot(
                'qtype_multichoice_options',
                'correctfeedback',
                $options,
                'question/correctfeedback',
                'qid'
            ),
        ]];
    }

    /**
     * An assignment with activity instructions.
     *
     * @return array
     */
    private static function assign(): array {
        $build = static function (array $t): array {
            $parameters = file_scenarios::base_params('assign', [
                'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
                'sendlatenotifications' => 0, 'duedate' => 0, 'cutoffdate' => 0, 'gradingduedate' => 0,
                'allowsubmissionsfromdate' => 0, 'grade' => 100, 'teamsubmission' => 0, 'attemptreopenmethod' => 'none',
                'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0,
                'assignsubmission_onlinetext_enabled' => 1, 'assignfeedback_comments_enabled' => 1,
                'requireallteammemberssubmit' => 0, 'preventsubmissionnotingroup' => 0, 'hidegrader' => 0,
                'blindmarking' => 0, 'sendstudentnotifications' => 1, 'activityeditor' => file_scenarios::editor($t['activity']),
            ], $t['intro']);
            return file_scenarios::result('assign', $parameters);
        };
        return ['module' => 'assign', 'build' => $build, 'slots' => [
            'intro' => file_scenarios::intro('assign'),
            'activity' => file_scenarios::slot(
                'assign',
                'activity',
                file_scenarios::root('assign'),
                'mod_assign/activityattachment'
            ),
        ]];
    }

    /**
     * A feedback with a label item and a final page.
     *
     * @return array
     */
    private static function feedback(): array {
        $build = static function (array $t): array {
            $parameters = file_scenarios::base_params('feedback', [
                'anonymous' => 1, 'page_after_submit' => '', 'multiple_submit' => 0, 'autonumbering' => 1,
                'page_after_submit_editor' => file_scenarios::editor($t['finalpage']),
            ], $t['intro']);
            $item = [
                'typ' => 'label', 'name' => 'Label', 'presentation_editor' => file_scenarios::editor($t['item']), 'required' => 0,
            ];
            return file_scenarios::result('feedback', $parameters, ['questions' => [$item]]);
        };
        $items = file_scenarios::rows_by('feedback_item', 'feedback');
        return ['module' => 'feedback', 'build' => $build, 'slots' => [
            'intro' => file_scenarios::intro('feedback'),
            'finalpage' => file_scenarios::slot(
                'feedback',
                'page_after_submit',
                file_scenarios::root('feedback'),
                'mod_feedback/page_after_submit'
            ),
            'item' => file_scenarios::slot('feedback_item', 'presentation', $items, 'mod_feedback/item', 'row'),
        ]];
    }

    /**
     * A workshop with instructions, a conclusion and an assessment criterion.
     *
     * @return array
     */
    private static function workshop(): array {
        $build = static function (array $t): array {
            $parameters = file_scenarios::base_params('workshop', [
                'grade' => 80, 'gradinggrade' => 20, 'strategy' => 'accumulative', 'submissiontypetext' => 1,
                'submissiontypefile' => 0, 'nattachments' => 1, 'maxbytes' => 0, 'overallfeedbackmode' => 0,
                'overallfeedbackfiles' => 0, 'usepeerassessment' => 1, 'useselfassessment' => 0, 'numexamples' => 0,
                'examplesmode' => 0, 'evaluation' => 'best', 'gradedecimals' => 0, 'latesubmissions' => 0,
                'instructauthorseditor' => file_scenarios::editor($t['instructauthors']),
                'instructreviewerseditor' => file_scenarios::editor($t['instructreviewers']),
                'conclusioneditor' => file_scenarios::editor($t['conclusion']),
            ], $t['intro']);
            return file_scenarios::result('workshop', $parameters, [
                'criteria' => [['description' => $t['criterion'], 'max_points' => 10]],
            ]);
        };
        $root = file_scenarios::root('workshop');
        $criteria = file_scenarios::rows_by('workshopform_accumulative', 'workshopid');
        return ['module' => 'workshop', 'build' => $build, 'slots' => [
            'intro' => file_scenarios::intro('workshop'),
            'instructauthors' => file_scenarios::slot('workshop', 'instructauthors', $root, 'mod_workshop/instructauthors'),
            'instructreviewers' => file_scenarios::slot('workshop', 'instructreviewers', $root, 'mod_workshop/instructreviewers'),
            'conclusion' => file_scenarios::slot('workshop', 'conclusion', $root, 'mod_workshop/conclusion'),
            'criterion' => file_scenarios::slot(
                'workshopform_accumulative',
                'description',
                $criteria,
                'workshopform_accumulative/description',
                'row'
            ),
        ]];
    }

    /**
     * A choice.
     *
     * @return array
     */
    private static function choice(): array {
        $build = static function (array $t): array {
            $parameters = file_scenarios::base_params('choice', [
                'option' => ['A', 'B'], 'limitanswers' => 0, 'showresults' => 1, 'publish' => 0, 'allowupdate' => 0,
                'allowmultiple' => 0, 'display' => 0,
            ], $t['intro']);
            return file_scenarios::result('choice', $parameters);
        };
        return ['module' => 'choice', 'build' => $build, 'slots' => ['intro' => file_scenarios::intro('choice')]];
    }

    /**
     * A database with a template, which the module shows as written, and an example entry.
     *
     * @return array
     */
    private static function data(): array {
        $build = static function (array $t): array {
            $parameters = file_scenarios::base_params('data', [
                'approval' => 0, 'comments' => 0, 'requiredentries' => 0, 'requiredentriestoview' => 0, 'maxentries' => 0,
                'rssarticles' => 0, 'manageapproved' => 1,
            ], $t['intro']);
            return file_scenarios::result('data', $parameters, [
                'fields' => [['type' => 'textarea', 'name' => 'Body']],
                'templates' => ['singletemplate' => $t['template']],
                'example_entries' => [['values' => [['field_name' => 'Body', 'value' => $t['entry']]]]],
            ]);
        };
        $contents = file_scenarios::rows_of(
            'SELECT c.* FROM {data_content} c JOIN {data_records} r ON r.id = c.recordid WHERE r.dataid = ? ORDER BY c.id'
        );
        return ['module' => 'data', 'build' => $build, 'slots' => [
            'intro' => file_scenarios::intro('data'),
            'template' => file_scenarios::slot(
                'data',
                'singletemplate',
                file_scenarios::root('data'),
                'mod_data/intro',
                0,
                0,
                true
            ),
            'entry' => file_scenarios::slot('data_content', 'content', $contents, 'mod_data/content', 'row'),
        ]];
    }
}
