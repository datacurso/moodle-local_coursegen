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

use context;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;
use workshop;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/workshop/locallib.php');

/**
 * A workshop's view page, drawn by mod_workshop's own code against the payload.
 *
 * mod/workshop/view.php builds a workshop_user_plan for the reader and hands
 * it to mod_workshop_renderer::view_page(), which draws the action buttons,
 * the current phase's heading, the timeline of phases with their tasks, and
 * under it what the phase has to show. Those pieces are copied here under
 * their own names, with the rows read from the payload instead of the
 * database, the context handed in, and every link the workshop makes to its
 * own pages made to the preview instead.
 *
 * The tasks whose standing depends on what people have done - submissions
 * made, assessments allocated, grades calculated - are counted the way the
 * plan counts them, over rows the payload does not carry, so they count to
 * nothing: a template describes how a workshop is built, not who has used it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use workshop_action_buttons;
    use workshop_plan_orchestrator;
    use workshop_plan_early;
    use workshop_plan_late;
    use workshop_plan_render;
    use workshop_submissions_report;
    use workshop_queries;
    use workshop_urls;

    /** @var stdClass The workshop row. */
    protected stdClass $workshop;

    /** @var stdClass The course module. */
    protected stdClass $cm;

    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var context The context the workshop's text is formatted in. */
    protected context $context;

    /** @var json_store The workshop's rows. */
    protected json_store $store;

    /** @var moodle_url The preview this is drawn on. */
    protected moodle_url $here;

    /** @var int Who is reading. */
    protected int $userid;

    /** @var array The phases, once built (workshop_user_plan::$phases). */
    protected array $phases = [];

    /**
     * Constructor.
     *
     * @param stdClass $workshop The workshop row.
     * @param stdClass $cm The course module.
     * @param stdClass $course The course.
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here The preview page, which every link stays on.
     * @param int $userid The reader.
     */
    public function __construct(stdClass $workshop, stdClass $cm, stdClass $course, context $context,
            json_store $store, moodle_url $here, int $userid) {
        $this->workshop = $workshop;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
        $this->userid = $userid;
        // The backup structure leaves out what is not the workshop's setup;
        // the plan reads these as columns, so they are given their defaults.
        foreach (['phaseswitchassessment' => 0, 'intro' => '', 'instructauthors' => '', 'instructreviewers' => '',
                'conclusion' => '', 'useexamples' => 0, 'examplesmode' => 0, 'submissionstart' => 0,
                'submissionend' => 0, 'assessmentstart' => 0, 'assessmentend' => 0, 'latesubmissions' => 0,
                'useselfassessment' => 0, 'phase' => workshop::PHASE_SETUP] as $field => $default) {
            if (!isset($this->workshop->{$field})) {
                $this->workshop->{$field} = $default;
            }
        }
    }

    /**
     * The page, as mod/workshop/view.php prints it through view_page().
     *
     * @return string
     */
    public function page(): string {
        $this->user_plan();
        $currentphasetitle = '';
        foreach ($this->phases as $phase) {
            if ($phase->active) {
                $currentphasetitle = $phase->title;
            }
        }
        return $this->view_page($currentphasetitle);
    }

    /**
     * The heading the activity header shows, as view.php sets it.
     *
     * @return string
     */
    public function heading(): string {
        global $OUTPUT;
        return $OUTPUT->heading_with_help(format_string($this->workshop->name), 'userplan', 'workshop');
    }

    /**
     * Ported from mod_workshop_renderer::view_page().
     *
     * @param string $currentphasetitle
     * @return string
     */
    protected function view_page(string $currentphasetitle): string {
        global $OUTPUT;
        $output = '';

        $output .= $this->render_action_buttons();
        $output .= $OUTPUT->heading(format_string($currentphasetitle), 2, null, 'mod_workshop-userplanheading');
        $output .= $this->render_workshop_user_plan();
        $output .= $this->view_submissions_report();

        return $output;
    }
}
