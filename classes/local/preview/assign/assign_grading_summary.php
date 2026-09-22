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
        $table = $OUTPUT->render_from_template('local_coursegen/preview_table', [
            'classes' => 'generaltable table-bordered',
            'rows' => $this->grading_summary_rows($summary),
        ]);

        $o = '';
        $o .= $OUTPUT->container_start('gradingsummary');
        $o .= $OUTPUT->heading(get_string('gradingsummary', 'assign'), 3);
        $o .= $OUTPUT->box($table, 'boxaligncenter gradingsummarytable');
        $o .= $OUTPUT->container_end();
        return $o;
    }

    /**
     * renderer::render_assign_grading_summary(), the table's rows.
     *
     * @param stdClass $summary
     * @return array Rows of {first, second}, ready for preview_table.mustache.
     */
    protected function grading_summary_rows(stdClass $summary): array {
        $rows = [];

        // Visibility Status.
        $visible = get_string('no');
        if (!$summary->isvisible) {
            $visible = get_string('yes');
        }
        $rows[] = ['first' => get_string('hiddenfromstudents'), 'second' => $visible];

        // Status.
        $participantslabel = get_string('numberofparticipants', 'assign');
        if ($summary->teamsubmission) {
            $participantslabel = get_string('numberofteams', 'assign');
        }
        $rows[] = ['first' => $participantslabel, 'second' => $summary->participantcount];

        // Drafts count and dont show drafts count when using offline assignment.
        if ($summary->submissiondraftsenabled && $summary->submissionsenabled) {
            $rows[] = [
                'first' => get_string('numberofdraftsubmissions', 'assign'),
                'second' => $summary->submissiondraftscount,
            ];
        }

        // Submitted for grading.
        if ($summary->submissionsenabled) {
            $rows[] = [
                'first' => get_string('numberofsubmittedassignments', 'assign'),
                'second' => $summary->submissionssubmittedcount,
            ];

            if (!$summary->teamsubmission) {
                $rows[] = [
                    'first' => get_string('numberofsubmissionsneedgrading', 'assign'),
                    'second' => $summary->submissionsneedgradingcount,
                ];
            }
        }

        $rows = array_merge($rows, $this->grading_summary_time_rows($summary));

        // Add time limit info if there is one.
        $timelimitenabled = get_config('assign', 'enabletimelimit');
        if ($timelimitenabled && $summary->timelimit > 0) {
            $rows[] = ['first' => get_string('timelimit', 'assign'), 'second' => format_time($summary->timelimit)];
        }

        return $rows;
    }

    /**
     * renderer::render_assign_grading_summary(), the due-date-dependent rows.
     *
     * @param stdClass $summary
     * @return array Rows of {first, second}, ready for preview_table.mustache.
     */
    protected function grading_summary_time_rows(stdClass $summary): array {
        if (!$summary->duedate) {
            return [];
        }

        $time = time();
        $duedate = $summary->duedate;

        // Time remaining.
        $timeremaining = format_time($duedate - $time);
        if ($summary->courserelativedatesmode) {
            $timeremaining = get_string('relativedatessubmissiontimeleft', 'mod_assign');
        } else if ($duedate - $time <= 0) {
            $timeremaining = get_string('assignmentisdue', 'assign');
        }
        $rows = [['first' => get_string('timeremaining', 'assign'), 'second' => $timeremaining]];

        if ($duedate < $time && $summary->cutoffdate) {
            $latesubmissions = get_string('nomoresubmissionsaccepted', 'assign');
            if ($summary->cutoffdate > $time) {
                $latesubmissions = get_string('latesubmissionsaccepted', 'assign', userdate($summary->cutoffdate));
            }
            $rows[] = ['first' => get_string('latesubmissions', 'assign'), 'second' => $latesubmissions];
        }

        return $rows;
    }
}
