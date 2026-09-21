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

use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use stdClass;

/**
 * The grading-summary table mod_assign's view page opens with, kept apart
 * from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait assign_grading_summary {
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
}
