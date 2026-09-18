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
use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

/**
 * mod_choice's view code, ported to run against the payload.
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
        foreach ($this->store->get_records('choice_options', ['choiceid' => $choice->id], 'id') as $option) {
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

        $out .= '<div class="clearer"></div>';

        $timenow = time();
        $current = [];
        // The "your selection" box needs a response; there is none.

        /// Print the form
        $choiceopen = true;
        if ((!empty($choice->timeopen)) && ($choice->timeopen > $timenow)) {
            if ($choice->showpreview) {
                $out .= $OUTPUT->box(get_string('previewing', 'choice'), 'generalbox alert');
            } else {
                return $out;
            }
        } else if ((!empty($choice->timeclose)) && ($timenow > $choice->timeclose)) {
            $choiceopen = false;
        }

        if ( (!$current or $choice->allowupdate) and $choiceopen and $this->can_choose()) {

            // Show information on how the results will be published to students.
            $publishinfo = null;
            switch ($choice->showresults) {
                case self::CHOICE_SHOWRESULTS_NOT:
                    $publishinfo = get_string('publishinfonever', 'choice');
                    break;

                case self::CHOICE_SHOWRESULTS_AFTER_ANSWER:
                    if ($choice->publish == self::CHOICE_PUBLISH_ANONYMOUS) {
                        $publishinfo = get_string('publishinfoanonafter', 'choice');
                    } else {
                        $publishinfo = get_string('publishinfofullafter', 'choice');
                    }
                    break;

                case self::CHOICE_SHOWRESULTS_AFTER_CLOSE:
                    if ($choice->publish == self::CHOICE_PUBLISH_ANONYMOUS) {
                        $publishinfo = get_string('publishinfoanonclose', 'choice');
                    } else {
                        $publishinfo = get_string('publishinfofullclose', 'choice');
                    }
                    break;

                default:
                    // No need to inform the user in the case of CHOICE_SHOWRESULTS_ALWAYS since it's already obvious that the results are
                    // being published.
                    break;
            }

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
            if ($results) {
                if ($results->publish) { // If set to publish full results, display a heading for the responses section.
                    $out .= html_writer::tag('h3', format_string(get_string("responses", "choice")), ['class' => 'mt-4']);
                }
                // The group menu needs the course's groups; a template course
                // has none to offer a preview.
                $resultstable = $this->display_result($results);
                $out .= $OUTPUT->box($resultstable);
            }
        } else if (!$choiceformshown) {
            $out .= $OUTPUT->box(get_string('noresultsviewable', 'choice'));
        }

        return $out;
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

    /**
     * mod/choice/lib.php choice_prepare_options(), with no answers recorded.
     *
     * @param stdClass $choice
     * @param stdClass $user
     * @param stdClass $coursemodule
     * @param array $allresponses
     * @return array
     */
    protected function choice_prepare_options($choice, $user, $coursemodule, $allresponses) {
        $cdisplay = array('options'=>array());

        $cdisplay['limitanswers'] = $choice->limitanswers;
        $cdisplay['showavailable'] = $choice->showavailable;

        foreach ($choice->option as $optionid => $text) {
            if (isset($text)) { //make sure there are no dud entries in the db with blank text values.
                $option = new stdClass;
                $option->attributes = new stdClass;
                $option->attributes->value = $optionid;
                $option->text = format_string($text);
                $option->maxanswers = $choice->maxanswers[$optionid];
                $option->displaylayout = $choice->display;

                if (isset($allresponses[$optionid])) {
                    $option->countanswers = count($allresponses[$optionid]);
                } else {
                    $option->countanswers = 0;
                }
                if ( $choice->limitanswers && ($option->countanswers >= $option->maxanswers) && empty($option->attributes->checked)) {
                    $option->attributes->disabled = true;
                }
                $cdisplay['options'][] = $option;
            }
        }

        $cdisplay['hascapability'] = $this->can_choose(); //only enrolled users are allowed to make a choice

        if ($choice->showpreview && $choice->timeopen > time()) {
            $cdisplay['previewonly'] = true;
        }

        return $cdisplay;
    }

    /**
     * mod/choice/renderer.php display_options(), posting back to the preview.
     *
     * @param array $options
     * @param int $coursemoduleid
     * @param bool $vertical
     * @param bool $multiple
     * @return string
     */
    protected function display_options($options, $coursemoduleid, $vertical = false, $multiple = false) {
        $layoutclass = 'horizontal';
        if ($vertical) {
            $layoutclass = 'vertical';
        }
        $target = new moodle_url($this->here);
        $attributes = array('method'=>'POST', 'action'=>$target, 'class'=> $layoutclass);
        $disabled = empty($options['previewonly']) ? array() : array('disabled' => 'disabled');

        $html = html_writer::start_tag('form', $attributes);
        $html .= html_writer::start_tag('ul', array('class' => 'choices list-unstyled unstyled'));

        $availableoption = count($options['options']);
        $choicecount = 0;
        foreach ($options['options'] as $option) {
            $choicecount++;
            $html .= html_writer::start_tag('li', array('class' => 'option me-3'));
            if ($multiple) {
                $option->attributes->name = 'answer[]';
                $option->attributes->type = 'checkbox';
            } else {
                $option->attributes->name = 'answer';
                $option->attributes->type = 'radio';
            }
            $option->attributes->id = 'choice_'.$choicecount;
            $option->attributes->class = 'mx-1';

            $labeltext = $option->text;
            if (!empty($option->attributes->disabled)) {
                $labeltext .= ' ' . get_string('full', 'choice');
                $availableoption--;
            }

            if (!empty($options['limitanswers']) && !empty($options['showavailable'])) {
                $labeltext .= html_writer::empty_tag('br');
                $labeltext .= get_string("responsesa", "choice", $option->countanswers);
                $labeltext .= html_writer::empty_tag('br');
                $labeltext .= get_string("limita", "choice", $option->maxanswers);
            }

            $html .= html_writer::empty_tag('input', (array)$option->attributes + $disabled);
            $html .= html_writer::tag('label', $labeltext, array('for'=>$option->attributes->id));
            $html .= html_writer::end_tag('li');
        }
        $html .= html_writer::tag('li','', array('class'=>'clearfloat'));
        $html .= html_writer::end_tag('ul');
        $html .= html_writer::tag('div', '', array('class'=>'clearfloat'));
        $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()));
        $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'action', 'value'=>'makechoice'));
        $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'id', 'value'=>$coursemoduleid));

        if (empty($options['previewonly'])) {
            if (!empty($options['hascapability']) && ($options['hascapability'])) {
                if ($availableoption < 1) {
                    $html .= html_writer::tag('label', get_string('choicefull', 'choice'));
                } else {
                    $html .= html_writer::empty_tag('input', array(
                        'type' => 'submit',
                        'value' => get_string('savemychoice', 'choice'),
                        'class' => 'btn btn-primary'
                    ));
                }
                // "Remove my choice" follows a response; there is none.
            } else {
                $html .= html_writer::tag('label', get_string('havetologin', 'choice'));
            }
        }

        $html .= html_writer::end_tag('form');

        return $html;
    }

    /**
     * mod/choice/lib.php choice_can_view_results().
     *
     * @param stdClass $choice
     * @param array|null $current
     * @param bool|null $choiceopen
     * @return bool
     */
    protected function choice_can_view_results($choice, $current = null, $choiceopen = null) {

        if (is_null($choiceopen)) {
            $timenow = time();

            if ($choice->timeopen != 0 && $timenow < $choice->timeopen) {
                // If the choice is not available, we can't see the results.
                return false;
            }

            if ($choice->timeclose != 0 && $timenow > $choice->timeclose) {
                $choiceopen = false;
            } else {
                $choiceopen = true;
            }
        }

        if ($choice->showresults == self::CHOICE_SHOWRESULTS_ALWAYS or
           ($choice->showresults == self::CHOICE_SHOWRESULTS_AFTER_ANSWER and !empty($current)) or
           ($choice->showresults == self::CHOICE_SHOWRESULTS_AFTER_CLOSE and !$choiceopen)) {
            return true;
        }
        return false;
    }

    /**
     * mod/choice/lib.php prepare_choice_show_results(), returning what it prints too.
     *
     * @param stdClass $choice
     * @param stdClass $course
     * @param stdClass $cm
     * @param array $allresponses
     * @return array [display or false, the heading printed when nobody has answered]
     */
    protected function prepare_choice_show_results($choice, $course, $cm, $allresponses) {
        global $OUTPUT;

        $display = clone($choice);
        $display->coursemoduleid = $cm->id;
        $display->courseid = $course->id;

        if (!empty($choice->showunanswered)) {
            $choice->option[0] = get_string('notanswered', 'choice');
            $choice->maxanswers[0] = 0;
        }

        //overwrite options value;
        $display->options = array();
        $allusers = [];
        foreach ($choice->option as $optionid => $optiontext) {
            $display->options[$optionid] = new stdClass;
            $display->options[$optionid]->text = format_string($optiontext, true,
                ['context' => $this->context]);
            $display->options[$optionid]->maxanswer = $choice->maxanswers[$optionid];

            if (array_key_exists($optionid, $allresponses)) {
                $display->options[$optionid]->user = $allresponses[$optionid];
                $allusers = array_merge($allusers, array_keys($allresponses[$optionid]));
            }
        }
        unset($display->option);
        unset($display->maxanswers);

        $display->numberofuser = count(array_unique($allusers));
        $context = $this->context;
        $display->viewresponsecapability = has_capability('mod/choice:readresponses', $context);
        $display->deleterepsonsecapability = has_capability('mod/choice:deleteresponses',$context);
        $display->fullnamecapability = has_capability('moodle/site:viewfullnames', $context);

        if (empty($allresponses)) {
            return [false, $OUTPUT->heading(get_string("nousersyet"), 3, null)];
        }

        return [$display, ''];
    }

    /**
     * mod/choice/renderer.php display_result().
     *
     * @param stdClass $choices
     * @param bool $forcepublish
     * @return string
     */
    protected function display_result($choices, $forcepublish = false) {
        if (empty($forcepublish)) { //allow the publish setting to be overridden
            $forcepublish = $choices->publish;
        }

        $displaylayout = $choices->display;

        if ($forcepublish) {  //CHOICE_PUBLISH_NAMES
            return $this->display_publish_name_vertical($choices);
        } else {
            return $this->display_publish_anonymous($choices, $displaylayout);
        }
    }

    /**
     * mod/choice/renderer.php display_publish_name_vertical(), with nobody having answered.
     *
     * @param stdClass $choices
     * @return string
     */
    protected function display_publish_name_vertical($choices) {
        global $OUTPUT;
        $html ='';

        $attributes = array('method'=>'POST');
        $attributes['action'] = new moodle_url($this->here);
        $attributes['id'] = 'attemptsform';

        if ($choices->viewresponsecapability) {
            $html .= html_writer::start_tag('form', $attributes);
            $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'id', 'value'=> $choices->coursemoduleid));
            $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=> sesskey()));
            $html .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'mode', 'value'=>'overview'));
        }

        $table = new html_table();
        $table->cellpadding = 0;
        $table->cellspacing = 0;
        $table->attributes['class'] = 'results names table table-bordered';
        $table->tablealign = 'center';
        $table->summary = get_string('responsesto', 'choice', format_string($choices->name));
        $table->data = array();

        $count = 0;
        ksort($choices->options);

        $columns = array();
        $celldefault = new html_table_cell();
        $celldefault->attributes['class'] = 'data';

        // This extra cell is needed in order to support accessibility for screenreader. MDL-30816
        $accessiblecell = new html_table_cell();
        $accessiblecell->scope = 'row';
        $accessiblecell->text = get_string('choiceoptions', 'choice');
        $columns['options'][] = $accessiblecell;

        $usernumberheader = clone($celldefault);
        $usernumberheader->header = true;
        $usernumberheader->attributes['class'] = 'header data';
        $usernumberheader->text = get_string('numberofuser', 'choice');
        $columns['usernumber'][] = $usernumberheader;

        $optionsnames = [];
        foreach ($choices->options as $optionid => $options) {
            $celloption = clone($celldefault);
            $cellusernumber = clone($celldefault);

            if ($choices->showunanswered && $optionid == 0) {
                $headertitle = get_string('notanswered', 'choice');
            } else if ($optionid > 0) {
                $headertitle = format_string($choices->options[$optionid]->text);
                if (!empty($choices->options[$optionid]->user) && count($choices->options[$optionid]->user) > 0) {
                    if (
                        $choices->limitanswers &&
                        (count($choices->options[$optionid]->user) == $choices->options[$optionid]->maxanswer)
                    ) {
                        $headertitle .= ' ' . get_string('full', 'choice');
                    }
                }
            }
            $celltext = $headertitle;

            // Render select/deselect all checkbox for this option.
            if ($choices->viewresponsecapability && $choices->deleterepsonsecapability) {

                // Build the select/deselect all for this option.
                $selectallid = 'select-response-option-' . $optionid;
                $togglegroup = 'responses response-option-' . $optionid;
                $selectalltext = get_string('selectalloption', 'choice', $headertitle);
                $deselectalltext = get_string('deselectalloption', 'choice', $headertitle);
                $mastercheckbox = new \core\output\checkbox_toggleall($togglegroup, true, [
                    'id' => $selectallid,
                    'name' => $selectallid,
                    'value' => 1,
                    'selectall' => $selectalltext,
                    'deselectall' => $deselectalltext,
                    'label' => $selectalltext,
                    'labelclasses' => 'accesshide',
                ]);

                $celltext .= html_writer::div($OUTPUT->render($mastercheckbox));
            }
            $numberofuser = 0;
            if (!empty($options->user) && count($options->user) > 0) {
                $numberofuser = count($options->user);
            }
            if (($choices->limitanswers) && ($choices->showavailable)) {
                $numberofuser .= html_writer::empty_tag('br');
                $numberofuser .= get_string("limita", "choice", $options->maxanswer);
            }
            $celloption->text = html_writer::div($celltext, 'text-center');
            $optionsnames[$optionid] = $celltext;
            $cellusernumber->text = html_writer::div($numberofuser, 'text-center');

            $columns['options'][] = $celloption;
            $columns['usernumber'][] = $cellusernumber;
        }

        $table->head = $columns['options'];
        $table->data[] = new html_table_row($columns['usernumber']);

        $columns = array();

        // This extra cell is needed in order to support accessibility for screenreader. MDL-30816
        $accessiblecell = new html_table_cell();
        $accessiblecell->text = get_string('userchoosethisoption', 'choice');
        $accessiblecell->header = true;
        $accessiblecell->scope = 'row';
        $accessiblecell->attributes['class'] = 'header data';
        $columns[] = $accessiblecell;

        foreach ($choices->options as $optionid => $options) {
            $cell = new html_table_cell();
            $cell->attributes['class'] = 'data';
            // The users who chose each option are listed here; nobody has.
            $columns[] = $cell;
            $count++;
        }
        $row = new html_table_row($columns);
        $table->data[] = $row;

        $html .= html_writer::tag('div', html_writer::table($table), array('class'=>'response'));

        $actiondata = '';
        if ($choices->viewresponsecapability && $choices->deleterepsonsecapability) {
            // Build the select/deselect all for all of options.
            $selectallid = 'select-all-responses';
            $togglegroup = 'responses';
            $selectallcheckbox = new \core\output\checkbox_toggleall($togglegroup, true, [
                'id' => $selectallid,
                'name' => $selectallid,
                'value' => 1,
                'label' => get_string('selectall'),
                'classes' => 'btn-secondary me-1'
            ], true);
            $actiondata .= $OUTPUT->render($selectallcheckbox);

            $actionurl = new moodle_url($this->here,
                    ['sesskey' => sesskey(), 'action' => 'delete_confirmation()']);
            $actionoptions = array('delete' => get_string('delete'));
            foreach ($choices->options as $optionid => $option) {
                if ($optionid > 0) {
                    $actionoptions['choose_'.$optionid] = get_string('chooseoption', 'choice', $option->text);
                }
            }
            $selectattributes = [
                'data-action' => 'toggle',
                'data-togglegroup' => 'responses',
                'data-toggle' => 'action',
            ];
            $selectnothing = ['' => get_string('chooseaction', 'choice')];
            $select = new \single_select($actionurl, 'action', $actionoptions, null, $selectnothing, 'attemptsform');
            $select->set_label(get_string('withselected', 'choice'));
            $select->disabled = true;
            $select->attributes = $selectattributes;

            $actiondata .= $OUTPUT->render($select);
        }
        $html .= html_writer::tag('div', $actiondata, array('class'=>'responseaction'));

        if ($choices->viewresponsecapability) {
            $html .= html_writer::end_tag('form');
        }

        return $html;
    }

    /**
     * mod/choice/renderer.php display_publish_anonymous().
     *
     * @param stdClass $choices
     * @param int $displaylayout
     * @return string
     */
    protected function display_publish_anonymous($choices, $displaylayout) {
        global $OUTPUT;
        $count = 0;
        $data = [];
        $numberofuser = 0;
        $percentageamount = 0;
        foreach ($choices->options as $optionid => $option) {
            if (!empty($option->user)) {
                $numberofuser = count($option->user);
            }
            if($choices->numberofuser > 0) {
                $percentageamount = ((float)$numberofuser / (float)$choices->numberofuser) * 100.0;
            }
            $data['labels'][$count] = $option->text;
            $data['series'][$count] = $numberofuser;
            $data['series_labels'][$count] = $numberofuser . ' (' . format_float($percentageamount, 1) . '%)';
            $count++;
            $numberofuser = 0;
        }

        $chart = new \core\chart_bar();
        if ($displaylayout == self::CHOICE_DISPLAY_VERTICAL) {
            $chart->set_horizontal(true); // Horizontal bars when choices are vertical.
        }
        $series = new \core\chart_series(format_string(get_string("responses", "choice")), $data['series']);
        $series->set_labels($data['series_labels']);
        $chart->add_series($series);
        $chart->set_labels($data['labels']);
        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_stepsize(max(1, round(max($data['series']) / 10)));
        return $OUTPUT->render($chart);
    }
}
