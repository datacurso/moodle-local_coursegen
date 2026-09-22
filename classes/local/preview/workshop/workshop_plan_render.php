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

use pix_icon;
use stdClass;
use workshop;

/**
 * Draws the phases timeline and what each phase has to show, kept apart from
 * view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_plan_render {
    /**
     * Ported from mod_workshop_renderer::render_workshop_user_plan().
     *
     * @return string
     */
    protected function render_workshop_user_plan(): string {
        global $OUTPUT;
        $phases = [];
        foreach ($this->phases as $phasecode => $phase) {
            $phases[] = $this->workshop_phase_row($phasecode, $phase);
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_workshop_plan', [
            'numberofphases' => count($this->phases),
            'phases' => $phases,
        ]);
    }

    /**
     * One phase's row: whether it is the active one, its heading, its
     * switch-phase actions and its own task list, ready for
     * preview_workshop_plan.mustache.
     *
     * @param mixed $phasecode
     * @param stdClass $phase
     * @return array
     */
    protected function workshop_phase_row($phasecode, $phase): array {
        global $OUTPUT;
        $actionshtml = '';
        if ($phase->active) {
            // Mark the section as the current one.
            $icon = $OUTPUT->pix_icon('i/marked', '');
            $actionshtml = get_string('userplancurrentphase', 'workshop') . ' ' . $icon;
        } else {
            // Display a control widget to switch to the given phase or mark the phase as the current one.
            foreach ($phase->actions as $action) {
                if ($action->type !== 'switchphase') {
                    continue;
                }
                $icon = $this->phase_switch_icon($phasecode);
                $actionshtml .= $OUTPUT->action_icon($action->url, $icon, null, null, true);
            }
        }

        $classes = 'phase' . $phasecode;
        if ($phase->active) {
            $classes .= ' active';
        } else {
            $classes .= ' nonactive';
        }

        return [
            'classes' => $classes,
            'active' => $phase->active,
            'title' => $phase->title,
            'actionshtml' => $actionshtml,
            'taskshtml' => $this->helper_user_plan_tasks($phase->tasks),
        ];
    }

    /**
     * Which icon marks a phase's switch-phase action: the scheduled-allocator
     * icon for the one switch a running allocator will make on its own,
     * the plain marker for every other.
     *
     * @param mixed $phasecode
     * @return pix_icon
     */
    protected function phase_switch_icon($phasecode): pix_icon {
        if ($phasecode == workshop::PHASE_ASSESSMENT && $this->workshop->phase == workshop::PHASE_SUBMISSION
                && $this->workshop->phaseswitchassessment) {
            return new pix_icon('i/scheduled', get_string('switchphaseauto', 'mod_workshop'));
        }
        return new pix_icon('i/marker', get_string('switchphase' . $phasecode, 'mod_workshop'));
    }

    /**
     * Ported from mod_workshop_renderer::helper_user_plan_tasks().
     *
     * @param array $tasks
     * @return string
     */
    protected function helper_user_plan_tasks(array $tasks): string {
        global $OUTPUT;
        if (empty($tasks)) {
            return '';
        }
        $rows = [];
        foreach ($tasks as $taskcode => $task) {
            $rows[] = $this->workshop_task_row($task);
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_workshop_tasks', ['tasks' => $rows]);
    }

    /**
     * One task's row, ready for preview_workshop_tasks.mustache.
     *
     * @param stdClass $task
     * @return array
     */
    protected function workshop_task_row(stdClass $task): array {
        $classes = '';
        $accessibilitytext = '';
        if ($task->completed === true) {
            $classes = 'completed';
            $accessibilitytext = get_string('taskdone', 'workshop');
        } else if ($task->completed === false) {
            $classes = 'fail';
            $accessibilitytext = get_string('taskfail', 'workshop');
        } else if ($task->completed === 'info') {
            $classes = 'info';
            $accessibilitytext = get_string('taskinfo', 'workshop');
        } else {
            $accessibilitytext = get_string('tasktodo', 'workshop');
        }

        $link = null;
        if (!is_null($task->link)) {
            $link = $task->link->out(false);
        }

        return [
            'classes' => $classes,
            'accessibilitytext' => $accessibilitytext,
            'link' => $link,
            'title' => $task->title,
            'details' => $task->details,
        ];
    }

    /**
     * The description formatted as format_module_intro() formats it, with the context handed in.
     *
     * @return string
     */
    protected function format_module_intro(): string {
        $options = ['noclean' => true, 'para' => false, 'filter' => true, 'context' => $this->context, 'overflowdiv' => true];
        $intro = file_rewrite_pluginfile_urls((string) $this->workshop->intro, 'pluginfile.php', $this->context->id,
            'mod_workshop', 'intro', null);
        return trim(format_text($intro, (int) $this->workshop->introformat, $options, null));
    }
}
