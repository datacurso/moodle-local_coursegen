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

use moodle_url;
use stdClass;

/**
 * mod_choice's option list, built the same way view.php builds it: preparing each
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
        global $OUTPUT;
        $layoutclass = 'horizontal';
        if ($vertical) {
            $layoutclass = 'vertical';
        }
        $target = new moodle_url($this->here);
        $disabled = !empty($options['previewonly']);

        $rows = [];
        $choicecount = 0;
        foreach ($options['options'] as $option) {
            $choicecount++;
            $rows[] = $this->choice_option_row($option, $options, $multiple, $choicecount, $disabled);
        }

        // The real form ends with the button that saves a choice, or with the
        // reason it cannot be saved. Nobody may act on an activity that does not exist, so the
        // options are shown and nothing follows them.

        $action = $target->out(false);
        $sesskeyvalue = sesskey();
        return $OUTPUT->render_from_template('local_coursegen/preview_choice_options', [
            'action' => $action,
            'layoutclass' => $layoutclass,
            'sesskey' => $sesskeyvalue,
            'coursemoduleid' => $coursemoduleid,
            'options' => $rows,
        ]);
    }

    /**
     * One option's own row, ready for preview_choice_options.mustache.
     *
     * @param stdClass $option
     * @param array $options
     * @param bool $multiple
     * @param int $choicecount
     * @param bool $disabled
     * @return array
     */
    protected function choice_option_row(stdClass $option, array $options, bool $multiple, int $choicecount, bool $disabled): array {
        $type = 'radio';
        $name = 'answer';
        if ($multiple) {
            $type = 'checkbox';
            $name = 'answer[]';
        }

        $isfull = !empty($option->attributes->disabled);
        $showavailable = !empty($options['limitanswers']) && !empty($options['showavailable']);
        $fulltext = get_string('full', 'choice');
        $responsestext = get_string('responsesa', 'choice', $option->countanswers);
        $limittext = get_string('limita', 'choice', $option->maxanswers);

        return [
            'type' => $type,
            'name' => $name,
            'id' => 'choice_' . $choicecount,
            'value' => $option->attributes->value,
            'disabled' => $disabled || $isfull,
            'text' => $option->text,
            'isfull' => $isfull,
            'fulltext' => $fulltext,
            'showavailable' => $showavailable,
            'responsestext' => $responsestext,
            'limittext' => $limittext,
        ];
    }
}
