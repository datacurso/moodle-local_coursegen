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

namespace local_coursegen\local\preview\workshop;

use stdClass;
use workshop;

/**
 * The assessment, evaluation and closed phases of
 * workshop_user_plan::__construct(), kept apart from view.php only because
 * together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_plan_late {
    /**
     * Copied from workshop_user_plan::__construct(): setup | submission | * ASSESSMENT | evaluation | closed.
     *
     * @return stdClass
     */
    protected function build_phase_assessment(): stdClass {
        $workshop = $this->workshop;
        $userid = $this->userid;

        $phase = new stdClass();
        $phase->title = get_string('phaseassessment', 'workshop');
        $phase->tasks = [];
        $phase->isreviewer = has_capability('mod/workshop:peerassess', $this->context, $userid);
        if ($workshop->phase == workshop::PHASE_SUBMISSION && $workshop->phaseswitchassessment
                && has_capability('mod/workshop:switchphase', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('switchphase30auto', 'mod_workshop', workshop::timestamp_formats($workshop->submissionend));
            $task->completed = 'info';
            $phase->tasks['autoswitchinfo'] = $task;
        }
        if ($workshop->useexamples && $workshop->examplesmode == workshop::EXAMPLES_BEFORE_ASSESSMENT
                && $phase->isreviewer && !has_capability('mod/workshop:manageexamples', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('exampleassesstask', 'workshop');
            $examples = $this->get_examples();
            $a = new stdClass();
            $a->expected = count($examples);
            $a->assessed = $this->count_examples_assessed($examples);
            $task->details = get_string('exampleassesstaskdetails', 'workshop', $a);
            if ($a->assessed == $a->expected) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                $task->completed = false;
            }
            $phase->tasks['examples'] = $task;
        }
        if (empty($phase->tasks['examples']) || !empty($phase->tasks['examples']->completed)) {
            $phase->assessments = $this->get_assessments_by_reviewer($userid);
            $numofpeers     = 0;    // Number of allocated peer-assessments.
            $numofpeerstodo = 0;    // Number of peer-assessments to do.
            $numofself      = 0;    // Number of allocated self-assessments - should be 0 or 1.
            $numofselftodo  = 0;    // Number of self-assessments to do - should be 0 or 1.
            foreach ($phase->assessments as $a) {
                if ($a->authorid == $userid) {
                    $numofself++;
                    if (is_null($a->grade)) {
                        $numofselftodo++;
                    }
                } else {
                    $numofpeers++;
                    if (is_null($a->grade)) {
                        $numofpeerstodo++;
                    }
                }
            }
            unset($a);
            if ($numofpeers) {
                $task = new stdClass();
                if ($numofpeerstodo == 0) {
                    $task->completed = true;
                } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                    $task->completed = false;
                }
                $a = new stdClass();
                $a->total = $numofpeers;
                $a->todo  = $numofpeerstodo;
                $task->title = get_string('taskassesspeers', 'workshop');
                $task->details = get_string('taskassesspeersdetails', 'workshop', $a);
                unset($a);
                $phase->tasks['assesspeers'] = $task;
            }
            if ($workshop->useselfassessment && $numofself) {
                $task = new stdClass();
                if ($numofselftodo == 0) {
                    $task->completed = true;
                } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                    $task->completed = false;
                }
                $task->title = get_string('taskassessself', 'workshop');
                $phase->tasks['assessself'] = $task;
            }
        }
        if ($workshop->assessmentstart) {
            $task = new stdClass();
            $task->title = get_string('assessmentstartdatetime', 'workshop',
                workshop::timestamp_formats($workshop->assessmentstart));
            $task->completed = 'info';
            $phase->tasks['assessmentstartdatetime'] = $task;
        }
        if ($workshop->assessmentend) {
            $task = new stdClass();
            $task->title = get_string('assessmentenddatetime', 'workshop',
                workshop::timestamp_formats($workshop->assessmentend));
            $task->completed = 'info';
            $phase->tasks['assessmentenddatetime'] = $task;
        }
        if (isset($phase->tasks['assessmentstartdatetime']) || isset($phase->tasks['assessmentenddatetime'])) {
            if (has_capability('mod/workshop:ignoredeadlines', $this->context, $userid)) {
                $task = new stdClass();
                $task->title = get_string('deadlinesignored', 'workshop');
                $task->completed = 'info';
                $phase->tasks['deadlinesignored'] = $task;
            }
        }
        return $phase;
    }

    /**
     * Copied from workshop_user_plan::__construct(): setup | submission | assessment | * EVALUATION | closed.
     *
     * @return stdClass
     */
    protected function build_phase_evaluation(): stdClass {
        $workshop = $this->workshop;
        $userid = $this->userid;

        $phase = new stdClass();
        $phase->title = get_string('phaseevaluation', 'workshop');
        $phase->tasks = [];
        if (has_capability('mod/workshop:overridegrades', $this->context)) {
            $phase->tasks['calculatesubmissiongrade'] = $this->build_calculate_submission_grade_task($workshop);
            $phase->tasks['calculategradinggrade'] = $this->build_calculate_grading_grade_task($workshop);

        } else if ($workshop->phase == workshop::PHASE_EVALUATION) {
            $task = new stdClass();
            $task->title = get_string('evaluategradeswait', 'workshop');
            $task->completed = 'info';
            $phase->tasks['evaluateinfo'] = $task;
        }

        if (has_capability('moodle/course:manageactivities', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('taskconclusion', 'workshop');
            $task->link = $this->updatemod_url();
            if (trim($workshop->conclusion)) {
                $task->completed = true;
            } else if ($workshop->phase >= workshop::PHASE_EVALUATION) {
                $task->completed = false;
            }
            $phase->tasks['conclusion'] = $task;
        }

        return $phase;
    }

    /**
     * Copied from workshop_user_plan::__construct(): the submission grades a manager has calculated so far.
     *
     * @param stdClass $workshop
     * @return stdClass
     */
    protected function build_calculate_submission_grade_task(stdClass $workshop): stdClass {
        $expected = $this->count_potential_authors(false);
        $calculated = 0;
        foreach ($this->store->get_records('workshop_submissions', ['workshopid' => $workshop->id]) as $s) {
            if (!is_null($s->grade ?? null) || !is_null($s->gradeover ?? null)) {
                $calculated++;
            }
        }
        $task = new stdClass();
        $task->title = get_string('calculatesubmissiongrades', 'workshop');
        $a = new stdClass();
        $a->expected    = $expected;
        $a->calculated  = $calculated;
        $task->details  = get_string('calculatesubmissiongradesdetails', 'workshop', $a);
        if ($calculated >= $expected) {
            $task->completed = true;
        } else if ($workshop->phase > workshop::PHASE_EVALUATION) {
            $task->completed = false;
        }
        return $task;
    }

    /**
     * Copied from workshop_user_plan::__construct(): the grading grades a manager has calculated so far.
     *
     * @param stdClass $workshop
     * @return stdClass
     */
    protected function build_calculate_grading_grade_task(stdClass $workshop): stdClass {
        $expected = $this->count_potential_reviewers(false);
        $calculated = 0;
        foreach ($this->store->get_records('workshop_aggregations', ['workshopid' => $workshop->id]) as $g) {
            if (!is_null($g->gradinggrade ?? null)) {
                $calculated++;
            }
        }
        $task = new stdClass();
        $task->title = get_string('calculategradinggrades', 'workshop');
        $a = new stdClass();
        $a->expected    = $expected;
        $a->calculated  = $calculated;
        $task->details  = get_string('calculategradinggradesdetails', 'workshop', $a);
        if ($calculated >= $expected) {
            $task->completed = true;
        } else if ($workshop->phase > workshop::PHASE_EVALUATION) {
            $task->completed = false;
        }
        return $task;
    }

    /**
     * Copied from workshop_user_plan::__construct(): setup | submission | assessment | evaluation | * CLOSED.
     *
     * @return stdClass
     */
    protected function build_phase_closed(): stdClass {
        $phase = new stdClass();
        $phase->title = get_string('phaseclosed', 'workshop');
        $phase->tasks = [];
        return $phase;
    }
}
