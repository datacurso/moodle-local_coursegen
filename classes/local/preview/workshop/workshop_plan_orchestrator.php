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
 * Assembles the five phases built by workshop_plan_early and
 * workshop_plan_late into $this->phases, the way
 * workshop_user_plan::__construct() assembles its own, kept apart from
 * view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_plan_orchestrator {
    /**
     * Copied from workshop_user_plan::__construct().
     *
     * Fills $this->phases the way the plan fills its own. Where the plan
     * counts the database it counts the store, and the store has no rows for
     * what people have done.
     */
    protected function user_plan(): void {
        $this->phases = [];
        $this->phases[workshop::PHASE_SETUP] = $this->build_phase_setup();
        $this->phases[workshop::PHASE_SUBMISSION] = $this->build_phase_submission();
        $this->phases[workshop::PHASE_ASSESSMENT] = $this->build_phase_assessment();
        $this->phases[workshop::PHASE_EVALUATION] = $this->build_phase_evaluation();
        $this->phases[workshop::PHASE_CLOSED] = $this->build_phase_closed();
        $this->polish_phases();
        $this->add_phase_switching_actions();
    }

    /**
     * Copied from workshop_user_plan::__construct(): polish data, set default values if not done explicitly.
     */
    protected function polish_phases(): void {
        $workshop = $this->workshop;
        foreach ($this->phases as $phasecode => $phase) {
            $this->polish_phase($phase, $phasecode == $workshop->phase);
        }
    }

    /**
     * Copied from workshop_user_plan::__construct(): polish one phase's own data.
     *
     * @param stdClass $phase
     * @param bool $active
     */
    protected function polish_phase(stdClass $phase, bool $active): void {
        if (!isset($phase->title)) {
            $phase->title = '';
        }
        if (!isset($phase->tasks)) {
            $phase->tasks = [];
        }
        $phase->active = $active;
        if (!isset($phase->actions)) {
            $phase->actions = [];
        }
        $this->polish_phase_tasks($phase->tasks);
    }

    /**
     * Copied from workshop_user_plan::__construct(): polish one phase's own tasks.
     *
     * @param array $tasks
     */
    protected function polish_phase_tasks(array $tasks): void {
        foreach ($tasks as $task) {
            if (!isset($task->title)) {
                $task->title = '';
            }
            if (!isset($task->link)) {
                $task->link = null;
            }
            if (!isset($task->details)) {
                $task->details = '';
            }
            if (!isset($task->completed)) {
                $task->completed = null;
            }
        }
    }

    /**
     * Copied from workshop_user_plan::__construct(): add phase switching actions.
     */
    protected function add_phase_switching_actions(): void {
        $workshop = $this->workshop;
        $userid = $this->userid;

        if (!has_capability('mod/workshop:switchphase', $this->context, $userid)) {
            return;
        }
        $nextphases = [
            workshop::PHASE_SETUP => workshop::PHASE_SUBMISSION,
            workshop::PHASE_SUBMISSION => workshop::PHASE_ASSESSMENT,
            workshop::PHASE_ASSESSMENT => workshop::PHASE_EVALUATION,
            workshop::PHASE_EVALUATION => workshop::PHASE_CLOSED,
        ];
        foreach ($this->phases as $phasecode => $phase) {
            if ($phase->active) {
                if (isset($nextphases[$workshop->phase])) {
                    $task = new stdClass();
                    $task->title = get_string('switchphasenext', 'mod_workshop');
                    $task->link = $this->switchphase_url($nextphases[$workshop->phase]);
                    $task->details = '';
                    $task->completed = null;
                    $phase->tasks['switchtonextphase'] = $task;
                }

            } else {
                $action = new stdClass();
                $action->type = 'switchphase';
                $action->url  = $this->switchphase_url($phasecode);
                $phase->actions[] = $action;
            }
        }
    }
}
