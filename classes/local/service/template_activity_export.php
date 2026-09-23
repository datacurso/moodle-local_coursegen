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
use core_tag_tag;
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
        if ($cm->modname === 'h5pactivity') {
            return self::h5pactivity_parameters($cm);
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
     * @var array<string, bool> The supported qtypes that save hints, and whether they do it WITH parts.
     *
     * The value is the $withparts argument each qtype passes to
     * question_type::save_hints(), which is what decides whether
     * hintclearwrong and hintshownumcorrect are on that qtype's form at all:
     * true for multichoice, gapselect (qtype_gapselect_base) and
     * calculatedmulti, false for truefalse, shortanswer, numerical and
     * calculated. essay and description are absent because neither ever calls
     * save_hints(), so a hint of theirs would be written by nobody.
     */
    private const QUIZ_HINT_QTYPES = [
        'multichoice' => true,
        'gapselect' => true,
        'calculatedmulti' => true,
        'truefalse' => false,
        'shortanswer' => false,
        'numerical' => false,
        'calculated' => false,
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
     * The overall feedback bands are NOT a mod_settings collection: the quiz
     * mod_form owns them as two top-level repeated arrays, so they travel there
     * (see quiz_overall_feedback()). The sections are, because they describe
     * the layout of the questions and can only be rebuilt once those exist.
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
            self::quiz_overall_feedback($quiz),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $quiz->intro ?? '',
                'quizpassword' => (string) ($quiz->password ?? ''),
                'gradepass' => self::quiz_grade_pass($cm),
            ]
        );

        [$questions, $slotmap] = self::quiz_questions($cm);
        if ($questions) {
            $parameters['mod_settings'] = ['questions' => $questions];

            $sections = self::quiz_sections($quiz, $slotmap);
            if ($sections) {
                $parameters['mod_settings']['sections'] = $sections;
            }
        }

        return $parameters;
    }

    /**
     * The mold's overall feedback bands, in the shape the quiz form owns.
     *
     * quiz_feedback rows are written by quiz_after_add_or_update() out of two
     * top-level repeated form arrays, so they have to travel back the same way:
     * feedbacktext[] carries one editor field per band, highest band first, and
     * feedbackboundaries[] the grade that each band starts at - one FEWER entry
     * than the texts, because the lowest band always starts at zero and the
     * form never carries that boundary (see quiz_after_add_or_update(), which
     * reads feedbackboundaries[$i] for the min and [$i - 1] for the max, with
     * quiz_process_options() filling [-1] with grade + 1 and [count] with 0).
     *
     * The boundaries travel ABSOLUTE. quiz_process_options() also accepts a
     * '50%' string and turns it into a grade, but only the absolute form can be
     * rebuilt from the stored mingrade without knowing which of the two the
     * author typed. The text travels raw: it may carry the mold's markers.
     *
     * @param \stdClass $quiz The quiz row.
     * @return array Empty when the mold has no authored band.
     */
    private static function quiz_overall_feedback($quiz): array {
        global $DB;

        $grade = (float) ($quiz->grade ?? 0);
        $rows = array_values($DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'mingrade DESC'));
        if (!$rows || $grade <= 0) {
            return [];
        }

        $texts = [];
        $boundaries = [];
        $previous = null;
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $texts[] = [
                'text' => (string) $row->feedbacktext,
                'format' => (int) $row->feedbacktextformat,
                // quiz_after_add_or_update() reads this key straight off the
                // payload; zero simply means "no draft file area to merge in".
                'itemid' => 0,
            ];
            if ($index === $last) {
                continue;
            }

            $boundary = (float) $row->mingrade;
            if ($boundary <= 0 || $boundary >= $grade || ($previous !== null && $boundary >= $previous)) {
                // quiz_process_options() refuses the WHOLE quiz on a boundary
                // out of range or out of order, which a grade lowered after the
                // bands were written produces. An unusable band set therefore
                // travels as no band set at all, rather than as an activity the
                // consumer cannot create.
                return [];
            }
            $previous = $boundary;
            $boundaries[] = self::quiz_feedback_boundary($boundary);
        }

        foreach ($texts as $text) {
            if (!html_is_blank($text['text'])) {
                return [
                    'feedbacktext' => $texts,
                    'feedbackboundaries' => $boundaries,
                    // The mod_form repeat counter. quiz_process_options()
                    // ignores it, but it is part of the shape the service's
                    // QuizParameters declares.
                    'boundary_repeats' => count($boundaries),
                ];
            }
        }

        // A single blank band is what mod_quiz stores for a feedback form
        // nobody ever filled in: there is nothing authored to reproduce.
        return [];
    }

    /**
     * One stored grade boundary, as the absolute string the quiz form reads.
     *
     * @param float $boundary The stored mingrade.
     * @return string
     */
    private static function quiz_feedback_boundary(float $boundary): string {
        // The column is number(10,5). Formatting it explicitly and trimming the
        // trailing zeros keeps a small boundary out of scientific notation,
        // which quiz_process_options() would read as junk.
        $formatted = rtrim(rtrim(number_format($boundary, 5, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * The mold's section headings, renumbered onto the exported slots.
     *
     * A quiz always owns at least one section, created with it at slot 1
     * (mod/quiz/lib.php), so an untouched default one describes nothing the
     * generated quiz does not already have and is not reported.
     *
     * firstslot is a SLOT NUMBER, and the export skips the slots it cannot
     * reproduce (random, unsupported types), so every section is moved onto the
     * first exported slot at or after the one it started on. A section whose
     * questions were all skipped disappears with them, and two sections that
     * end up on the same slot collapse into the first.
     *
     * @param \stdClass $quiz The quiz row.
     * @param array<int, int> $slotmap Mold slot number => exported slot number.
     * @return array
     */
    private static function quiz_sections($quiz, array $slotmap): array {
        global $DB;

        $rows = $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot ASC');

        $sections = [];
        $claimed = [];
        foreach ($rows as $row) {
            $firstslot = self::quiz_section_first_slot((int) $row->firstslot, $slotmap);
            if ($firstslot === null || isset($claimed[$firstslot])) {
                continue;
            }
            $claimed[$firstslot] = true;
            $sections[] = [
                'heading' => (string) ($row->heading ?? ''),
                'shufflequestions' => (int) $row->shufflequestions,
                'firstslot' => $firstslot,
            ];
        }

        if (count($sections) === 1 && $sections[0]['firstslot'] === 1
                && $sections[0]['heading'] === '' && $sections[0]['shufflequestions'] === 0) {
            return [];
        }

        return $sections;
    }

    /**
     * The exported slot one section starts at, or null when it lost every slot.
     *
     * @param int $firstslot The section's firstslot in the mold.
     * @param array<int, int> $slotmap Mold slot number => exported slot number, in slot order.
     * @return int|null
     */
    private static function quiz_section_first_slot(int $firstslot, array $slotmap): ?int {
        foreach ($slotmap as $moldslot => $exportedslot) {
            if ($moldslot >= $firstslot) {
                return $exportedslot;
            }
        }

        return null;
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
     * @return array [questions, mold slot number => exported slot number]
     */
    private static function quiz_questions(cm_info $cm): array {
        $structure = qbank_helper::get_question_structure((int) $cm->instance, $cm->context);

        $questions = [];
        $slotmap = [];
        foreach ($structure as $slot) {
            $question = self::quiz_question_parameters($slot, (string) $cm->name);
            if ($question !== null) {
                $questions[] = $question;
                // The exported slots are renumbered from one: the skipped ones
                // leave no gap in the generated quiz, so the sections have to
                // be renumbered onto this map rather than onto the mold's.
                $slotmap[(int) $slot->slot] = count($questions);
            }
        }
        return [$questions, $slotmap];
    }

    /**
     * One slot's question, or null when this slot cannot be reproduced.
     *
     * Three kinds of slot never travel:
     *
     * - 'random' is a question_set_reference, not a question: it draws from a
     *   question bank CATEGORY of the template's own course, which does not
     *   exist in the generated one, and quiz_add_quiz_question() throws
     *   outright on random questions. It is skipped WITH a developer notice,
     *   because an admin who molded a quiz full of random slots would otherwise
     *   just get a shorter quiz and no explanation.
     * - a qtype outside the supported nine is skipped the same way, and for the
     *   same reason.
     * - 'missingtype' is the placeholder the API puts in when the question
     *   itself is gone, or its qtype plugin is uninstalled. It is skipped in
     *   silence: there is no definition left to describe, and the broken mold
     *   is already visible as such in the mold's own course.
     *
     * @param \stdClass $slot One row of qbank_helper::get_question_structure().
     * @param string $quizname The mold quiz's name, for the developer notices.
     * @return array|null
     */
    private static function quiz_question_parameters($slot, string $quizname): ?array {
        $qtype = (string) ($slot->qtype ?? '');
        if ($qtype === 'missingtype') {
            return null;
        }
        if ($qtype === 'random') {
            debugging(
                'local_coursegen: quiz "' . $quizname . '" slot ' . (int) $slot->slot . ' holds a random '
                    . 'question, which draws from a question bank category of the template course; it '
                    . 'cannot be reproduced in the generated course and is not exported.',
                DEBUG_DEVELOPER
            );
            return null;
        }
        if (!in_array($qtype, self::QUIZ_SUPPORTED_QTYPES, true)) {
            debugging(
                'local_coursegen: quiz "' . $quizname . '" slot ' . (int) $slot->slot . ' holds a "' . $qtype
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

        // The other two authored quiz_slots columns. Both ship only when the
        // mold really set them, so an absent key still means exactly what it
        // has always meant: automatic numbering and no dependency.
        if ($slot->displaynumber !== null && (string) $slot->displaynumber !== '') {
            $question['displaynumber'] = (string) $slot->displaynumber;
        }
        if (!empty($slot->requireprevious)) {
            $question['requireprevious'] = 1;
        }

        return array_merge(
            $question,
            self::quiz_question_options($qtype, (int) $slot->questionid),
            self::quiz_question_hints($qtype, (int) $slot->questionid),
            self::quiz_question_tags((int) $slot->questionid)
        );
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
     * A multichoice question: its options and its parallel answer arrays.
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

        return array_merge($exported, self::quiz_answers($questionid, true));
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
     * Seven of the nine supported types save hints; which of them also save the
     * two part flags is decided by the $withparts argument each one passes to
     * question_type::save_hints(), listed in QUIZ_HINT_QTYPES. A qtype without
     * parts leaves clearwrong and shownumcorrect null in the database and has
     * no form field for them, so reporting them would invent two settings the
     * mold cannot express - and reporting the hints only for multichoice, which
     * is what this used to do, lost every hint of the other six.
     *
     * A question with no hint ships no key at all - neither an empty hint list
     * nor empty flag arrays - because inventing them would make the consumer
     * create blank hints the mold never had.
     *
     * @param string $qtype The question type.
     * @param int $questionid
     * @return array Empty when this type has no hints, or the question has none.
     */
    private static function quiz_question_hints(string $qtype, int $questionid): array {
        global $DB;

        if (!array_key_exists($qtype, self::QUIZ_HINT_QTYPES)) {
            return [];
        }

        $records = $DB->get_records('question_hints', ['questionid' => $questionid], 'id ASC');
        if (!$records) {
            return [];
        }

        $withparts = self::QUIZ_HINT_QTYPES[$qtype];
        $exported = ['hint' => []];
        if ($withparts) {
            $exported['hintclearwrong'] = [];
            $exported['hintshownumcorrect'] = [];
        }
        foreach ($records as $hint) {
            $exported['hint'][] = ['text' => (string) $hint->hint, 'format' => (int) $hint->hintformat];
            if ($withparts) {
                // Both columns are nullable, and null there means "off".
                $exported['hintclearwrong'][] = (int) ($hint->clearwrong ?? 0);
                $exported['hintshownumcorrect'][] = (int) ($hint->shownumcorrect ?? 0);
            }
        }
        return $exported;
    }

    /**
     * One question's tags, in the order the author put them in.
     *
     * The tags are authored classification of the mold's question bank, so they
     * travel with the question and the consumer re-applies them. They are read
     * with their rawname, which preserves the author's own casing.
     *
     * Two neighbouring pieces of the bank deliberately do NOT travel:
     *
     * - idnumber, because it has to be unique inside a question bank category
     *   and a copy carrying the mold's one would collide with it the moment the
     *   generated course shares a category with the template.
     * - anything beyond version 1 of the question. The export describes one
     *   question, and the consumer creates it as a fresh bank entry whose first
     *   version is the only one there has ever been; a mold's version history
     *   belongs to the mold's own bank entry and cannot be re-created.
     *
     * @param int $questionid
     * @return array Empty when the question has no tag.
     */
    private static function quiz_question_tags(int $questionid): array {
        $tags = [];
        foreach (core_tag_tag::get_item_tags('core_question', 'question', $questionid) as $tag) {
            $tags[] = (string) $tag->rawname;
        }

        return $tags ? ['tags' => $tags] : [];
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

    /** @var string[] Every mod_h5pactivity instance setting worth reproducing on the generated activity. */
    private const H5PACTIVITY_SETTINGS_COLUMNS = [
        'displayoptions', 'enabletracking', 'grademethod', 'reviewmode', 'grade',
    ];

    /**
     * An H5P activity's raw description, its settings and its own package text.
     *
     * This type breaks the convention every other file-bearing one follows. A
     * scorm/imscp/resource mold only describes the document it wants BUILT, and
     * the service writes a new package the plugin then downloads. An H5P mold
     * cannot work that way: its content is content/content.json inside its own
     * .h5p, which also carries the library folders, the images and every
     * coordinate those images are calibrated against. So only the TEXT travels
     * - the two raw entries under moldh5p - and the plugin rebuilds the package
     * out of the MOLD's own .h5p, replacing just those two entries
     * (see \local_coursegen\mod_parameters\h5pactivity_parameters). The library
     * versions survive byte for byte, which is also what keeps the generated
     * activity compatible with whatever H5P version the site runs.
     *
     * Two traps of mod_h5pactivity's schema shape this branch:
     *
     * - gradepass is NOT an h5pactivity column. As in mod_quiz it lives in
     *   grade_items, so it travels through the grades API.
     * - displayoptions is a PACKED int whose bits are inverted (set = disabled)
     *   and which the site can partly force. add_moduleinfo() consumes it as
     *   such - unlike the quiz review bitmasks - so it is copied verbatim
     *   rather than decoded into checkboxes that would not round trip.
     *
     * There is deliberately no maxattempts: mod_h5pactivity owns no such
     * column. Its "attempt options" fieldset is enabletracking, grademethod
     * and reviewmode, and nothing else.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function h5pactivity_parameters(cm_info $cm): array {
        global $DB;

        $h5pactivity = $DB->get_record('h5pactivity', ['id' => $cm->instance]);
        if (!$h5pactivity) {
            return ['name' => $cm->name, 'section' => (int) $cm->sectionnum];
        }

        return array_merge(
            self::h5pactivity_settings_columns($h5pactivity),
            [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
                'intro' => $h5pactivity->intro ?? '',
                'gradepass' => self::h5pactivity_grade_pass($cm),
                // Always reported, null included: an absent key and a package
                // this activity does not have mean different things to the
                // service, and only one of them is true here.
                'moldh5p' => self::h5pactivity_mold_package($cm),
            ]
        );
    }

    /**
     * The mod_h5pactivity settings worth reproducing on the generated activity.
     *
     * Identity columns (id, course, name, timecreated, timemodified,
     * introformat) are left out on purpose - they describe THIS activity,
     * never the new one.
     *
     * @param \stdClass $h5pactivity
     * @return array
     */
    private static function h5pactivity_settings_columns($h5pactivity): array {
        $settings = [];
        foreach (self::H5PACTIVITY_SETTINGS_COLUMNS as $field) {
            if (isset($h5pactivity->$field)) {
                $settings[$field] = $h5pactivity->$field;
            }
        }
        return $settings;
    }

    /**
     * The mold's grade to pass, or 0.0 when it has none.
     *
     * @param cm_info $cm
     * @return float
     */
    private static function h5pactivity_grade_pass(cm_info $cm): float {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'h5pactivity',
            'iteminstance' => (int) $cm->instance,
            'itemnumber' => 0,
            'courseid' => (int) $cm->course,
        ]);

        return $item ? (float) $item->gradepass : 0.0;
    }

    /**
     * The two text entries of the mold's own package, plus its identity.
     *
     * Both texts travel RAW: they are the marker-bearing strings the service
     * fills in, and they are also what the rebuild writes back into a copy of
     * this very package - so any re-encoding here would come back as a
     * different file. The cmid is the plugin's own, and the rebuild re-resolves
     * and re-authorises it: the service is never trusted with a file handle.
     *
     * A package that cannot be opened, or that is missing either entry, is not
     * a mold this design can reproduce; it reports no mold rather than throwing
     * and losing the whole template export over one broken activity.
     *
     * @param cm_info $cm
     * @return array|null
     */
    private static function h5pactivity_mold_package(cm_info $cm): ?array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($cm->context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }

        // The same primitive h5pactivity_parameters::validate_package() uses:
        // a stored file has to be on disk before ZipArchive can read it.
        $temppath = $file->copy_content_to_temp();
        try {
            $zip = new \ZipArchive();
            if ($zip->open($temppath) !== true) {
                return null;
            }
            $h5pjson = $zip->getFromName('h5p.json');
            $contentjson = $zip->getFromName('content/content.json');
            $zip->close();
        } finally {
            @unlink($temppath);
        }

        if ($h5pjson === false || $contentjson === false) {
            return null;
        }

        return array_merge(
            [
                'cmid' => (int) $cm->id,
                'filename' => $file->get_filename(),
                'h5pjson' => $h5pjson,
                'contentjson' => $contentjson,
            ],
            self::h5pactivity_main_library($h5pjson)
        );
    }

    /**
     * The manifest's main library, with the version the package really carries.
     *
     * h5p.json names the main library but holds no version of its own for it:
     * the version lives in the preloadedDependencies entry whose machineName
     * matches mainLibrary, which is where core_h5p reads it from too.
     *
     * @param string $h5pjson The raw h5p.json text.
     * @return array<string, mixed> mainlibrary, majorversion and minorversion.
     */
    private static function h5pactivity_main_library(string $h5pjson): array {
        $manifest = json_decode($h5pjson, true);
        $mainlibrary = is_array($manifest) ? (string) ($manifest['mainLibrary'] ?? '') : '';

        $major = 0;
        $minor = 0;
        $dependencies = (is_array($manifest) ? $manifest['preloadedDependencies'] ?? [] : []);
        foreach ((array) $dependencies as $dependency) {
            if ($mainlibrary === '' || !is_array($dependency)) {
                continue;
            }
            if ((string) ($dependency['machineName'] ?? '') === $mainlibrary) {
                $major = (int) ($dependency['majorVersion'] ?? 0);
                $minor = (int) ($dependency['minorVersion'] ?? 0);
                break;
            }
        }

        return ['mainlibrary' => $mainlibrary, 'majorversion' => $major, 'minorversion' => $minor];
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
