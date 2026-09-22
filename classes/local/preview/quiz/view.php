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
use moodle_url;
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
    use quiz_page_buttons;
    use quiz_access_rules;
    use quiz_question_engine;

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
        // The real page offers to attempt or to preview the quiz. Nobody may
        // act on an activity that does not exist: no button is offered, and
        // the questions follow below instead. What would keep a reader out is
        // still said, because it describes the quiz.
        $viewobj->buttontext = '';
        if ($viewobj->quizhasquestions && $canpreview) {
            $viewobj->preventmessages = $this->prevent_access();
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
}
