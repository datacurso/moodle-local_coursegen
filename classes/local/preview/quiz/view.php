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

use context;
use html_writer;
use moodle_url;
use question_bank;
use question_display_options;
use question_engine;
use single_button;
use stdClass;

/**
 * mod_quiz's view code, ported to run against the payload.
 *
 * Copied from mod/quiz/view.php, mod/quiz/classes/output/renderer.php
 * (view_page and what it calls), mod/quiz/classes/access_manager.php and the
 * quizaccess rules whose messages depend only on the quiz's own settings
 * (Moodle 4.5). Method names are the functions and methods they came from.
 *
 * What changed: the quiz is read from the payload rather than the database;
 * the reader is treated as someone who may preview the quiz, which is who
 * reads a preview; there are no attempts, because attempts are the readers'
 * and a template carries none, so everything that only an attempt can reach
 * (the summary of attempts, the grade so far, the feedback) never draws; and
 * the start button posts back to the preview.
 *
 * The questions are not on a quiz's view page; they are one attempt away. A
 * preview has nowhere to attempt, so they follow the view page here, drawn by
 * the question engine itself, which builds and renders a question from its
 * data without ever touching the database.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** mod/quiz/lib.php. */
    const QUIZ_GRADEHIGHEST = '1';
    /** mod/quiz/lib.php. */
    const QUIZ_GRADEAVERAGE = '2';
    /** mod/quiz/lib.php. */
    const QUIZ_ATTEMPTFIRST = '3';
    /** mod/quiz/lib.php. */
    const QUIZ_ATTEMPTLAST = '4';

    /** @var stdClass The quiz row. */
    protected stdClass $quiz;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var array One entry per slot: slot, page, maxmark, displaynumber and the question's data. */
    protected array $slots;
    /** @var moodle_url Where the preview is read; the start button posts back to it. */
    protected moodle_url $here;
    /** @var int */
    protected int $timenow;

    /**
     * Constructor.
     *
     * @param stdClass $quiz The quiz row.
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @param array $slots The quiz's slots with their questions' data.
     * @param moodle_url $here
     */
    public function __construct(
        stdClass $quiz,
        stdClass $cm,
        stdClass $course,
        context $context,
        array $slots,
        moodle_url $here
    ) {
        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->slots = $slots;
        $this->here = $here;
        $this->timenow = time();
    }

    /**
     * mod/quiz/view.php from the header to the footer, then the questions.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;

        $quiz = $this->quiz;

        // A preview is read by someone who may preview the quiz.
        $canpreview = true;
        $canattempt = false;
        $canignoretimelimits = false;

        // There are no attempts to list or to grade.
        $numattempts = 0;
        $unfinished = false;
        $lastfinishedattempt = false;

        $viewobj = new stdClass();
        $viewobj->attempts = [];
        $viewobj->timenow = $this->timenow;
        $viewobj->numattempts = $numattempts;
        $viewobj->mygrade = null;
        $viewobj->moreattempts = $unfinished || !$this->is_finished($numattempts, $lastfinishedattempt);
        $viewobj->mygradeoverridden = false;
        $viewobj->gradebookfeedback = '';
        $viewobj->lastfinishedattempt = $lastfinishedattempt;
        $viewobj->canedit = false;
        $viewobj->startattempturl = $this->here;
        $viewobj->preflightcheckform = null;
        $viewobj->popuprequired = $this->attempt_must_be_in_popup();
        $viewobj->popupoptions = [];
        $viewobj->gradecolumn = false;
        $viewobj->overallstats = false;
        $viewobj->feedbackcolumn = false;
        $viewobj->showbacktocourse = false;

        // Display information about this quiz.
        $viewobj->infomessages = $this->describe_rules($canignoretimelimits);
        if ($quiz->attempts != 1) {
            $viewobj->infomessages[] = get_string('gradingmethod', 'quiz',
                    $this->quiz_get_grading_option_name($quiz->grademethod));
        }

        // The grade to pass lives on the grade item, which is the course's
        // gradebook and not the quiz's row, so a payload has no way to say it.

        // Determine whether a start attempt button should be displayed.
        $viewobj->quizhasquestions = !empty($this->slots);
        $viewobj->preventmessages = [];
        if (!$viewobj->quizhasquestions) {
            $viewobj->buttontext = '';
        } else {
            if ($unfinished) {
                if ($canpreview) {
                    $viewobj->buttontext = get_string('continuepreview', 'quiz');
                } else if ($canattempt) {
                    $viewobj->buttontext = get_string('continueattemptquiz', 'quiz');
                }
            } else {
                if ($canpreview) {
                    $viewobj->buttontext = get_string('previewquizstart', 'quiz');
                } else if ($canattempt) {
                    $viewobj->buttontext = get_string('attemptquiz', 'quiz');
                }
            }

            // Users who can preview the quiz should be able to see all messages for not being able to access the quiz.
            if ($canpreview) {
                $viewobj->preventmessages = $this->prevent_access();
            }
        }

        $out = $this->view_page($viewobj);
        $out .= $this->questions();
        return $out;
    }

    /**
     * renderer::view_page().
     *
     * @param stdClass $viewobj
     * @return string
     */
    protected function view_page(stdClass $viewobj): string {
        global $OUTPUT;
        $output = '';

        $output .= $this->view_page_tertiary_nav($viewobj);
        $output .= $this->view_information($viewobj->infomessages);
        // view_result_info() draws nothing without an attempt or a grade, and
        // the list of attempts draws nothing without attempts.
        $output .= $OUTPUT->box($this->view_page_buttons($viewobj), 'quizattempt');
        return $output;
    }

    /**
     * renderer::view_page_tertiary_nav().
     *
     * @param stdClass $viewobj
     * @return string
     */
    protected function view_page_tertiary_nav(stdClass $viewobj): string {
        $content = '';

        if ($viewobj->buttontext) {
            $attemptbtn = $this->start_attempt_button($viewobj->buttontext,
                    $viewobj->startattempturl, $viewobj->popuprequired, $viewobj->popupoptions);
            $content .= $attemptbtn;
        }

        // The "add question" link is for someone who may edit a quiz that
        // exists; nothing here does.

        if ($content) {
            return html_writer::div(html_writer::div($content, 'row'), 'container-fluid tertiary-navigation');
        } else {
            return '';
        }
    }

    /**
     * renderer::start_attempt_button().
     *
     * @param string $buttontext
     * @param moodle_url $url
     * @param bool $popuprequired
     * @param array|null $popupoptions
     * @return string
     */
    protected function start_attempt_button($buttontext, moodle_url $url, $popuprequired = false, $popupoptions = null) {
        global $OUTPUT, $PAGE;

        $button = new single_button($url, $buttontext, 'post', single_button::BUTTON_PRIMARY);
        $button->class .= ' quizstartbuttondiv';
        if ($popuprequired) {
            $button->class .= ' quizsecuremoderequired';
        }

        $popupjsoptions = null;

        $PAGE->requires->js_call_amd('mod_quiz/preflightcheck', 'init',
                ['.quizstartbuttondiv [type=submit]', get_string('startattempt', 'quiz'),
                        '#mod_quiz_preflight_form', $popupjsoptions]);

        return $OUTPUT->render($button);
    }

    /**
     * renderer::view_information().
     *
     * The counts of attempts and of overrides are links to reports on a quiz
     * that exists, and both draw nothing when there is nothing to count, which
     * is the case here.
     *
     * @param array $messages
     * @return string
     */
    protected function view_information(array $messages): string {
        global $OUTPUT;
        $output = '';

        // Output any access messages.
        if ($messages) {
            $output .= $OUTPUT->box($this->access_messages($messages), 'quizinfo');
        }

        return $output;
    }

    /**
     * renderer::view_page_buttons().
     *
     * @param stdClass $viewobj
     * @return string
     */
    protected function view_page_buttons(stdClass $viewobj): string {
        global $OUTPUT;
        $output = '';

        if (!$viewobj->quizhasquestions) {
            $output .= html_writer::div(
                    $OUTPUT->notification(get_string('noquestions', 'quiz'), 'warning', false),
                    'text-start mb-3');
        }
        $output .= $this->access_messages($viewobj->preventmessages);

        return $output;
    }

    /**
     * renderer::access_messages().
     *
     * @param array $messages
     * @return string
     */
    protected function access_messages(array $messages): string {
        $output = '';
        foreach ($messages as $message) {
            $output .= html_writer::tag('p', $message, ['class' => 'text-start']);
        }
        return $output;
    }

    /**
     * access_manager::describe_rules(), for the rules a quiz's own row settles.
     *
     * The rules are made in the order core_component lists the quizaccess
     * plugins, and each says its piece. The ones read from the quiz's row:
     * numattempts, password and timelimit. The rest either say nothing
     * (delaybetweenattempts, ipaddress, offlineattempts, openclosedate,
     * securewindow for a previewing reader) or are settled by their own
     * tables (seb), which a payload does not carry.
     *
     * @param bool $canignoretimelimits
     * @return string[]
     */
    protected function describe_rules(bool $canignoretimelimits): array {
        $quiz = $this->quiz;
        $result = [];

        // quizaccess_numattempts.
        if ((int) $quiz->attempts !== 0) {
            $result[] = get_string('attemptsallowedn', 'quizaccess_numattempts', $quiz->attempts);
        }
        // quizaccess_password.
        if (!empty($quiz->password)) {
            $result[] = get_string('requirepasswordmessage', 'quizaccess_password');
        }
        // quizaccess_timelimit.
        if (!empty($quiz->timelimit) && !$canignoretimelimits) {
            $result[] = get_string('quiztimelimit', 'quizaccess_timelimit', format_time($quiz->timelimit));
        }
        return $result;
    }

    /**
     * access_manager::prevent_access(), for the rules a quiz's own row settles.
     *
     * @return string[]
     */
    protected function prevent_access(): array {
        $quiz = $this->quiz;
        $result = [];

        // quizaccess_ipaddress.
        if (!empty($quiz->subnet) && !address_in_subnet(getremoteaddr(), $quiz->subnet)) {
            $result[] = get_string('subnetwrong', 'quizaccess_ipaddress');
        }

        // quizaccess_openclosedate.
        $message = get_string('notavailable', 'quizaccess_openclosedate');
        if ($this->timenow < $quiz->timeopen) {
            $result[] = $message;
        } else if (!empty($quiz->timeclose) && $this->timenow > $quiz->timeclose) {
            if (($quiz->overduehandling ?? '') !== 'graceperiod'
                    || $this->timenow > $quiz->timeclose + (int) ($quiz->graceperiod ?? 0)) {
                $result[] = $message;
            }
        }
        return $result;
    }

    /**
     * access_manager::is_finished(), for the rules a quiz's own row settles.
     *
     * @param int $numprevattempts
     * @param mixed $lastattempt
     * @return bool
     */
    protected function is_finished(int $numprevattempts, $lastattempt): bool {
        $quiz = $this->quiz;
        // quizaccess_numattempts.
        if ((int) $quiz->attempts !== 0 && $numprevattempts >= $quiz->attempts) {
            return true;
        }
        // quizaccess_openclosedate.
        if (!empty($quiz->timeclose) && $this->timenow > $quiz->timeclose) {
            return true;
        }
        return false;
    }

    /**
     * access_manager::attempt_must_be_in_popup().
     *
     * @return bool
     */
    protected function attempt_must_be_in_popup(): bool {
        return ($this->quiz->browsersecurity ?? '') === 'securewindow';
    }

    /**
     * mod/quiz/locallib.php quiz_get_grading_option_name().
     *
     * @param mixed $option
     * @return string
     */
    protected function quiz_get_grading_option_name($option): string {
        $strings = [
            self::QUIZ_GRADEHIGHEST => get_string('gradehighest', 'quiz'),
            self::QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'quiz'),
            self::QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'quiz'),
            self::QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'quiz'),
        ];
        return $strings[(string) $option] ?? '';
    }

    /**
     * mod/quiz/lib.php quiz_get_grade_format().
     *
     * @return int
     */
    protected function quiz_get_grade_format(): int {
        $quiz = $this->quiz;
        if (empty($quiz->questiondecimalpoints)) {
            $quiz->questiondecimalpoints = -1;
        }
        if ((int) $quiz->questiondecimalpoints === -1) {
            return (int) $quiz->decimalpoints;
        }
        return (int) $quiz->questiondecimalpoints;
    }

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
