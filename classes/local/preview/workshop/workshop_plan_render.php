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
     * Ported from mod_workshop_renderer::view_submissions_report(), the parts a template carries.
     *
     * Each phase opens with what the workshop's author wrote for it - the
     * description and the example submissions while it is being set up, the
     * instructions for submitting, then for assessing, and the conclusion once
     * it is closed - and goes on to what the people in it have done: their own
     * submission, the examples they must assess, the assessments given to
     * them, the grades report and the grading toolbox. The payload carries the
     * first and none of the second, so the author's parts are drawn here and
     * the people's are not; on a fresh workshop the real page draws none of
     * them either.
     *
     * @return string
     */
    protected function view_submissions_report(): string {
        global $OUTPUT;
        $output = '';
        $workshop = $this->workshop;

        switch ($workshop->phase) {
            case workshop::PHASE_SETUP:
                if (trim($workshop->intro)) {
                    $output .= print_collapsible_region_start('', 'workshop-viewlet-intro', get_string('introduction', 'workshop'),
                        'workshop-viewlet-intro-collapsed', false, true);
                    $output .= $OUTPUT->box($this->format_module_intro(), 'generalbox');
                    $output .= print_collapsible_region_end(true);
                }
                if ($workshop->useexamples && has_capability('mod/workshop:manageexamples', $this->context)) {
                    $output .= print_collapsible_region_start('', 'workshop-viewlet-allexamples',
                        get_string('examplesubmissions', 'workshop'), 'workshop-viewlet-allexamples-collapsed', false, true);
                    $output .= $OUTPUT->box_start('generalbox examples');
                    if ($this->form_ready()) {
                        if (!$examples = $this->get_examples_for_manager()) {
                            $output .= $OUTPUT->container(get_string('noexamples', 'workshop'), 'noexamples');
                        }
                        $aurl = new moodle_url($this->exsubmission_url(0), ['edit' => 'on']);
                        $output .= $OUTPUT->single_button($aurl, get_string('exampleadd', 'workshop'), 'get');
                    } else {
                        $output .= $OUTPUT->container(get_string('noexamplesformready', 'workshop'));
                    }
                    $output .= $OUTPUT->box_end();
                    $output .= print_collapsible_region_end(true);
                }
                break;
            case workshop::PHASE_SUBMISSION:
                if (trim($workshop->instructauthors)) {
                    $instructions = file_rewrite_pluginfile_urls($workshop->instructauthors,
                        'pluginfile.php', $this->context->id,
                        'mod_workshop', 'instructauthors', null, workshop::instruction_editors_options($this->context));
                    $output .= print_collapsible_region_start('', 'workshop-viewlet-instructauthors',
                        get_string('instructauthors', 'workshop'),
                        'workshop-viewlet-instructauthors-collapsed', false, true);
                    $output .= $OUTPUT->box(format_text($instructions, $workshop->instructauthorsformat, ['overflowdiv' => true]),
                        ['generalbox', 'instructions']);
                    $output .= print_collapsible_region_end(true);
                }
                break;
            case workshop::PHASE_ASSESSMENT:
                if (trim($workshop->instructreviewers)) {
                    $instructions = file_rewrite_pluginfile_urls($workshop->instructreviewers,
                        'pluginfile.php', $this->context->id,
                        'mod_workshop', 'instructreviewers', null, workshop::instruction_editors_options($this->context));
                    $output .= print_collapsible_region_start('', 'workshop-viewlet-instructreviewers',
                        get_string('instructreviewers', 'workshop'),
                        'workshop-viewlet-instructreviewers-collapsed', false, true);
                    $output .= $OUTPUT->box(format_text($instructions, $workshop->instructreviewersformat,
                        ['overflowdiv' => true]), ['generalbox', 'instructions']);
                    $output .= print_collapsible_region_end(true);
                }
                break;
            case workshop::PHASE_CLOSED:
                if (trim($workshop->conclusion)) {
                    $conclusion = file_rewrite_pluginfile_urls($workshop->conclusion, 'pluginfile.php', $this->context->id,
                        'mod_workshop', 'conclusion', null, workshop::instruction_editors_options($this->context));
                    $output .= print_collapsible_region_start('', 'workshop-viewlet-conclusion',
                        get_string('conclusion', 'workshop'),
                        'workshop-viewlet-conclusion-collapsed', false, true);
                    $output .= $OUTPUT->box(format_text($conclusion, $workshop->conclusionformat, ['overflowdiv' => true]),
                        ['generalbox', 'conclusion']);
                    $output .= print_collapsible_region_end(true);
                }
                break;
        }

        return $output;
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
