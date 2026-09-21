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
    use assign_grading_summary;

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
        $instance = $this->instance;
        $o = '';

        // can_view_grades(): the reader may.
        $o .= $this->submission_actionmenu();
        $o .= $this->render_assign_grading_summary($this->get_assign_grading_summary_renderable());

        // can_view_submission(): the reader may see their own.
        $o .= $this->view_submission_action_bar($instance);
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
