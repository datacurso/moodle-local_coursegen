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

namespace local_coursegen\mod_export;

use core_tag_tag;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;

/**
 * Class quiz_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_export extends base_export {
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
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $this->cm->instance]);
        if (!$quiz) {
            return $this->minimal_parameters();
        }

        $parameters = array_merge(
            $this->settings_columns($quiz),
            $this->review_options($quiz),
            $this->overall_feedback($quiz),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $quiz->intro ?? '',
                'quizpassword' => (string) ($quiz->password ?? ''),
                'gradepass' => $this->grade_pass(),
            ]
        );

        [$questions, $slotmap] = $this->questions();
        if ($questions) {
            $parameters['mod_settings'] = ['questions' => $questions];

            $sections = $this->sections($quiz, $slotmap);
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
    private function overall_feedback($quiz): array {
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
            $boundaries[] = $this->feedback_boundary($boundary);
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
    private function feedback_boundary(float $boundary): string {
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
    private function sections($quiz, array $slotmap): array {
        global $DB;

        $rows = $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot ASC');

        $sections = [];
        $claimed = [];
        foreach ($rows as $row) {
            $firstslot = $this->section_first_slot((int) $row->firstslot, $slotmap);
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

        if (
            count($sections) === 1 && $sections[0]['firstslot'] === 1
                && $sections[0]['heading'] === '' && $sections[0]['shufflequestions'] === 0
        ) {
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
    private function section_first_slot(int $firstslot, array $slotmap): ?int {
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
    private function settings_columns($quiz): array {
        return $this->whitelisted_settings($quiz, self::QUIZ_SETTINGS_COLUMNS);
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
    private function review_options($quiz): array {
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
     * Every question of one quiz, in slot order, page and mark included.
     *
     * The supported API resolves the whole quiz_slots -> question_references ->
     * question_bank_entries -> question_versions -> question chain and picks
     * the right version, which hand-written SQL over quiz_slots cannot.
     *
     * @return array [questions, mold slot number => exported slot number]
     */
    private function questions(): array {
        $structure = qbank_helper::get_question_structure((int) $this->cm->instance, $this->cm->context);

        $questions = [];
        $slotmap = [];
        foreach ($structure as $slot) {
            $question = $this->question_parameters($slot, (string) $this->cm->name);
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
    private function question_parameters($slot, string $quizname): ?array {
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
            $this->question_options($qtype, (int) $slot->questionid),
            $this->question_hints($qtype, (int) $slot->questionid),
            $this->question_tags((int) $slot->questionid)
        );
    }

    /**
     * One question's type-specific payload.
     *
     * @param string $qtype The question type.
     * @param int $questionid The question id.
     * @return array
     */
    private function question_options(string $qtype, int $questionid): array {
        switch ($qtype) {
            case 'multichoice':
                return $this->multichoice_options($questionid);
            case 'truefalse':
                return $this->truefalse_options($questionid);
            case 'shortanswer':
                return $this->shortanswer_options($questionid);
            case 'numerical':
                return $this->numerical_options($questionid);
            case 'essay':
                return $this->essay_options($questionid);
            case 'gapselect':
                return $this->gapselect_options($questionid);
            case 'calculated':
            case 'calculatedmulti':
                return $this->calculated_options($qtype, $questionid);
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
    private function multichoice_options(int $questionid): array {
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
                $this->combined_feedback($options)
            );
        }

        return array_merge($exported, $this->answers($questionid, true));
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
    private function truefalse_options(int $questionid): array {
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
            'feedbacktrue' => $this->editor_field($true, 'feedback'),
            'feedbackfalse' => $this->editor_field($false, 'feedback'),
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
    private function shortanswer_options(int $questionid): array {
        global $DB;

        $options = $DB->get_record('qtype_shortanswer_options', ['questionid' => $questionid]);
        $exported = $options ? ['usecase' => (int) $options->usecase] : [];

        return array_merge($exported, $this->answers($questionid, false));
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
    private function numerical_options(int $questionid): array {
        global $DB;

        $tolerances = $DB->get_records_menu(
            'question_numerical',
            ['question' => $questionid],
            '',
            'answer, tolerance'
        );

        $exported = $this->answers($questionid, false);
        $exported['tolerance'] = [];
        foreach (array_keys($DB->get_records('question_answers', ['question' => $questionid], 'id ASC')) as $answerid) {
            $exported['tolerance'][] = (string) ($tolerances[$answerid] ?? '0');
        }

        return array_merge($exported, $this->unit_options($questionid));
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
    private function unit_options(int $questionid): array {
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
    private function essay_options(int $questionid): array {
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
            'graderinfo' => $this->editor_field($options, 'graderinfo'),
            'responsetemplate' => $this->editor_field($options, 'responsetemplate'),
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
    private function gapselect_options(int $questionid): array {
        global $DB;

        $exported = [];
        $options = $DB->get_record('question_gapselect', ['questionid' => $questionid]);
        if ($options) {
            $exported = array_merge(
                [
                    'shuffleanswers' => (int) $options->shuffleanswers,
                    'shownumcorrect' => (int) $options->shownumcorrect,
                ],
                $this->combined_feedback($options)
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
    private function calculated_options(string $qtype, int $questionid): array {
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
            $exported = array_merge($exported, $this->combined_feedback($options));
        }

        // In calculatedmulti the answers are real editor fields, while in
        // calculated they are bare formula strings: that is what each qtype reads.
        $exported = array_merge($exported, $this->answers($questionid, $ismulti));

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

        $exported = array_merge($exported, $this->unit_options($questionid));
        $exported['dataset'] = $this->question_datasets($questionid);

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
    private function question_datasets(int $questionid): array {
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
    private function answers(int $questionid, bool $answerseditor): array {
        global $DB;

        $exported = ['answer' => [], 'fraction' => [], 'feedback' => []];
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id ASC') as $answer) {
            $exported['answer'][] = $answerseditor
                ? ['text' => (string) $answer->answer, 'format' => (int) $answer->answerformat]
                : (string) $answer->answer;
            $exported['fraction'][] = (float) $answer->fraction;
            $exported['feedback'][] = $this->editor_field($answer, 'feedback');
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
    private function question_hints(string $qtype, int $questionid): array {
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
    private function question_tags(int $questionid): array {
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
    private function combined_feedback($options): array {
        $feedback = [];
        foreach (self::QUIZ_COMBINED_FEEDBACK as $name) {
            $feedback[$name] = $this->editor_field($options, $name);
        }
        return $feedback;
    }
}
