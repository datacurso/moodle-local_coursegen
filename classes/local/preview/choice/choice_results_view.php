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
 * mod_choice's results display, built the same way view.php builds it: whether
 * results can be shown at all, and drawing them (as a table or as a chart,
 * named or anonymous, by response count or by percentage). Kept apart from
 * view.php, which is the options a reader picks from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait choice_results_view {
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
