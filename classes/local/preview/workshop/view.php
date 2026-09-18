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
use html_writer;
use local_coursegen\local\preview\json_store;
use moodle_url;
use pix_icon;
use single_button;
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

    /**
     * Ported from mod_workshop_renderer::render_action_buttons().
     *
     * The one button any phase has is the reader's own submission; whether
     * they have one is theirs, not the template's, so it is looked for in
     * rows the payload never carries and offered where the workshop allows.
     *
     * @return string
     */
    protected function render_action_buttons(): string {
        global $OUTPUT;
        $output = '';
        $workshop = $this->workshop;

        // The one button any phase offers starts the reader's own submission.
        // Nobody may act on an activity that does not exist: it is not offered.
        return $output;
    }

    /**
     * Ported from workshop_user_plan::__construct().
     *
     * Fills $this->phases the way the plan fills its own. Where the plan
     * counts the database it counts the store, and the store has no rows for
     * what people have done.
     */
    protected function user_plan(): void {
        $workshop = $this->workshop;
        $userid = $this->userid;
        $this->phases = [];

        // Phases: * SETUP | submission | assessment | evaluation | closed.
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
        $this->phases[workshop::PHASE_SETUP] = $phase;

        // Phases: setup | * SUBMISSION | assessment | evaluation | closed.
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
        $this->phases[workshop::PHASE_SUBMISSION] = $phase;

        // Phases: setup | submission | * ASSESSMENT | evaluation | closed.
        $phase = new stdClass();
        $phase->title = get_string('phaseassessment', 'workshop');
        $phase->tasks = [];
        $phase->isreviewer = has_capability('mod/workshop:peerassess', $this->context, $userid);
        if ($workshop->phase == workshop::PHASE_SUBMISSION && $workshop->phaseswitchassessment
                && has_capability('mod/workshop:switchphase', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('switchphase30auto', 'mod_workshop', workshop::timestamp_formats($workshop->submissionend));
            $task->completed = 'info';
            $phase->tasks['autoswitchinfo'] = $task;
        }
        if ($workshop->useexamples && $workshop->examplesmode == workshop::EXAMPLES_BEFORE_ASSESSMENT
                && $phase->isreviewer && !has_capability('mod/workshop:manageexamples', $this->context, $userid)) {
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
            } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                $task->completed = false;
            }
            $phase->tasks['examples'] = $task;
        }
        if (empty($phase->tasks['examples']) || !empty($phase->tasks['examples']->completed)) {
            $phase->assessments = $this->get_assessments_by_reviewer($userid);
            $numofpeers     = 0;    // Number of allocated peer-assessments.
            $numofpeerstodo = 0;    // Number of peer-assessments to do.
            $numofself      = 0;    // Number of allocated self-assessments - should be 0 or 1.
            $numofselftodo  = 0;    // Number of self-assessments to do - should be 0 or 1.
            foreach ($phase->assessments as $a) {
                if ($a->authorid == $userid) {
                    $numofself++;
                    if (is_null($a->grade)) {
                        $numofselftodo++;
                    }
                } else {
                    $numofpeers++;
                    if (is_null($a->grade)) {
                        $numofpeerstodo++;
                    }
                }
            }
            unset($a);
            if ($numofpeers) {
                $task = new stdClass();
                if ($numofpeerstodo == 0) {
                    $task->completed = true;
                } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                    $task->completed = false;
                }
                $a = new stdClass();
                $a->total = $numofpeers;
                $a->todo  = $numofpeerstodo;
                $task->title = get_string('taskassesspeers', 'workshop');
                $task->details = get_string('taskassesspeersdetails', 'workshop', $a);
                unset($a);
                $phase->tasks['assesspeers'] = $task;
            }
            if ($workshop->useselfassessment && $numofself) {
                $task = new stdClass();
                if ($numofselftodo == 0) {
                    $task->completed = true;
                } else if ($workshop->phase > workshop::PHASE_ASSESSMENT) {
                    $task->completed = false;
                }
                $task->title = get_string('taskassessself', 'workshop');
                $phase->tasks['assessself'] = $task;
            }
        }
        if ($workshop->assessmentstart) {
            $task = new stdClass();
            $task->title = get_string('assessmentstartdatetime', 'workshop',
                workshop::timestamp_formats($workshop->assessmentstart));
            $task->completed = 'info';
            $phase->tasks['assessmentstartdatetime'] = $task;
        }
        if ($workshop->assessmentend) {
            $task = new stdClass();
            $task->title = get_string('assessmentenddatetime', 'workshop',
                workshop::timestamp_formats($workshop->assessmentend));
            $task->completed = 'info';
            $phase->tasks['assessmentenddatetime'] = $task;
        }
        if (isset($phase->tasks['assessmentstartdatetime']) || isset($phase->tasks['assessmentenddatetime'])) {
            if (has_capability('mod/workshop:ignoredeadlines', $this->context, $userid)) {
                $task = new stdClass();
                $task->title = get_string('deadlinesignored', 'workshop');
                $task->completed = 'info';
                $phase->tasks['deadlinesignored'] = $task;
            }
        }
        $this->phases[workshop::PHASE_ASSESSMENT] = $phase;

        // Phases: setup | submission | assessment | * EVALUATION | closed.
        $phase = new stdClass();
        $phase->title = get_string('phaseevaluation', 'workshop');
        $phase->tasks = [];
        if (has_capability('mod/workshop:overridegrades', $this->context)) {
            $expected = $this->count_potential_authors(false);
            $calculated = 0;
            foreach ($this->store->get_records('workshop_submissions', ['workshopid' => $workshop->id]) as $s) {
                if (!is_null($s->grade ?? null) || !is_null($s->gradeover ?? null)) {
                    $calculated++;
                }
            }
            $task = new stdClass();
            $task->title = get_string('calculatesubmissiongrades', 'workshop');
            $a = new stdClass();
            $a->expected    = $expected;
            $a->calculated  = $calculated;
            $task->details  = get_string('calculatesubmissiongradesdetails', 'workshop', $a);
            if ($calculated >= $expected) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_EVALUATION) {
                $task->completed = false;
            }
            $phase->tasks['calculatesubmissiongrade'] = $task;

            $expected = $this->count_potential_reviewers(false);
            $calculated = 0;
            foreach ($this->store->get_records('workshop_aggregations', ['workshopid' => $workshop->id]) as $g) {
                if (!is_null($g->gradinggrade ?? null)) {
                    $calculated++;
                }
            }
            $task = new stdClass();
            $task->title = get_string('calculategradinggrades', 'workshop');
            $a = new stdClass();
            $a->expected    = $expected;
            $a->calculated  = $calculated;
            $task->details  = get_string('calculategradinggradesdetails', 'workshop', $a);
            if ($calculated >= $expected) {
                $task->completed = true;
            } else if ($workshop->phase > workshop::PHASE_EVALUATION) {
                $task->completed = false;
            }
            $phase->tasks['calculategradinggrade'] = $task;

        } else if ($workshop->phase == workshop::PHASE_EVALUATION) {
            $task = new stdClass();
            $task->title = get_string('evaluategradeswait', 'workshop');
            $task->completed = 'info';
            $phase->tasks['evaluateinfo'] = $task;
        }

        if (has_capability('moodle/course:manageactivities', $this->context, $userid)) {
            $task = new stdClass();
            $task->title = get_string('taskconclusion', 'workshop');
            $task->link = $this->updatemod_url();
            if (trim($workshop->conclusion)) {
                $task->completed = true;
            } else if ($workshop->phase >= workshop::PHASE_EVALUATION) {
                $task->completed = false;
            }
            $phase->tasks['conclusion'] = $task;
        }

        $this->phases[workshop::PHASE_EVALUATION] = $phase;

        // Phases: setup | submission | assessment | evaluation | * CLOSED.
        $phase = new stdClass();
        $phase->title = get_string('phaseclosed', 'workshop');
        $phase->tasks = [];
        $this->phases[workshop::PHASE_CLOSED] = $phase;

        // Polish data, set default values if not done explicitly.
        foreach ($this->phases as $phasecode => $phase) {
            $phase->title = isset($phase->title) ? $phase->title : '';
            $phase->tasks = isset($phase->tasks) ? $phase->tasks : [];
            if ($phasecode == $workshop->phase) {
                $phase->active = true;
            } else {
                $phase->active = false;
            }
            if (!isset($phase->actions)) {
                $phase->actions = [];
            }

            foreach ($phase->tasks as $taskcode => $task) {
                $task->title = isset($task->title) ? $task->title : '';
                $task->link = isset($task->link) ? $task->link : null;
                $task->details = isset($task->details) ? $task->details : '';
                $task->completed = isset($task->completed) ? $task->completed : null;
            }
        }

        // Add phase switching actions.
        if (has_capability('mod/workshop:switchphase', $this->context, $userid)) {
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

    /**
     * workshop_strategy::form_ready(): whether the grading strategy has any dimensions.
     *
     * Every strategy keeps its dimensions in a table named after it, and is
     * ready once it has one; that is what its own form_ready() counts.
     *
     * @return bool
     */
    protected function form_ready(): bool {
        $strategy = clean_param((string) ($this->workshop->strategy ?? ''), PARAM_PLUGIN);
        if ($strategy === '') {
            return false;
        }
        return $this->store->count_records('workshopform_' . $strategy, ['workshopid' => $this->workshop->id]) > 0;
    }

    /**
     * workshop::assessing_examples_allowed().
     *
     * @return bool
     */
    protected function assessing_examples_allowed(): bool {
        if (empty($this->workshop->useexamples)) {
            return false;
        }
        if (workshop::EXAMPLES_VOLUNTARY == $this->workshop->examplesmode) {
            return true;
        }
        if (workshop::EXAMPLES_BEFORE_SUBMISSION == $this->workshop->examplesmode
                && workshop::PHASE_SUBMISSION == $this->workshop->phase) {
            return true;
        }
        if (workshop::EXAMPLES_BEFORE_ASSESSMENT == $this->workshop->examplesmode
                && workshop::PHASE_ASSESSMENT == $this->workshop->phase) {
            return true;
        }
        return false;
    }

    /**
     * workshop::creating_submission_allowed(), for a reader who may ignore deadlines.
     *
     * @param int $userid
     * @return bool
     */
    protected function creating_submission_allowed(int $userid): bool {
        $now = time();
        $ignoredeadlines = has_capability('mod/workshop:ignoredeadlines', $this->context, $userid);

        if ($this->workshop->latesubmissions) {
            if ($this->workshop->phase != workshop::PHASE_SUBMISSION && $this->workshop->phase != workshop::PHASE_ASSESSMENT) {
                // Late submissions are allowed in the submission and assessment phase only.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionstart) && $this->workshop->submissionstart > $now) {
                // Late submissions are not allowed before the submission start.
                return false;
            }
            return true;

        } else {
            if ($this->workshop->phase != workshop::PHASE_SUBMISSION) {
                // Submissions are allowed during the submission phase only.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionstart) && $this->workshop->submissionstart > $now) {
                // If enabled, submitting is not allowed before the date/time defined in the mod_form.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionend) && $now > $this->workshop->submissionend ) {
                // If enabled, submitting is not allowed after the date/time defined in the mod_form
                // unless late submission is allowed.
                return false;
            }
            return true;
        }
    }

    /**
     * workshop::get_submission_by_author(), over the store.
     *
     * @param int $authorid
     * @return stdClass|false
     */
    protected function get_submission_by_author(int $authorid) {
        return $this->store->get_record('workshop_submissions',
            ['workshopid' => $this->workshop->id, 'example' => 0, 'authorid' => $authorid]);
    }

    /**
     * workshop::get_examples_for_manager(), over the store.
     *
     * @return array
     */
    protected function get_examples_for_manager(): array {
        return $this->store->get_records('workshop_submissions', ['workshopid' => $this->workshop->id, 'example' => 1]);
    }

    /**
     * workshop_user_plan::get_examples(): the reader's example submissions with their grade.
     *
     * @return array
     */
    protected function get_examples(): array {
        $examples = [];
        foreach ($this->get_examples_for_manager() as $example) {
            $assessment = $this->store->get_record('workshop_assessments',
                ['submissionid' => $example->id, 'reviewerid' => $this->userid, 'weight' => 0]);
            $example->grade = $assessment ? ($assessment->grade ?? null) : null;
            $examples[$example->id] = $example;
        }
        return $examples;
    }

    /**
     * workshop::get_assessments_by_reviewer(), over the store.
     *
     * @param int $reviewerid
     * @return array
     */
    protected function get_assessments_by_reviewer(int $reviewerid): array {
        $assessments = [];
        foreach ($this->store->get_records('workshop_assessments', ['reviewerid' => $reviewerid]) as $assessment) {
            $submission = $this->store->get_record('workshop_submissions',
                ['id' => $assessment->submissionid, 'workshopid' => $this->workshop->id, 'example' => 0]);
            if (!$submission) {
                continue;
            }
            $assessment->authorid = $submission->authorid;
            $assessments[$assessment->id] = $assessment;
        }
        return $assessments;
    }

    /**
     * workshop::count_potential_authors(): who could submit, which is who is enrolled.
     *
     * The enrolled are the template course's, not the template's, and the
     * plan counts them only to say how many submissions to expect.
     *
     * @param bool $musthavesubmission
     * @return int
     */
    protected function count_potential_authors(bool $musthavesubmission = true): int {
        return 0;
    }

    /**
     * workshop::count_potential_reviewers(), likewise.
     *
     * @param bool $musthavesubmission
     * @return int
     */
    protected function count_potential_reviewers(bool $musthavesubmission = true): int {
        return 0;
    }

    /**
     * workshop::updatemod_url(): the activity's settings, from the preview.
     *
     * @return moodle_url
     */
    protected function updatemod_url(): moodle_url {
        return $this->url_to(['update' => 1, 'return' => 1]);
    }

    /**
     * workshop::editform_url().
     *
     * @return moodle_url
     */
    protected function editform_url(): moodle_url {
        return $this->url_to(['editform' => 1]);
    }

    /**
     * workshop::submission_url().
     *
     * @param int|null $id
     * @return moodle_url
     */
    protected function submission_url($id = null): moodle_url {
        return $this->url_to(['submission' => 1, 'id' => $id]);
    }

    /**
     * workshop::exsubmission_url().
     *
     * @param int $id
     * @return moodle_url
     */
    protected function exsubmission_url($id): moodle_url {
        return $this->url_to(['exsubmission' => 1, 'id' => $id]);
    }

    /**
     * workshop::allocation_url().
     *
     * @param string|null $method
     * @return moodle_url
     */
    protected function allocation_url($method = null): moodle_url {
        $params = ['allocation' => 1];
        if (!empty($method)) {
            $params['method'] = $method;
        }
        return $this->url_to($params);
    }

    /**
     * workshop::switchphase_url().
     *
     * @param int $phasecode
     * @return moodle_url
     */
    protected function switchphase_url($phasecode): moodle_url {
        $phasecode = clean_param($phasecode, PARAM_INT);
        return $this->url_to(['phase' => $phasecode]);
    }

    /**
     * A link to the preview with extra parameters, where the workshop linked to one of its pages.
     *
     * @param array $params
     * @return moodle_url
     */
    protected function url_to(array $params): moodle_url {
        $url = new moodle_url($this->here);
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $url->param($name, $value);
            }
        }
        return $url;
    }
}
