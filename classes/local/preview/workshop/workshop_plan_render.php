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

use html_writer;
use moodle_url;
use pix_icon;
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
        $o = ''; // Output HTML code.
        $numberofphases = count($this->phases);
        $o .= html_writer::start_tag('div', [
            'class' => 'userplan',
            'aria-labelledby' => 'mod_workshop-userplanheading',
            'aria-describedby' => 'mod_workshop-userplanaccessibilitytitle',
        ]);
        $o .= html_writer::span(get_string('userplanaccessibilitytitle', 'workshop', $numberofphases),
            'accesshide', ['id' => 'mod_workshop-userplanaccessibilitytitle']);
        $o .= html_writer::link('#mod_workshop-userplancurrenttasks', get_string('userplanaccessibilityskip', 'workshop'),
            ['class' => 'accesshide']);
        foreach ($this->phases as $phasecode => $phase) {
            $o .= html_writer::start_tag('dl', ['class' => 'phase']);
            $actions = '';

            if ($phase->active) {
                // Mark the section as the current one.
                $icon = $OUTPUT->pix_icon('i/marked', '');
                $actions .= get_string('userplancurrentphase', 'workshop').' '.$icon;

            } else {
                // Display a control widget to switch to the given phase or mark the phase as the current one.
                foreach ($phase->actions as $action) {
                    if ($action->type === 'switchphase') {
                        if ($phasecode == workshop::PHASE_ASSESSMENT && $this->workshop->phase == workshop::PHASE_SUBMISSION
                                && $this->workshop->phaseswitchassessment) {
                            $icon = new pix_icon('i/scheduled', get_string('switchphaseauto', 'mod_workshop'));
                        } else {
                            $icon = new pix_icon('i/marker', get_string('switchphase'.$phasecode, 'mod_workshop'));
                        }
                        $actions .= $OUTPUT->action_icon($action->url, $icon, null, null, true);
                    }
                }
            }

            if (!empty($actions)) {
                $actions = $OUTPUT->container($actions, 'actions');
            }
            $classes = 'phase' . $phasecode;
            if ($phase->active) {
                $title = html_writer::span($phase->title, 'phasetitle', ['id' => 'mod_workshop-userplancurrenttasks']);
                $classes .= ' active';
            } else {
                $title = html_writer::span($phase->title, 'phasetitle');
                $classes .= ' nonactive';
            }
            $o .= html_writer::start_tag('dt', ['class' => $classes]);
            $o .= $OUTPUT->container($title . $actions);
            $o .= html_writer::start_tag('dd', ['class' => $classes. ' phasetasks']);
            $o .= $this->helper_user_plan_tasks($phase->tasks);
            $o .= html_writer::end_tag('dd');
            $o .= html_writer::end_tag('dl');
        }
        $o .= html_writer::end_tag('div');
        return $o;
    }

    /**
     * Ported from mod_workshop_renderer::helper_user_plan_tasks().
     *
     * @param array $tasks
     * @return string
     */
    protected function helper_user_plan_tasks(array $tasks): string {
        global $OUTPUT;
        $out = '';
        foreach ($tasks as $taskcode => $task) {
            $classes = '';
            $accessibilitytext = '';
            $icon = null;
            if ($task->completed === true) {
                $classes .= ' completed';
                $accessibilitytext .= get_string('taskdone', 'workshop') . ' ';
            } else if ($task->completed === false) {
                $classes .= ' fail';
                $accessibilitytext .= get_string('taskfail', 'workshop') . ' ';
            } else if ($task->completed === 'info') {
                $classes .= ' info';
                $accessibilitytext .= get_string('taskinfo', 'workshop') . ' ';
            } else {
                $accessibilitytext .= get_string('tasktodo', 'workshop') . ' ';
            }
            if (is_null($task->link)) {
                $title = html_writer::tag('span', $accessibilitytext, ['class' => 'accesshide']);
                $title .= $task->title;
            } else {
                $title = html_writer::tag('span', $accessibilitytext, ['class' => 'accesshide']);
                $title .= html_writer::link($task->link, $task->title);
            }
            $title = $OUTPUT->container($title, 'title');
            $details = $OUTPUT->container($task->details, 'details');
            $out .= html_writer::tag('li', $title . $details, ['class' => $classes]);
        }
        if ($out) {
            $out = html_writer::tag('ul', $out, ['class' => 'tasks']);
        }
        return $out;
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
