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
 * mod_choice's named, vertical-layout results table, ported the same way
 * view.php is. Kept apart from choice_results_view.php only because
 * together they crossed the 250-line cap - both are the same "show the
 * results" concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait choice_results_table {
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

}
