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

namespace local_coursegen\local\service;

use cm_info;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mold is the case that actually matters here: the AI reproduces its
 * structure page by page, so a lesson's real pages (title + content_html,
 * markers and all) must travel intact under mod_settings.pages - the shape
 * course_ai's _mold_lesson_pages() reads. Every other module type only needs
 * enough to identify and place it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        if ($cm->modname === 'lesson') {
            return self::lesson_parameters($cm);
        }
        if ($cm->modname === 'url') {
            return self::url_parameters($cm);
        }
        if ($cm->modname === 'resource') {
            return self::resource_parameters($cm);
        }
        if ($cm->modname === 'forum') {
            return self::forum_parameters($cm);
        }
        if ($cm->modname === 'book') {
            return self::book_parameters($cm);
        }
        if ($cm->modname === 'wiki') {
            return self::wiki_parameters($cm);
        }
        if ($cm->modname === 'data') {
            return self::data_parameters($cm);
        }
        if ($cm->modname === 'quiz') {
            return self::quiz_parameters($cm);
        }
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
        ];
    }

    /** @var string[] Every quiz instance setting worth reproducing on the generated activity. */
    private const QUIZ_SETTINGS_COLUMNS = [
        'timeopen', 'timeclose', 'timelimit', 'overduehandling', 'graceperiod',
        'attempts', 'attemptonlast', 'delay1', 'delay2',
        'grademethod', 'grade', 'decimalpoints', 'questiondecimalpoints',
        'preferredbehaviour', 'canredoquestions', 'shuffleanswers', 'questionsperpage',
        'navmethod', 'showuserpicture', 'showblocks',
        'password', 'subnet', 'browsersecurity',
        'completionattemptsexhausted', 'completionminattempts', 'allowofflineattempts',
    ];

    /** @var string[] The eight review bitmask columns, named by the form prefix each one packs. */
    private const QUIZ_REVIEW_FIELDS = [
        'attempt', 'correctness', 'maxmarks', 'marks',
        'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback',
    ];

    /** @var array<string, int> The four review times, named as quiz_process_options() reads them. */
    private const QUIZ_REVIEW_TIMES = [
        'during' => display_options::DURING,
        'immediately' => display_options::IMMEDIATELY_AFTER,
        'open' => display_options::LATER_WHILE_OPEN,
        'closed' => display_options::AFTER_CLOSE,
    ];

    /** @var string[] The question types the AI service has a schema for; any other one is skipped. */
    private const QUIZ_SUPPORTED_QTYPES = [
        'multichoice', 'truefalse', 'shortanswer', 'numerical', 'essay',
        'description', 'gapselect', 'calculated', 'calculatedmulti',
    ];

    /** @var string[] The combined-feedback trio, shared by several question types. */
    private const QUIZ_COMBINED_FEEDBACK = [
        'correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback',
    ];

    /**
     * A Quiz's raw description, every instance setting and its questions.
     *
     * Three traps of mod_quiz's schema shape this branch:
     *
     * - gradepass is NOT a quiz column. It lives in grade_items, which is where
     *   mod/quiz/view.php reads the pass mark from, so it travels through the
     *   grades API; read off the quiz row it would always have been lost.
     * - The eight review* columns are BITMASKS and add_moduleinfo() cannot
     *   consume them: quiz_process_options() rebuilds each one out of four
     *   <field><whenname> checkboxes. They therefore travel decoded into those
     *   32 booleans, never as the packed integer.
     * - password is the column, but the form field is quizpassword and
     *   quiz_process_options() copies the latter onto the former. Both names
     *   travel, or add_moduleinfo() would read an undefined property.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function quiz_parameters(cm_info $cm): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        if (!$quiz) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = array_merge(
            self::quiz_settings_columns($quiz),
            self::quiz_review_options($quiz),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $quiz->intro ?? '',
                'quizpassword' => (string) ($quiz->password ?? ''),
                'gradepass' => self::quiz_grade_pass($cm),
            ]
        );

        $questions = self::quiz_questions($cm);
        if ($questions) {
            $parameters['mod_settings'] = ['questions' => $questions];
        }

        return $parameters;
    }

    /**
     * The mod_quiz settings worth reproducing on the generated activity.
     *
     * Identity and derived columns (id, course, sumgrades, timecreated,
     * timemodified, introformat) are left out on purpose - they describe THIS
     * quiz, never the new one.
     *
     * @param \stdClass $quiz
     * @return array
     */
    private static function quiz_settings_columns($quiz): array {
        $settings = [];
        foreach (self::QUIZ_SETTINGS_COLUMNS as $field) {
            if (isset($quiz->$field)) {
                $settings[$field] = $quiz->$field;
            }
        }
        return $settings;
    }

    /**
     * The mold's review settings, decoded into the 32 booleans the form owns.
     *
     * Core forces two invariants of its own on the way back in (reviewattempt
     * always gets DURING, reviewoverallfeedback never keeps it), so this
     * reports each bit exactly as stored instead of fighting them.
     *
     * @param \stdClass $quiz
     * @return array<string, int>
     */
    private static function quiz_review_options($quiz): array {
        $options = [];
        foreach (self::QUIZ_REVIEW_FIELDS as $field) {
            $bitmask = (int) ($quiz->{'review' . $field} ?? 0);
            foreach (self::QUIZ_REVIEW_TIMES as $whenname => $when) {
                $options[$field . $whenname] = ($bitmask & $when) ? 1 : 0;
            }
        }
        return $options;
    }

    /**
     * The mold's grade to pass, or 0.0 when it has none.
     *
     * @param cm_info $cm
     * @return float
     */
    private static function quiz_grade_pass(cm_info $cm): float {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => (int) $cm->instance,
            'itemnumber' => 0,
            'courseid' => (int) $cm->course,
        ]);

        return $item ? (float) $item->gradepass : 0.0;
    }

    /**
     * Every question of one quiz, in slot order, page and mark included.
     *
     * The supported API resolves the whole quiz_slots -> question_references ->
     * question_bank_entries -> question_versions -> question chain and picks
     * the right version, which hand-written SQL over quiz_slots cannot.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function quiz_questions(cm_info $cm): array {
        $structure = qbank_helper::get_question_structure((int) $cm->instance, $cm->context);

        $questions = [];
        foreach ($structure as $slot) {
            $question = self::quiz_question_parameters($slot);
            if ($question !== null) {
                $questions[] = $question;
            }
        }
        return $questions;
    }

    /**
     * One slot's question, or null when this slot cannot be reproduced.
     *
     * Two kinds of slot are skipped without a word, because neither is a
     * payload defect:
     *
     * - 'random' is a question_set_reference, not a question. The consumer
     *   builds slots with quiz_add_quiz_question(), which throws outright on
     *   random questions, so there is nothing to send.
     * - 'missingtype' is the placeholder the API puts in when the question
     *   itself is gone, or its qtype plugin is uninstalled: there is no
     *   definition left to reproduce.
     *
     * @param \stdClass $slot One row of qbank_helper::get_question_structure().
     * @return array|null
     */
    private static function quiz_question_parameters($slot): ?array {
        $qtype = (string) ($slot->qtype ?? '');
        if ($qtype === 'random' || $qtype === 'missingtype') {
            return null;
        }
        if (!in_array($qtype, self::QUIZ_SUPPORTED_QTYPES, true)) {
            debugging(
                'local_coursegen: mold quiz slot ' . (int) $slot->slot . ' holds a "' . $qtype
                    . '" question, which the AI service has no schema for; it is not exported.',
                DEBUG_DEVELOPER
            );
            return null;
        }

        // All text travels raw: it carries the mold's markers, and the editor
        // shape is exactly what qtype_*::save_question() reads back.
        $question = [
            'qtype' => $qtype,
            'name' => (string) $slot->name,
            'questiontext' => [
                'text' => (string) ($slot->questiontext ?? ''),
                'format' => (int) ($slot->questiontextformat ?? FORMAT_HTML),
            ],
            'generalfeedback' => [
                'text' => (string) ($slot->generalfeedback ?? ''),
                'format' => (int) ($slot->generalfeedbackformat ?? FORMAT_HTML),
            ],
            'defaultmark' => (float) $slot->defaultmark,
            'penalty' => (float) $slot->penalty,
            // The slot's own data: the page is the mold's layout and the mark
            // lives on quiz_slots, never on the question.
            'page' => (int) $slot->page,
            'maxmark' => (float) $slot->maxmark,
        ];

        return array_merge($question, self::quiz_question_options($qtype, (int) $slot->questionid));
    }

    /**
     * One question's type-specific payload.
     *
     * @param string $qtype The question type.
     * @param int $questionid The question id.
     * @return array
     */
    private static function quiz_question_options(string $qtype, int $questionid): array {
        switch ($qtype) {
            case 'multichoice':
                return self::quiz_multichoice_options($questionid);
            case 'truefalse':
                return self::quiz_truefalse_options($questionid);
            case 'shortanswer':
                return self::quiz_shortanswer_options($questionid);
            case 'numerical':
                return self::quiz_numerical_options($questionid);
            case 'essay':
                return self::quiz_essay_options($questionid);
            case 'gapselect':
                return self::quiz_gapselect_options($questionid);
            case 'calculated':
            case 'calculatedmulti':
                return self::quiz_calculated_options($qtype, $questionid);
            default:
                // A description is its two texts and nothing else: it owns no
                // answer and Moodle forces its mark to zero.
                return [];
        }
    }

    /**
     * A multichoice question: its options, its parallel answer arrays and hints.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_multichoice_options(int $questionid): array {
        global $DB;

        $exported = [];
        $options = $DB->get_record('qtype_multichoice_options', ['questionid' => $questionid]);
        if ($options) {
            $exported = array_merge(
                [
                    'single' => (int) $options->single,
                    'shuffleanswers' => (int) $options->shuffleanswers,
                    'answernumbering' => (string) $options->answernumbering,
                    'shownumcorrect' => (int) $options->shownumcorrect,
                    'showstandardinstruction' => (int) $options->showstandardinstruction,
                ],
                self::quiz_combined_feedback($options)
            );
        }

        $exported = array_merge($exported, self::quiz_answers($questionid, true));

        return array_merge($exported, self::quiz_question_hints($questionid));
    }

    /**
     * A truefalse question: the 0|1 it was authored with, and its two feedbacks.
     *
     * The two question_answers rows hold the LOCALISED "True"/"False" labels
     * that qtype_truefalse writes itself from get_string(), so they must never
     * travel as text. What the payload needs is correctanswer, which is derived
     * from the fraction of the row question_truefalse points at as the true
     * one - a wrong constant there silently mis-grades every attempt.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_truefalse_options(int $questionid): array {
        global $DB;

        $options = $DB->get_record('question_truefalse', ['question' => $questionid]);
        if (!$options) {
            return [];
        }

        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC');
        $true = $answers[$options->trueanswer] ?? null;
        $false = $answers[$options->falseanswer] ?? null;

        return [
            'correctanswer' => ($true && (float) $true->fraction > 0) ? 1 : 0,
            'feedbacktrue' => self::quiz_editor_field($true, 'feedback'),
            'feedbackfalse' => self::quiz_editor_field($false, 'feedback'),
            'showstandardinstruction' => (int) $options->showstandardinstruction,
        ];
    }

    /**
     * A shortanswer question: its case flag and its plain answers.
     *
     * The answers stay plain strings, wildcards ('*') and all: that is the
     * shape the qtype reads, and a '*' answer is how the mold catches anything
     * else.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_shortanswer_options(int $questionid): array {
        global $DB;

        $options = $DB->get_record('qtype_shortanswer_options', ['questionid' => $questionid]);
        $exported = $options ? ['usecase' => (int) $options->usecase] : [];

        return array_merge($exported, self::quiz_answers($questionid, false));
    }

    /**
     * A numerical question: its answers with one tolerance each, plus the units.
     *
     * The tolerance of an answer lives in its own table (question_numerical,
     * one row per answer), so it is rebuilt as an array parallel to the answers
     * - which is how the qtype reads it back.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_numerical_options(int $questionid): array {
        global $DB;

        $tolerances = $DB->get_records_menu(
            'question_numerical',
            ['question' => $questionid],
            '',
            'answer, tolerance'
        );

        $exported = self::quiz_answers($questionid, false);
        $exported['tolerance'] = [];
        foreach (array_keys($DB->get_records('question_answers', ['question' => $questionid], 'id ASC')) as $answerid) {
            $exported['tolerance'][] = (string) ($tolerances[$answerid] ?? '0');
        }

        return array_merge($exported, self::quiz_unit_options($questionid));
    }

    /**
     * The unit options shared by numerical and calculated, when the mold has them.
     *
     * qtype_calculatedmulti never writes this row (it has no unit handling at
     * all), so an absent row ships nothing rather than inventing defaults.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_unit_options(int $questionid): array {
        global $DB;

        $options = $DB->get_record('question_numerical_options', ['question' => $questionid]);
        if (!$options) {
            return [];
        }

        return [
            'showunits' => (int) $options->showunits,
            'unitsleft' => (int) $options->unitsleft,
            'unitgradingtype' => (int) $options->unitgradingtype,
            'unitpenalty' => (float) $options->unitpenalty,
        ];
    }

    /**
     * An essay question: its whole response setup, word-limit gates included.
     *
     * qtype_essay writes minwordlimit/maxwordlimit only when the matching
     * minwordenabled/maxwordenabled gate is SET - isset(), not truthy - so a
     * limit has to travel with its gate, and a mold without limits must ship
     * neither: a gate of 0 would still make the consumer store one.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_essay_options(int $questionid): array {
        global $DB;

        $options = $DB->get_record('qtype_essay_options', ['questionid' => $questionid]);
        if (!$options) {
            return [];
        }

        $exported = [
            'responseformat' => (string) $options->responseformat,
            'responserequired' => (int) $options->responserequired,
            'responsefieldlines' => (int) $options->responsefieldlines,
            'attachments' => (int) $options->attachments,
            'attachmentsrequired' => (int) $options->attachmentsrequired,
            'maxbytes' => (int) $options->maxbytes,
            'filetypeslist' => (string) ($options->filetypeslist ?? ''),
            'graderinfo' => self::quiz_editor_field($options, 'graderinfo'),
            'responsetemplate' => self::quiz_editor_field($options, 'responsetemplate'),
        ];

        if ($options->minwordlimit !== null) {
            $exported['minwordenabled'] = 1;
            $exported['minwordlimit'] = (int) $options->minwordlimit;
        }
        if ($options->maxwordlimit !== null) {
            $exported['maxwordenabled'] = 1;
            $exported['maxwordlimit'] = (int) $options->maxwordlimit;
        }

        return $exported;
    }

    /**
     * A gapselect question: its options and its decoded choices.
     *
     * The choices live in question_answers under a special encoding (see
     * qtype_gapselect_base::save_question_options): answer is the choice word,
     * fraction is always 0 and the FEEDBACK column carries the choice's group
     * number. Exported as answers they would ship a group as feedback and lose
     * the groups, so they travel as the 'choices' list the qtype reads back.
     * The [[1]], [[2]] placeholders inside questiontext are positional -
     * choice N answers gap N - and travel raw with it.
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_gapselect_options(int $questionid): array {
        global $DB;

        $exported = [];
        $options = $DB->get_record('question_gapselect', ['questionid' => $questionid]);
        if ($options) {
            $exported = array_merge(
                [
                    'shuffleanswers' => (int) $options->shuffleanswers,
                    'shownumcorrect' => (int) $options->shownumcorrect,
                ],
                self::quiz_combined_feedback($options)
            );
        }

        $choices = [];
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $answer) {
            $choices[] = [
                'answer' => (string) $answer->answer,
                'choicegroup' => (int) $answer->feedback,
            ];
        }
        $exported['choices'] = $choices;

        return $exported;
    }

    /**
     * A calculated or calculatedmulti question: its formulas and its wildcards.
     *
     * The formula itself is the answer text ('{a} + {b}'), and each answer owns
     * a tolerance, a tolerance type and the shape of the printed right answer
     * in question_calculated - all rebuilt as arrays parallel to the answers.
     * import_process tells the consumer to take the import path, which is the
     * only one that creates the dataset ITEMS as well as the definitions.
     *
     * @param string $qtype Either 'calculated' or 'calculatedmulti'.
     * @param int $questionid
     * @return array
     */
    private static function quiz_calculated_options(string $qtype, int $questionid): array {
        global $DB;

        $ismulti = $qtype === 'calculatedmulti';
        $options = $DB->get_record('question_calculated_options', ['question' => $questionid]);

        $exported = [
            'synchronize' => (int) ($options->synchronize ?? 0),
            'answernumbering' => (string) ($options->answernumbering ?? 'abc'),
            'shuffleanswers' => (int) ($options->shuffleanswers ?? 0),
            'import_process' => true,
        ];
        if ($ismulti && $options) {
            $exported['single'] = (int) $options->single;
            $exported['shownumcorrect'] = (int) $options->shownumcorrect;
            $exported = array_merge($exported, self::quiz_combined_feedback($options));
        }

        // In calculatedmulti the answers are real editor fields, while in
        // calculated they are bare formula strings: that is what each qtype reads.
        $exported = array_merge($exported, self::quiz_answers($questionid, $ismulti));

        $peranswer = $DB->get_records(
            'question_calculated',
            ['question' => $questionid],
            '',
            'answer, tolerance, tolerancetype, correctanswerlength, correctanswerformat'
        );
        $exported['tolerance'] = [];
        $exported['tolerancetype'] = [];
        $exported['correctanswerlength'] = [];
        $exported['correctanswerformat'] = [];
        foreach (array_keys($DB->get_records('question_answers', ['question' => $questionid], 'id ASC')) as $answerid) {
            $row = $peranswer[$answerid] ?? null;
            $exported['tolerance'][] = (string) ($row->tolerance ?? '0');
            $exported['tolerancetype'][] = (int) ($row->tolerancetype ?? 1);
            $exported['correctanswerlength'][] = (int) ($row->correctanswerlength ?? 2);
            $exported['correctanswerformat'][] = (int) ($row->correctanswerformat ?? 2);
        }

        $exported = array_merge($exported, self::quiz_unit_options($questionid));
        $exported['dataset'] = self::quiz_question_datasets($questionid);

        return $exported;
    }

    /**
     * Every wildcard of one calculated question, definitions and values alike.
     *
     * A wildcard spans three tables, and its definition packs the whole range
     * into ONE string - "<distribution>:<min>:<max>:<decimals>" (see
     * qtype_calculated::get_datasets_for_export) - so it travels decoded into
     * the four parts import_datasets() reads back. Without the items the
     * question always fails at attempt time ('cannotgetdsfordependent').
     *
     * @param int $questionid
     * @return array
     */
    private static function quiz_question_datasets(int $questionid): array {
        global $DB;

        $sql = 'SELECT def.id, def.name, def.options, def.itemcount, def.category
                  FROM {question_datasets} qd
                  JOIN {question_dataset_definitions} def ON def.id = qd.datasetdefinition
                 WHERE qd.question = :questionid
              ORDER BY def.id ASC';
        $definitions = $DB->get_records_sql($sql, ['questionid' => $questionid]);

        $datasets = [];
        foreach ($definitions as $definition) {
            $range = explode(':', (string) $definition->options, 4);

            $items = [];
            $records = $DB->get_records(
                'question_dataset_items',
                ['definition' => $definition->id],
                'itemnumber ASC, id ASC'
            );
            foreach ($records as $item) {
                $items[] = ['itemnumber' => (int) $item->itemnumber, 'value' => (string) $item->value];
            }

            $datasets[] = [
                'name' => (string) $definition->name,
                'distribution' => (string) ($range[0] ?? 'uniform'),
                'min' => (string) ($range[1] ?? '0'),
                'max' => (string) ($range[2] ?? '0'),
                // The fourth part is the number of decimals of the values.
                'length' => (string) ($range[3] ?? '1'),
                // A definition of category 0 is private to its question; any
                // other one is shared with the whole question category.
                'status' => ((int) $definition->category === 0) ? 'private' : 'shared',
                // The real count, never the stored one: the attempt runtime
                // picks the variant range from MIN(itemcount).
                'itemcount' => count($items),
                'number_of_items' => count($items),
                'datasetitem' => $items,
            ];
        }
        return $datasets;
    }

    /**
     * One question's answers, as the parallel arrays the qtypes read.
     *
     * @param int $questionid
     * @param bool $answerseditor Whether the answer itself is an editor field
     *     (multichoice, calculatedmulti) rather than a plain string.
     * @return array
     */
    private static function quiz_answers(int $questionid, bool $answerseditor): array {
        global $DB;

        $exported = ['answer' => [], 'fraction' => [], 'feedback' => []];
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $answer) {
            $exported['answer'][] = $answerseditor
                ? ['text' => (string) $answer->answer, 'format' => (int) $answer->answerformat]
                : (string) $answer->answer;
            $exported['fraction'][] = (float) $answer->fraction;
            $exported['feedback'][] = self::quiz_editor_field($answer, 'feedback');
        }
        return $exported;
    }

    /**
     * One question's hints, in authoring order, grading options included.
     *
     * multichoice saves its hints with parts (save_hints($q, true)), so
     * clearwrong and shownumcorrect are settings of the mold like any other:
     * they travel as two arrays parallel to the hints, which is the shape
     * save_hints() reads them back from.
     *
     * A question with no hint ships no key at all - neither an empty hint list
     * nor empty flag arrays - because inventing them would make the consumer
     * create blank hints the mold never had.
     *
     * @param int $questionid
     * @return array Empty when the question has no hint.
     */
    private static function quiz_question_hints(int $questionid): array {
        global $DB;

        $records = $DB->get_records('question_hints', ['questionid' => $questionid], 'id ASC');
        if (!$records) {
            return [];
        }

        $exported = ['hint' => [], 'hintclearwrong' => [], 'hintshownumcorrect' => []];
        foreach ($records as $hint) {
            $exported['hint'][] = ['text' => (string) $hint->hint, 'format' => (int) $hint->hintformat];
            // Both columns are nullable: a qtype that saves its hints without
            // parts leaves them unset, which means "off".
            $exported['hintclearwrong'][] = (int) ($hint->clearwrong ?? 0);
            $exported['hintshownumcorrect'][] = (int) ($hint->shownumcorrect ?? 0);
        }
        return $exported;
    }

    /**
     * The combined-feedback trio, in the editor shape the qtypes read.
     *
     * @param \stdClass $options The qtype's options row.
     * @return array
     */
    private static function quiz_combined_feedback($options): array {
        $feedback = [];
        foreach (self::QUIZ_COMBINED_FEEDBACK as $name) {
            $feedback[$name] = self::quiz_editor_field($options, $name);
        }
        return $feedback;
    }

    /**
     * One stored text plus its format, in the editor shape.
     *
     * @param \stdClass|null $record The row holding it, or null.
     * @param string $field The text column; its format is $field . 'format'.
     * @return array ['text' => raw, 'format' => int]
     */
    private static function quiz_editor_field($record, string $field): array {
        return [
            'text' => (string) ($record->$field ?? ''),
            'format' => (int) ($record->{$field . 'format'} ?? FORMAT_HTML),
        ];
    }

    /** @var string[] Every template column mod_data really owns (see its install.xml). */
    private const DATA_TEMPLATE_COLUMNS = [
        'singletemplate', 'listtemplate', 'listtemplateheader', 'listtemplatefooter',
        'addtemplate', 'rsstemplate', 'rsstitletemplate', 'csstemplate', 'jstemplate',
        'asearchtemplate',
    ];

    /** @var string[] Field types whose value cannot be seeded back (their content is a file). */
    private const DATA_UNSEEDABLE_TYPES = ['file', 'picture'];

    /**
     * A Database's raw description, every instance setting, and its structure.
     *
     * Three traps of mod_data's schema shape this branch:
     *
     * - defaultsort stores a data_fields.id of THIS database. Reused as is it
     *   would point at a foreign row (or at nothing) in the generated one, so
     *   it never travels: the NAME of that field does, under defaultsortfield,
     *   and data_settings maps it back to the new field id.
     * - data_add_instance zeroes the rating window unless ratingtime says it is
     *   in use, exactly as mod_forum does, so that flag travels with the dates
     *   instead of being inferred on the way back in.
     * - The field definitions live in param1..param10, but only param1..param5
     *   ever reach data_field_base::define_field(). They all travel anyway; the
     *   consumer writes the last five straight onto the row.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function data_parameters(cm_info $cm): array {
        global $DB;

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        if (!$data) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $dataid = (int) $data->id;
        $fields = $DB->get_records('data_fields', ['dataid' => $dataid], 'id ASC');

        $parameters = array_merge(
            self::data_settings_columns($data),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $data->intro ?? '',
                'ratingtime' => (!empty($data->assesstimestart) && !empty($data->assesstimefinish)) ? 1 : 0,
                'defaultsortfield' => self::data_default_sort_field($fields, (int) ($data->defaultsort ?? 0)),
            ]
        );

        $collections = [];
        if ($fields) {
            $collections['fields'] = self::data_fields($fields);
        }
        $templates = self::data_templates($data);
        if ($templates) {
            $collections['templates'] = $templates;
        }
        $entries = self::data_example_entries($dataid, $fields);
        if ($entries) {
            $collections['example_entries'] = $entries;
        }
        // The sort field is a top-level setting, but only data_settings can resolve the name
        // into the new field's id - and mod_settings is all create_mod_service hands it - so
        // it travels there too, with the direction it has to apply alongside it.
        if ($parameters['defaultsortfield'] !== '') {
            $collections['defaultsortfield'] = $parameters['defaultsortfield'];
            $collections['defaultsortdir'] = (int) ($data->defaultsortdir ?? 0);
        }
        if ($collections) {
            $parameters['mod_settings'] = $collections;
        }

        return $parameters;
    }

    /**
     * The mod_data settings worth reproducing on the generated activity.
     *
     * The whole scope of the type travels (grading, entries, dates, access,
     * display and completion); identity and placement columns (id, course,
     * name, timemodified, config, defaultsort) are left out on purpose - they
     * describe THIS database, never the new one.
     *
     * @param \stdClass $data
     * @return array
     */
    private static function data_settings_columns($data): array {
        $fields = [
            'approval', 'manageapproved', 'comments',
            'requiredentries', 'requiredentriestoview', 'maxentries',
            'timeavailablefrom', 'timeavailableto', 'timeviewfrom', 'timeviewto',
            'editany', 'notification', 'completionentries',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'defaultsortdir', 'rssarticles',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($data->$field)) {
                $settings[$field] = $data->$field;
            }
        }
        return $settings;
    }

    /**
     * The NAME of the field the mold sorts by, or '' when it sorts by time added.
     *
     * @param array $fields data_fields rows, keyed by id.
     * @param int $defaultsort The mold's data.defaultsort (a data_fields.id).
     * @return string
     */
    private static function data_default_sort_field(array $fields, int $defaultsort): string {
        if ($defaultsort <= 0 || !isset($fields[$defaultsort])) {
            return '';
        }
        return (string) $fields[$defaultsort]->name;
    }

    /**
     * Every field of one database, in creation order, definition included.
     *
     * The params are the definition itself (choices, sizes, autolink, ...) and
     * travel raw - they are nullable columns, and guessing them per type would
     * rebuild a different column than the one the author authored.
     *
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private static function data_fields(array $fields): array {
        $exported = [];
        foreach ($fields as $field) {
            $spec = [
                'type' => $field->type,
                'name' => $field->name,
                'description' => $field->description ?? '',
                'required' => (int) $field->required,
            ];
            for ($param = 1; $param <= 10; $param++) {
                $key = 'param' . $param;
                $spec[$key] = $field->$key ?? null;
            }
            $exported[] = $spec;
        }
        return $exported;
    }

    /**
     * The mold's authored template columns, the non-empty ones only.
     *
     * They travel raw: a template carries both mod_data's own [[Field name]]
     * references and the service's markers, so any escaping or filtering here
     * would leave the generated database rendering nothing. An absent column
     * lets Moodle generate its own default, as it does for a hand-built one.
     *
     * @param \stdClass $data
     * @return array
     */
    private static function data_templates($data): array {
        $templates = [];
        foreach (self::DATA_TEMPLATE_COLUMNS as $column) {
            $value = (string) ($data->$column ?? '');
            if (trim($value) !== '') {
                $templates[$column] = $value;
            }
        }
        return $templates;
    }

    /**
     * The mold's entries, in authoring order, re-encoded for the consumer.
     *
     * The shape is the one data_settings::seed_example_entries() already reads,
     * so an exported mold flows back in unchanged. Values of a file/picture
     * field are dropped: the consumer cannot seed them, and copying a mold's
     * embedded files is not implemented anywhere in the plugin yet.
     *
     * @param int $dataid
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private static function data_example_entries(int $dataid, array $fields): array {
        global $DB;

        if (!$fields) {
            return [];
        }

        $records = $DB->get_records('data_records', ['dataid' => $dataid], 'id ASC', 'id');
        $entries = [];
        foreach ($records as $record) {
            $contents = $DB->get_records(
                'data_content',
                ['recordid' => $record->id],
                '',
                'fieldid, content, content1'
            );
            $values = [];
            foreach ($fields as $field) {
                $content = $contents[$field->id] ?? null;
                if ($content === null) {
                    continue;
                }
                $row = self::data_entry_row((string) $field->type, $content);
                if ($row !== null) {
                    $values[] = ['field_name' => $field->name] + $row;
                }
            }
            if ($values) {
                $entries[] = ['values' => $values];
            }
        }
        return $entries;
    }

    /**
     * One stored value, back in the form data_settings reads.
     *
     * The row is always ['value' => ...], plus an optional 'value1' for the
     * types that really own a second stored column. Each type is the exact
     * inverse of data_settings::insert_content(), which is what makes the round
     * trip lossless:
     *
     * - A date is stored as a unix timestamp, but insert_content() parses its
     *   input with strtotime(), and strtotime('1700000000') is false - so the
     *   day travels as a date string, not as the raw timestamp.
     * - multimenu/checkbox are stored '##' delimited, while insert_content()
     *   splits its input on commas, so the options travel comma separated.
     * - latlong keeps its pair in content/content1 but travels as ONE comma
     *   separated value, the way insert_content() has always read it back: the
     *   pair is meaningless split in two, that encoding already round trips
     *   exactly, and it is the shape the model-driven path emits.
     * - A url owns a real second authored string - the visible link text that
     *   mod_data stores in content1 - so it travels as value1, and ONLY when
     *   the author wrote one. An absent value1 is meaningful: it tells the
     *   consumer to leave content1 alone and keeps the payload identical to
     *   the one every other type ships.
     *
     * @param string $type The field type.
     * @param \stdClass $content The data_content row (content, content1).
     * @return array|null ['value' => string, 'value1' => string (optional)], or
     *     null when this type cannot be seeded back.
     */
    private static function data_entry_row(string $type, $content): ?array {
        if (in_array($type, self::DATA_UNSEEDABLE_TYPES, true)) {
            return null;
        }

        $raw = (string) ($content->content ?? '');
        if ($raw === '') {
            return null;
        }

        switch ($type) {
            case 'date':
                return ['value' => date('Y-m-d', (int) $raw)];
            case 'multimenu':
            case 'checkbox':
                return ['value' => implode(', ', explode('##', $raw))];
            case 'latlong':
                $longitude = (string) ($content->content1 ?? '');
                return $longitude === '' ? null : ['value' => $raw . ', ' . $longitude];
            case 'url':
                $linktext = (string) ($content->content1 ?? '');
                return $linktext === '' ? ['value' => $raw] : ['value' => $raw, 'value1' => $linktext];
            default:
                return ['value' => $raw];
        }
    }

    /**
     * A Wiki's raw description, its four type settings and its pages.
     *
     * A wiki that nobody has opened yet owns no page at all: wiki_add_instance
     * creates neither the subwiki nor the first page, they appear on the first
     * view. Such a mold still travels, simply without mod_settings.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function wiki_parameters(cm_info $cm): array {
        global $DB;

        $wiki = $DB->get_record('wiki', ['id' => $cm->instance]);
        if (!$wiki) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            'intro' => $wiki->intro ?? '',
            'wikimode' => $wiki->wikimode ?? 'collaborative',
            'defaultformat' => $wiki->defaultformat ?? 'html',
            'forceformat' => (int) ($wiki->forceformat ?? 0),
            'firstpagetitle' => $wiki->firstpagetitle ?? '',
        ];

        $pages = self::wiki_pages((int) $wiki->id, (string) ($wiki->firstpagetitle ?? ''));
        if ($pages) {
            $parameters['mod_settings'] = ['pages' => $pages];
        }

        return $parameters;
    }

    /**
     * Every page of one wiki, the first page ahead of the rest, as authored.
     *
     * Three traps of mod_wiki's schema shape this query:
     *
     * - Pages hang off a subwiki, never off the wiki itself. A mold is authored
     *   as a single collaborative wiki, which owns exactly one subwiki
     *   (groupid 0, userid 0), so the wiki's FIRST subwiki is the one carrying
     *   the authored pages.
     * - The authored text lives in wiki_versions.content of the CURRENT (highest)
     *   version. wiki_pages.cachedcontent is the parsed render that
     *   wiki_refresh_cachedcontent stores, so it would deliver markers already
     *   chewed by the wiki parser.
     * - There is no "is first" flag and no ordering column: the first page is
     *   the one whose title matches wiki.firstpagetitle (as wiki_get_first_page
     *   matches it), and id is the only stable sequence for the others.
     *
     * @param int $wikiid
     * @param string $firstpagetitle
     * @return array
     */
    private static function wiki_pages(int $wikiid, string $firstpagetitle): array {
        global $DB;

        $sql = 'SELECT p.id, p.title, v.content
                  FROM {wiki_pages} p
                  JOIN {wiki_subwikis} s ON s.id = p.subwikiid
             LEFT JOIN {wiki_versions} v ON v.pageid = p.id
                       AND v.version = (SELECT MAX(v2.version)
                                          FROM {wiki_versions} v2
                                         WHERE v2.pageid = p.id)
                 WHERE s.wikiid = :wikiid
                   AND s.id = (SELECT MIN(s2.id)
                                 FROM {wiki_subwikis} s2
                                WHERE s2.wikiid = :subwikiid)
              ORDER BY p.id ASC';
        $records = $DB->get_records_sql($sql, ['wikiid' => $wikiid, 'subwikiid' => $wikiid]);

        $first = [];
        $rest = [];
        foreach ($records as $record) {
            $isfirst = $firstpagetitle !== '' && (string) $record->title === $firstpagetitle;
            $page = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'firstpage' => $isfirst,
            ];
            if ($isfirst) {
                $first[] = $page;
            } else {
                $rest[] = $page;
            }
        }

        return array_merge($first, $rest);
    }

    /**
     * A Book's raw introduction, its three settings and its chapters.
     *
     * mod_book has no parent column: a subchapter belongs to the nearest
     * preceding chapter, so the reading order IS the hierarchy and must be
     * preserved exactly.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function book_parameters(cm_info $cm): array {
        global $DB;

        $book = $DB->get_record('book', ['id' => $cm->instance]);
        if (!$book) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            'intro' => $book->intro ?? '',
            'numbering' => (int) ($book->numbering ?? 0),
            // Moodle's own form has no control for this one, but the generated
            // book must still read the way the mold does.
            'navstyle' => (int) ($book->navstyle ?? 1),
            'customtitles' => (int) ($book->customtitles ?? 0),
        ];

        $chapters = self::book_chapters((int) $book->id);
        if ($chapters) {
            $parameters['mod_settings'] = ['chapters' => $chapters];
        }

        return $parameters;
    }

    /**
     * Every chapter of one book, in reading order.
     *
     * @param int $bookid
     * @return array
     */
    private static function book_chapters(int $bookid): array {
        global $DB;

        $records = $DB->get_records(
            'book_chapters',
            ['bookid' => $bookid],
            'pagenum ASC',
            'id, title, content, subchapter'
        );

        $chapters = [];
        foreach ($records as $record) {
            $chapters[] = [
                'title' => $record->title,
                'content' => $record->content ?? '',
                'subchapter' => (int) $record->subchapter,
            ];
        }
        return $chapters;
    }

    /**
     * A Forum's raw description, its settings and its initial discussions.
     *
     * Unlike url/resource, every forum setting is a plain column - there is no
     * serialized blob to unpack. The discussions are this type's internal
     * elements: their bodies are marker-bearing, so they travel raw and in the
     * order they were authored.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function forum_parameters(cm_info $cm): array {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $cm->instance]);
        if (!$forum) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = array_merge(
            self::forum_settings_columns($forum),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $forum->intro ?? '',
                // Moodle zeroes the rating window unless this flag says it is
                // in use (see forum_add_instance), so it travels with it
                // instead of being inferred on the way back in.
                'ratingtime' => (!empty($forum->assesstimestart) && !empty($forum->assesstimefinish)) ? 1 : 0,
            ]
        );

        $discussions = self::forum_discussions((int) $forum->id);
        if ($discussions) {
            $parameters['mod_settings'] = ['discussions' => $discussions];
        }

        return $parameters;
    }

    /**
     * The mod_forum settings worth reproducing on the generated activity.
     *
     * Identity/placement columns (id, course, name, timemodified) are left out
     * on purpose - they describe THIS forum, never the new one.
     *
     * @param \stdClass $forum
     * @return array
     */
    private static function forum_settings_columns($forum): array {
        $fields = [
            'type', 'forcesubscribe', 'trackingtype',
            'maxbytes', 'maxattachments', 'displaywordcount',
            'lockdiscussionafter', 'blockperiod', 'blockafter', 'warnafter',
            'grade_forum', 'grade_forum_notify',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'completiondiscussions', 'completionreplies', 'completionposts',
            'duedate', 'cutoffdate', 'rsstype', 'rssarticles',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($forum->$field)) {
                $settings[$field] = $forum->$field;
            }
        }
        return $settings;
    }

    /**
     * Every initial discussion of one forum, as authored.
     *
     * A discussion's body lives on its first post, not on the discussion row.
     * Moodle lists discussions by pinned/last-reply order, which is a reading
     * order, not the authoring one - so they are ordered by id, the only
     * stable "as written" sequence.
     *
     * @param int $forumid
     * @return array
     */
    private static function forum_discussions(int $forumid): array {
        global $DB;

        $sql = 'SELECT d.id, d.name, p.message
                  FROM {forum_discussions} d
                  JOIN {forum_posts} p ON p.id = d.firstpost
                 WHERE d.forum = :forumid
              ORDER BY d.id ASC';
        $records = $DB->get_records_sql($sql, ['forumid' => $forumid]);

        $discussions = [];
        foreach ($records as $record) {
            $discussions[] = [
                'subject' => $record->name,
                'message' => $record->message ?? '',
            ];
        }
        return $discussions;
    }

    /**
     * A File's raw description, its appearance settings and its document's
     * identity.
     *
     * The generated activity always builds a NEW document; the mold's own file
     * never travels as bytes. Its name and extension do, because the generated
     * document is produced in the same format the author chose here.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function resource_parameters(cm_info $cm): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $cm->instance]);
        if (!$resource) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        $parameters = array_merge(
            self::resource_display_settings($resource),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $resource->intro ?? '',
            ]
        );

        $moldfile = self::resource_mold_file($cm);
        if ($moldfile !== null) {
            $parameters['moldfile'] = $moldfile;
        }

        return $parameters;
    }

    /**
     * The appearance settings worth reproducing on the generated activity.
     *
     * mod_resource stores them serialized in displayoptions and, unlike
     * mod_url, it only writes the checkbox options when they are ON (see
     * resource_set_display_options). An absent key therefore means OFF, not
     * "fall back to the site default" - reading it the other way would turn
     * on options the author deliberately left off.
     *
     * @param \stdClass $resource
     * @return array
     */
    private static function resource_display_settings($resource): array {
        $options = [];
        if (!empty($resource->displayoptions)) {
            $options = (array) unserialize_array($resource->displayoptions);
        }
        $config = get_config('resource');

        return [
            'display' => (int) ($resource->display ?? $config->display ?? 0),
            // Only meaningful for AUTO/EMBED/FRAME, where mod_resource writes it.
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'showsize' => (int) ($options['showsize'] ?? 0),
            'showtype' => (int) ($options['showtype'] ?? 0),
            'showdate' => (int) ($options['showdate'] ?? 0),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
            'filterfiles' => (int) ($resource->filterfiles ?? $config->filterfiles ?? 0),
        ];
    }

    /**
     * The identity of the document the mold carries, or null when it has none.
     *
     * Only what the service needs to reproduce the format: never the bytes.
     *
     * @param cm_info $cm
     * @return array|null
     */
    private static function resource_mold_file(cm_info $cm): ?array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $cm->context->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id ASC',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }

        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return [
            'filename' => $filename,
            'mimetype' => (string) $file->get_mimetype(),
            'extension' => $extension,
        ];
    }

    /**
     * A URL's address, its raw description and its display settings.
     *
     * The description is the marker-bearing field the service fills in, so it
     * travels raw - formatting or filtering it here would destroy the mold.
     * The address travels verbatim too: a plain link is reused as is, while a
     * marked one tells the service to resolve it for the new course.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function url_parameters(cm_info $cm): array {
        global $DB;

        $url = $DB->get_record('url', ['id' => $cm->instance]);
        if (!$url) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        return array_merge(
            self::url_display_settings($url),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $url->intro ?? '',
                'externalurl' => $url->externalurl ?? '',
            ]
        );
    }

    /**
     * The display settings worth reproducing on the generated activity.
     *
     * mod_url stores them serialized in displayoptions but rebuilds that blob
     * from flat fields on save (see url_add_instance), so the flat shape is
     * what the generated activity can actually consume. Missing entries fall
     * back to the site defaults rather than travelling as nulls.
     *
     * @param \stdClass $url
     * @return array
     */
    private static function url_display_settings($url): array {
        $options = [];
        if (!empty($url->displayoptions)) {
            $options = (array) unserialize_array($url->displayoptions);
        }
        $config = get_config('url');

        return [
            'display' => (int) ($url->display ?? $config->display ?? 0),
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
        ];
    }

    /**
     * A lesson's real settings plus its ordered pages.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function lesson_parameters(cm_info $cm): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $cm->instance]);
        if (!$lesson) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        // Every real mod_lesson setting travels too, not just the pages: the
        // generated activity is meant to BE this mold (progress bar, menu,
        // retakes, grading, ...), and course_ai copies these verbatim onto
        // it (see _lesson_mold_config_overrides). Sending only name/pages is
        // what left generated lessons on the schema's generic defaults.
        return array_merge(
            self::lesson_settings_columns($lesson),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $lesson->intro ?? '',
                'mod_settings' => ['pages' => self::lesson_pages((int) $lesson->id)],
            ]
        );
    }

    /**
     * The mod_lesson settings worth reproducing on the generated activity.
     *
     * Identity/placement columns (id, course, timemodified, ...) are left out
     * on purpose - they describe THIS lesson, never the new one.
     *
     * @param \stdClass $lesson
     * @return array
     */
    private static function lesson_settings_columns($lesson): array {
        $fields = [
            'practice', 'modattempts', 'usepassword', 'password', 'dependency', 'conditions',
            'grade', 'custom', 'ongoing', 'usemaxgrade', 'maxanswers', 'maxattempts',
            'review', 'nextpagedefault', 'feedback', 'minquestions', 'maxpages', 'timelimit',
            'retake', 'activitylink', 'mediafile', 'mediaheight', 'mediawidth', 'mediaclose',
            'slideshow', 'width', 'height', 'bgcolor', 'displayleft', 'displayleftif',
            'progressbar', 'available', 'deadline', 'completionendreached', 'completiontimespent',
        ];
        $settings = [];
        foreach ($fields as $field) {
            if (isset($lesson->$field)) {
                $settings[$field] = $lesson->$field;
            }
        }
        return $settings;
    }

    /**
     * Every page of one lesson, in the order students actually walk it.
     *
     * mod_lesson stores that order as a prevpageid/nextpageid chain, NOT as
     * the row id order: a page inserted between two existing ones keeps a
     * higher id while sitting in the middle. Ordering by id therefore
     * scrambled the mold's real sequence.
     *
     * @param int $lessonid
     * @return array
     */
    private static function lesson_pages(int $lessonid): array {
        global $DB;

        $records = $DB->get_records('lesson_pages', ['lessonid' => $lessonid], 'id ASC');
        $pages = [];
        foreach (self::chain_order($records) as $page) {
            $pages[] = [
                'title' => $page->title,
                'page_type' => 'content',
                'content_html' => $page->contents,
                'buttons' => self::page_buttons((int) $page->id),
            ];
        }
        return $pages;
    }

    /**
     * Walk the prevpageid/nextpageid chain from its first page.
     *
     * @param array $records lesson_pages rows, keyed by id.
     * @return array Ordered rows; falls back to the given order if the chain
     *     is broken (never loses a page).
     */
    private static function chain_order(array $records): array {
        $first = null;
        foreach ($records as $page) {
            if ((int) $page->prevpageid === 0) {
                $first = $page;
                break;
            }
        }
        if ($first === null) {
            return array_values($records);
        }

        $ordered = [];
        $current = $first;
        while ($current !== null && count($ordered) < count($records)) {
            $ordered[] = $current;
            $nextid = (int) $current->nextpageid;
            $current = $nextid > 0 ? ($records[$nextid] ?? null) : null;
        }

        return count($ordered) === count($records) ? $ordered : array_values($records);
    }

    /**
     * One page's real navigation buttons: every answer, with its jump.
     *
     * A content page's answers ARE its buttons ("Anterior"/"Siguiente"/"Fin
     * de la lección", each with its own jumpto). Sending only the first one
     * collapsed every page to a single button - and, when that first answer
     * was "Anterior", to a back-labelled button that jumped forward.
     *
     * @param int $pageid
     * @return array
     */
    private static function page_buttons(int $pageid): array {
        global $DB;

        $buttons = [];
        $answers = $DB->get_records('lesson_answers', ['pageid' => $pageid], 'id ASC', 'id, answer, jumpto');
        foreach ($answers as $answer) {
            $text = trim(html_to_text((string) $answer->answer, 0, false));
            if ($text === '') {
                continue;
            }
            $buttons[] = ['text' => $text, 'jumpto' => (int) $answer->jumpto];
        }
        return $buttons;
    }
}
