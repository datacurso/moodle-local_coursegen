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

namespace local_coursegen\local\preview\assign;

use context;
use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use local_coursegen\local\preview\json_store;
use moodle_url;
use single_button;
use stdClass;

/**
 * mod_assign's view code, ported to run against the payload.
 *
 * Copied from mod/assign/locallib.php (view_submission_page() and what it
 * reaches), mod/assign/classes/output/renderer.php
 * (render_assign_grading_summary(), add_table_row_tuple()) and
 * mod/assign/classes/output/{actionmenu,user_submission_actionmenu}.php
 * (Moodle 4.5). Method names are the methods they came from.
 *
 * What changed: the assignment and its plugins' settings are read from a
 * json_store; the reader is someone who may grade and may submit, which is
 * who reads a preview; there are no participants, submissions or grades,
 * because those are the readers' and a template carries none, so every count
 * is zero and everything only a submission can reach (its status, the
 * feedback, the history) never draws; and the buttons lead back to the
 * preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** mod/assign/locallib.php. */
    const ASSIGN_SUBMISSION_STATUS_NEW = 'new';

    /** @var json_store */
    protected json_store $store;
    /** @var stdClass The assignment row. */
    protected stdClass $instance;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var moodle_url Where the preview is read; the buttons lead back to it. */
    protected moodle_url $here;

    /**
     * Constructor.
     *
     * @param stdClass $instance The assignment row.
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here
     */
    public function __construct(
        stdClass $instance,
        stdClass $cm,
        stdClass $course,
        context $context,
        json_store $store,
        moodle_url $here
    ) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
    }

    /**
     * assign::view_submission_page(), for a reader who may grade and submit.
     *
     * The header the page draws (assign_header) is the activity header, which
     * the preview page draws itself; a plugin's own header is what its
     * view_header() returns, and the standard plugins return nothing.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;

        $instance = $this->instance;
        $o = '';

        // The real page offers the grading page and a button to add a
        // submission to a reader who may. Nobody may act on an activity that does not exist: neither
        // is offered, and the grading summary, which describes the
        // assignment rather than anyone's work, stays.
        $o .= $this->render_assign_grading_summary($this->get_assign_grading_summary_renderable());
        // view_student_summary() draws the reader's submission status, feedback
        // and history, none of which exist before anyone has submitted; the
        // real page for this reader draws none of them either.

        return $o;
    }

    /**
     * renderer::submission_actionmenu() with output\actionmenu::export_for_template().
     *
     * @return string
     */
    protected function submission_actionmenu(): string {
        global $OUTPUT;
        // has_capability('mod/assign:grade'): the reader may.
        $context = ['gradelink' => $this->here->out(false)];
        return $OUTPUT->render_from_template('mod_assign/submission_actionmenu', $context);
    }

    /**
     * assign::get_assign_grading_summary_renderable(), as plain data.
     *
     * @return stdClass The fields of an assign_grading_summary.
     */
    protected function get_assign_grading_summary_renderable(): stdClass {
        $instance = $this->instance;
        $summary = new stdClass();
        $summary->participantcount = 0;
        $summary->submissiondraftsenabled = (bool) $instance->submissiondrafts;
        $summary->submissiondraftscount = 0;
        $summary->submissionsenabled = $this->is_any_submission_plugin_enabled();
        $summary->submissionssubmittedcount = 0;
        $summary->cutoffdate = (int) $instance->cutoffdate;
        $summary->duedate = (int) $instance->duedate;
        $summary->timelimit = (int) ($instance->timelimit ?? 0);
        $summary->coursemoduleid = (int) $this->cm->id;
        $summary->submissionsneedgradingcount = 0;
        $summary->teamsubmission = (bool) $instance->teamsubmission;
        $summary->warnofungroupedusers = false;
        $summary->courserelativedatesmode = !empty($this->course->relativedatesmode);
        $summary->coursestartdate = (int) ($this->course->startdate ?? 0);
        $summary->cangrade = true;
        // An activity in a template is one the teacher can see; whether it is
        // hidden from students is the course module's, and the payload lists
        // the modules a reader can see.
        $summary->isvisible = true;
        // groups_print_activity_menu() draws a menu when the module uses
        // groups and the course has some; a template has no groups.
        $summary->cm = null;
        return $summary;
    }

    /**
     * renderer::render_assign_grading_summary().
     *
     * @param stdClass $summary
     * @return string
     */
    protected function render_assign_grading_summary(stdClass $summary): string {
        global $OUTPUT;
        // Create a table for the data.
        $o = '';
        $o .= $OUTPUT->container_start('gradingsummary');
        $o .= $OUTPUT->heading(get_string('gradingsummary', 'assign'), 3);

        $o .= $OUTPUT->box_start('boxaligncenter gradingsummarytable');
        $t = new html_table();
        $t->attributes['class'] = 'generaltable table-bordered';

        // Visibility Status.
        $cell1content = get_string('hiddenfromstudents');
        $cell2content = (!$summary->isvisible) ? get_string('yes') : get_string('no');
        $this->add_table_row_tuple($t, $cell1content, $cell2content);

        // Status.
        if ($summary->teamsubmission) {
            $cell1content = get_string('numberofteams', 'assign');
        } else {
            $cell1content = get_string('numberofparticipants', 'assign');
        }

        $cell2content = $summary->participantcount;
        $this->add_table_row_tuple($t, $cell1content, $cell2content);

        // Drafts count and dont show drafts count when using offline assignment.
        if ($summary->submissiondraftsenabled && $summary->submissionsenabled) {
            $cell1content = get_string('numberofdraftsubmissions', 'assign');
            $cell2content = $summary->submissiondraftscount;
            $this->add_table_row_tuple($t, $cell1content, $cell2content);
        }

        // Submitted for grading.
        if ($summary->submissionsenabled) {
            $cell1content = get_string('numberofsubmittedassignments', 'assign');
            $cell2content = $summary->submissionssubmittedcount;
            $this->add_table_row_tuple($t, $cell1content, $cell2content);

            if (!$summary->teamsubmission) {
                $cell1content = get_string('numberofsubmissionsneedgrading', 'assign');
                $cell2content = $summary->submissionsneedgradingcount;
                $this->add_table_row_tuple($t, $cell1content, $cell2content);
            }
        }

        $time = time();
        if ($summary->duedate) {
            // Time remaining.
            $duedate = $summary->duedate;
            $cell1content = get_string('timeremaining', 'assign');
            if ($summary->courserelativedatesmode) {
                $cell2content = get_string('relativedatessubmissiontimeleft', 'mod_assign');
            } else {
                if ($duedate - $time <= 0) {
                    $cell2content = get_string('assignmentisdue', 'assign');
                } else {
                    $cell2content = format_time($duedate - $time);
                }
            }

            $this->add_table_row_tuple($t, $cell1content, $cell2content);

            if ($duedate < $time) {
                $cell1content = get_string('latesubmissions', 'assign');
                $cutoffdate = $summary->cutoffdate;
                if ($cutoffdate) {
                    if ($cutoffdate > $time) {
                        $cell2content = get_string('latesubmissionsaccepted', 'assign', userdate($summary->cutoffdate));
                    } else {
                        $cell2content = get_string('nomoresubmissionsaccepted', 'assign');
                    }

                    $this->add_table_row_tuple($t, $cell1content, $cell2content);
                }
            }

        }

        // Add time limit info if there is one.
        $timelimitenabled = get_config('assign', 'enabletimelimit');
        if ($timelimitenabled && $summary->timelimit > 0) {
            $cell1content = get_string('timelimit', 'assign');
            $cell2content = format_time($summary->timelimit);
            $this->add_table_row_tuple($t, $cell1content, $cell2content, [], []);
        }

        // All done - write the table.
        $o .= html_writer::table($t);
        $o .= $OUTPUT->box_end();

        // Close the container and insert a spacer.
        $o .= $OUTPUT->container_end();
        $o .= html_writer::end_tag('center');

        return $o;
    }

    /**
     * renderer::add_table_row_tuple().
     *
     * @param html_table $table
     * @param mixed $first
     * @param mixed $second
     * @param array $firstattributes
     * @param array $secondattributes
     */
    private function add_table_row_tuple(html_table $table, $first, $second, $firstattributes = [],
            $secondattributes = []) {
        $row = new html_table_row();
        $cell1 = new html_table_cell($first);
        $cell1->header = true;
        if (!empty($firstattributes)) {
            $cell1->attributes = $firstattributes;
        }
        $cell2 = new html_table_cell($second);
        if (!empty($secondattributes)) {
            $cell2->attributes = $secondattributes;
        }
        $row->cells = array($cell1, $cell2);
        $table->data[] = $row;
    }

    /**
     * assign::view_submission_action_bar() with user_submission_actionmenu::export_for_template().
     *
     * Nobody has submitted, so there is nothing to submit and nothing to
     * remove; what there is, when any submission plugin is on, is the button
     * to add a submission, and the time-limit variant of it when the
     * assignment has one.
     *
     * @param stdClass $instance
     * @return string
     */
    protected function view_submission_action_bar(stdClass $instance): string {
        global $OUTPUT;

        $showsubmit = false;
        $showedit = $this->is_any_submission_plugin_enabled();

        $data = ['edit' => false, 'submit' => false, 'remove' => false, 'previoussubmission' => false];
        if ($showedit) {
            $url = $this->here;
            $button = new single_button($url, get_string('editsubmission', 'mod_assign'), 'get');
            $data['edit'] = [
                'button' => $button->export_for_template($OUTPUT),
            ];
            // The status is new: nothing has been submitted.
            $timelimitenabled = get_config('assign', 'enabletimelimit');
            if ($timelimitenabled && !empty($instance->timelimit)) {
                $confirmation = new \confirm_action(
                    get_string('confirmstart', 'assign', format_time($instance->timelimit)),
                    null,
                    get_string('beginassignment', 'assign')
                );
                $beginbutton = new \action_link(
                    $url,
                        get_string('beginassignment', 'assign'),
                        $confirmation,
                        ['class' => 'btn btn-primary']
                );
                $data['edit']['button'] = $beginbutton->export_for_template($OUTPUT);
                $data['edit']['begin'] = true;
                $data['edit']['help'] = '';
            } else {
                $newattemptbutton = new single_button(
                    $url,
                    get_string('addsubmission', 'mod_assign'),
                    'get',
                    single_button::BUTTON_PRIMARY
                );
                $data['edit']['button'] = $newattemptbutton->export_for_template($OUTPUT);
                $data['edit']['help'] = '';
            }
        }
        return $OUTPUT->render_from_template('mod_assign/user_submission_actionmenu', $data);
    }

    /**
     * assign::is_any_submission_plugin_enabled(), from the plugins' saved settings.
     *
     * A plugin is enabled for this assignment when its saved setting says so,
     * and visible when the site has not disabled it; the standard submission
     * plugins all allow submissions.
     *
     * @return bool
     */
    protected function is_any_submission_plugin_enabled(): bool {
        foreach ($this->store->get_records('assign_plugin_config') as $config) {
            if (($config->subtype ?? '') !== 'assignsubmission' || ($config->name ?? '') !== 'enabled') {
                continue;
            }
            if (empty($config->value)) {
                continue;
            }
            if (get_config('assignsubmission_' . $config->plugin, 'disabled')) {
                continue;
            }
            return true;
        }
        return false;
    }
}
