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

/**
 * mod_choice's option list, ported the same way view.php is: preparing each
 * option's response count/ratio (nobody has answered a choice being
 * previewed, so those stay at zero) and drawing the form of radio buttons or
 * checkboxes a reader would pick from. Kept apart from view.php only because
 * together they crossed the 250-line cap - both are the same "let a reader
 * choose" concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait choice_options_view {

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

        // The real form ends with the button that saves a choice, or with the
        // reason it cannot be saved. Nobody may act on an activity that does not exist, so the
        // options are shown and nothing follows them.

        $html .= html_writer::end_tag('form');

        return $html;
    }
}
