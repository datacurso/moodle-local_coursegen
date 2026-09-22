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
 * The setup and submission phases of workshop_user_plan::__construct(), kept
 * apart from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_plan_early {
    /**
     * Ported from workshop_user_plan::__construct(): setup | submission | assessment | evaluation | closed.
     *
     * @return stdClass
     */
    protected function build_phase_setup(): stdClass {
        $workshop = $this->workshop;
        $userid = $this->userid;

        $phase = new stdClass();
        $phase->title = get_string('phasesetup', 'workshop');
        $phase->tasks = [];
        if (has_capability('moodle/course:manageactivities', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('taskintro', 'workshop');
            $task->link = $this->updatemod_url();
            $task->completed = !(trim($workshop->intro) == '');
            $phase->tasks['intro'] = $task;
        }
        if (has_capability('moodle/course:manageactivities', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('taskinstructauthors', 'workshop');
            $task->link = $this->updatemod_url();
            $task->completed = !(trim($workshop->instructauthors) == '');
            $phase->tasks['instructauthors'] = $task;
        }
        if (has_capability('mod/workshop:editdimensions', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('editassessmentform', 'workshop');
            $task->link = $this->editform_url();
            if ($this->form_ready()) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_SETUP) {
                $task->completed = false;
            }
            $phase->tasks['editform'] = $task;
        }
        if ($workshop->useexamples && has_capability('mod/workshop:manageexamples', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('prepareexamples', 'workshop');
            if ($this->store->count_records('workshop_submissions', ['example' => 1, 'workshopid' => $workshop->id]) > 0) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_SETUP) {
                $task->completed = false;
            }
            $phase->tasks['prepareexamples'] = $task;
        }
        if (empty($phase->tasks) && $workshop->phase == workshop::PHASE_SETUP) {
            // If we are in the setup phase and there is no task (typical for students), let us
            // display some explanation what is going on.
            $task = new stdClass();
            $task->title = get_string('undersetup', 'workshop');
            $task->completed = 'info';
            $phase->tasks['setupinfo'] = $task;
        }
        return $phase;
    }

    /**
     * Ported from workshop_user_plan::__construct(): setup | * SUBMISSION | assessment | evaluation | closed.
     *
     * @return stdClass
     */
    protected function build_phase_submission(): stdClass {
        $workshop = $this->workshop;
        $userid = $this->userid;

        $phase = new stdClass();
        $phase->title = get_string('phasesubmission', 'workshop');
        $phase->tasks = [];
        if (has_capability('moodle/course:manageactivities', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('taskinstructreviewers', 'workshop');
            $task->link = $this->updatemod_url();
            if (trim($workshop->instructreviewers)) {
                $task->completed = true;
            } else if ($workshop->phase >= workshop::PHASE_ASSESSMENT) {
                $task->completed = false;
            }
            $phase->tasks['instructreviewers'] = $task;
        }
        if ($workshop->useexamples && $workshop->examplesmode == workshop::EXAMPLES_BEFORE_SUBMISSION
                && has_capability('mod/workshop:submit', $this->context, $userid, false)
                    && !has_capability('mod/workshop:manageexamples', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('exampleassesstask', 'workshop');
            $examples = $this->get_examples();
            $a = new stdClass();
            $a->expected = count($examples);
            $a->assessed = 0;
            foreach ($examples as $exampleid => $example) {
                if (!is_null($example->grade)) {
                    $a->assessed++;
                }
            }
            $task->details = get_string('exampleassesstaskdetails', 'workshop', $a);
            if ($a->assessed == $a->expected) {
                $task->completed = true;
            } else if ($workshop->phase >= workshop::PHASE_ASSESSMENT) {
                $task->completed = false;
            }
            $phase->tasks['examples'] = $task;
        }
        if (has_capability('mod/workshop:submit', $this->context, $userid, false)) {
            $task = new stdClass();
            $task->title = get_string('tasksubmit', 'workshop');
            $task->link = $this->submission_url();
            if ($this->store->record_exists('workshop_submissions',
                    ['workshopid' => $workshop->id, 'example' => 0, 'authorid' => $userid])) {
                $task->completed = true;
            } else if ($workshop->phase >= workshop::PHASE_ASSESSMENT) {
                $task->completed = false;
            } else {
                $task->completed = null;    // Still has a chance to submit.
            }
            $phase->tasks['submit'] = $task;
        }
        if (has_capability('mod/workshop:allocate', $this->context, $userid)) {
            if ($workshop->phaseswitchassessment) {
                $task = new stdClass();
                $allocator = $this->store->get_record('workshopallocation_scheduled', ['workshopid' => $workshop->id]);
                if (empty($allocator)) {
                    $task->completed = false;
                } else if ($allocator->enabled && is_null($allocator->resultstatus)) {
                    $task->completed = true;
                } else if ($workshop->submissionend > time()) {
                    $task->completed = null;
                } else {
                    $task->completed = false;
                }
                $task->title = get_string('setup', 'workshopallocation_scheduled');
                $task->link = $this->allocation_url('scheduled');
                $phase->tasks['allocatescheduled'] = $task;
            }
            $task = new stdClass();
            $task->title = get_string('allocate', 'workshop');
            $task->link = $this->allocation_url();
            $numofauthors = $this->count_potential_authors(false);
            $numofsubmissions = $this->store->count_records('workshop_submissions',
                ['workshopid' => $workshop->id, 'example' => 0]);
            $numnonallocated = 0;
            foreach ($this->store->get_records('workshop_submissions', ['workshopid' => $workshop->id, 'example' => 0]) as $s) {
                if (!$this->store->record_exists('workshop_assessments', ['submissionid' => $s->id])) {
                    $numnonallocated++;
                }
            }
            if ($numofsubmissions == 0) {
                $task->completed = null;
            } else if ($numnonallocated == 0) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_SUBMISSION) {
                $task->completed = false;
            } else {
                $task->completed = null;    // Still has a chance to allocate.
            }
            $a = new stdClass();
            $a->expected    = $numofauthors;
            $a->submitted   = $numofsubmissions;
            $a->allocate    = $numnonallocated;
            $task->details  = get_string('allocatedetails', 'workshop', $a);
            unset($a);
            $phase->tasks['allocate'] = $task;

            if ($numofsubmissions < $numofauthors && $workshop->phase >= workshop::PHASE_SUBMISSION) {
                $task = new stdClass();
                $task->title = get_string('someuserswosubmission', 'workshop');
                $task->completed = 'info';
                $phase->tasks['allocateinfo'] = $task;
            }
        }
        if ($workshop->submissionstart) {
            $task = new stdClass();
            $task->title = get_string('submissionstartdatetime', 'workshop',
                workshop::timestamp_formats($workshop->submissionstart));
            $task->completed = 'info';
            $phase->tasks['submissionstartdatetime'] = $task;
        }
        if ($workshop->submissionend) {
            $task = new stdClass();
            $task->title = get_string('submissionenddatetime', 'workshop',
                workshop::timestamp_formats($workshop->submissionend));
            $task->completed = 'info';
            $phase->tasks['submissionenddatetime'] = $task;
        }
        if (($workshop->submissionstart < time()) && $workshop->latesubmissions) {
            // If submission deadline has passed and late submissions are allowed, only display 'latesubmissionsallowed' text to
            // users (students) who have not submitted and users (teachers, admins) who can switch phase.
            if (has_capability('mod/workshop:switchphase', $this->context, $userid) ||
                    (!$this->get_submission_by_author($userid) && $workshop->submissionend < time())) {
                $task = new stdClass();
                $task->title = get_string('latesubmissionsallowed', 'workshop');
                $task->completed = 'info';
                $phase->tasks['latesubmissionsallowed'] = $task;
            }
        }
        if (isset($phase->tasks['submissionstartdatetime']) || isset($phase->tasks['submissionenddatetime'])) {
            if (has_capability('mod/workshop:ignoredeadlines', $this->context, $userid)) {
                $task = new stdClass();
                $task->title = get_string('deadlinesignored', 'workshop');
                $task->completed = 'info';
                $phase->tasks['deadlinesignored'] = $task;
            }
        }
        return $phase;
    }
}
