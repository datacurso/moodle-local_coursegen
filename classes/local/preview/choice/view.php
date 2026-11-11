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

namespace local_coursegen\local\preview\choice;

use context;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

/**
 * mod_choice's view code, run here against the payload instead of the database.
 *
 * Copied from mod/choice/view.php, lib.php and renderer.php (Moodle 4.5).
 * Method names are the functions they came from. What changed: the choice
 * and its options are read from a json_store; the context is handed in; the
 * form posts back to the preview; and there are no responses, because
 * responses are the readers' and a template carries none, so the branches
 * that only a response can reach (your selection, remove my choice, the
 * report link, the response actions) never draw.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use choice_options_view;
    use choice_results_view;
    use choice_results_table;

    /** mod/choice/lib.php. */
    const CHOICE_PUBLISH_ANONYMOUS = '0';
    /** mod/choice/lib.php. */
    const CHOICE_PUBLISH_NAMES = '1';
    /** mod/choice/lib.php. */
    const CHOICE_SHOWRESULTS_NOT = '0';
    /** mod/choice/lib.php. */
    const CHOICE_SHOWRESULTS_AFTER_ANSWER = '1';
    /** mod/choice/lib.php. */
    const CHOICE_SHOWRESULTS_AFTER_CLOSE = '2';
    /** mod/choice/lib.php. */
    const CHOICE_SHOWRESULTS_ALWAYS = '3';
    /** mod/choice/lib.php. */
    const CHOICE_DISPLAY_HORIZONTAL = '0';
    /** mod/choice/lib.php. */
    const CHOICE_DISPLAY_VERTICAL = '1';

    /** @var json_store */
    protected json_store $store;
    /** @var stdClass The choice, as choice_get_choice() builds it. */
    protected stdClass $choice;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var moodle_url Where the preview is read; the form posts back to it. */
    protected moodle_url $here;

    /**
     * Constructor.
     *
     * @param stdClass $choice The choice row.
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here
     */
    public function __construct(
        stdClass $choice,
        stdClass $cm,
        stdClass $course,
        context $context,
        json_store $store,
        moodle_url $here
    ) {
        $this->store = $store;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->here = $here;
        $this->choice = $this->choice_get_choice($choice);
    }

    /**
     * mod/choice/lib.php choice_get_choice(), against the store.
     *
     * @param stdClass $choice
     * @return stdClass
     */
    protected function choice_get_choice(stdClass $choice): stdClass {
        $choice->option = [];
        $choice->maxanswers = [];
        $options = $this->store->get_records('choice_options', ['choiceid' => $choice->id], 'id');
        foreach ($options as $option) {
            $choice->option[$option->id] = $option->text;
            $choice->maxanswers[$option->id] = $option->maxanswers;
        }
        return $choice;
    }

    /**
     * mod/choice/view.php from the header to the footer.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT, $USER;

        $choice = $this->choice;
        $cm = $this->cm;
        $context = $this->context;
        $out = '';

        // Nobody has answered a choice that is being previewed: every user
        // who may choose is still to answer, and none is listed, because the
        // users are not part of a template.
        $allresponses = [0 => []];

        // choice_show_reportlink() is drawn only on a page without secondary
        // navigation; a module page has it.

        $out .= $OUTPUT->render_from_template('local_coursegen/preview_container', ['classes' => 'clearer', 'content' => '']);

        $timenow = time();
        $current = [];
        // The "your selection" box needs a response; there is none.

        /// Print the form
        $choiceopen = true;
        if ((!empty($choice->timeopen)) && ($choice->timeopen > $timenow)) {
            if ($choice->showpreview) {
                $previewingtext = get_string('previewing', 'choice');
                $out .= $OUTPUT->box($previewingtext, 'generalbox alert');
            } else {
                return $out;
            }
        } else if ((!empty($choice->timeclose)) && ($timenow > $choice->timeclose)) {
            $choiceopen = false;
        }

        if ( (!$current or $choice->allowupdate) and $choiceopen and $this->can_choose()) {

            // Show information on how the results will be published to students.
            $publishinfo = $this->publish_info($choice);

            // Show info if necessary.
            if (!empty($publishinfo)) {
                $out .= $OUTPUT->notification($publishinfo, 'info');
            }

            // They haven't made their choice yet or updates allowed and choice is open.
            $options = $this->choice_prepare_options($choice, $USER, $cm, $allresponses);
            $out .= $this->display_options($options, $cm->id, $choice->display, $choice->allowmultiple);
            $choiceformshown = true;
        } else {
            $choiceformshown = false;
        }

        // The guest and not-enrolled notices are about the reader's standing
        // in a course that does not exist yet; a reader who may not choose
        // sees the results, or that there are none to see, as below.

        // print the results at the bottom of the screen
        if ($this->choice_can_view_results($choice, $current, $choiceopen)) {
            [$results, $heading] = $this->prepare_choice_show_results($choice, $this->course, $cm, $allresponses);
            $out .= $heading;
            $out .= $this->results_section($results);
        } else if (!$choiceformshown) {
            $noresultstext = get_string('noresultsviewable', 'choice');
            $out .= $OUTPUT->box($noresultstext);
        }

        return $out;
    }

    /**
     * The results block: the "full results published" heading, when the
     * choice publishes them, followed by the results table itself. Nothing
     * is drawn when there are no results to show.
     *
     * @param stdClass|false $results
     * @return string
     */
    protected function results_section($results): string {
        global $OUTPUT;

        if (!$results) {
            return '';
        }

        $out = '';
        if ($results->publish) { // If set to publish full results, display a heading for the responses section.
            $responsestext = get_string("responses", "choice");
            $heading = format_string($responsestext);
            $out .= $OUTPUT->heading($heading, 3, 'mt-4');
        }
        // The group menu needs the course's groups; a template course
        // has none to offer a preview.
        $resultstable = $this->display_result($results);
        $out .= $OUTPUT->box($resultstable);
        return $out;
    }

    /**
     * How the choice tells the reader its results will be published, mapped
     * by showresults rather than switched on. CHOICE_SHOWRESULTS_ALWAYS needs
     * no message, since it is already obvious that the results are published.
     *
     * @param stdClass $choice
     * @return string|null
     */
    protected function publish_info(stdClass $choice): ?string {
        $anonymous = $choice->publish == self::CHOICE_PUBLISH_ANONYMOUS;
        $afterstring = 'publishinfofullafter';
        $closestring = 'publishinfofullclose';
        if ($anonymous) {
            $afterstring = 'publishinfoanonafter';
            $closestring = 'publishinfoanonclose';
        }
        $strings = [
            self::CHOICE_SHOWRESULTS_NOT => 'publishinfonever',
            self::CHOICE_SHOWRESULTS_AFTER_ANSWER => $afterstring,
            self::CHOICE_SHOWRESULTS_AFTER_CLOSE => $closestring,
        ];
        if (!array_key_exists($choice->showresults, $strings)) {
            return null;
        }
        return get_string($strings[$choice->showresults], 'choice');
    }

    /**
     * Whether the reader may answer: is_enrolled($context, null, 'mod/choice:choose').
     *
     * Enrolment is in the course the choice will be created in, which does
     * not exist yet, so the capability alone decides.
     *
     * @return bool
     */
    protected function can_choose(): bool {
        return has_capability('mod/choice:choose', $this->context);
    }
}
