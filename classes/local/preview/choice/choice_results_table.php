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
use single_select;
use stdClass;

/**
 * mod_choice's named, vertical-layout results table, built the same way
 * view.php builds it. Kept apart from choice_results_view.php only because
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

        ksort($choices->options);
        return $OUTPUT->render_from_template('local_coursegen/preview_choice_results_table', [
            'showform' => $choices->viewresponsecapability,
            'action' => (new moodle_url($this->here))->out(false),
            'coursemoduleid' => $choices->coursemoduleid,
            'sesskey' => sesskey(),
            'summary' => get_string('responsesto', 'choice', format_string($choices->name)),
            'columns' => $this->choice_results_columns($choices),
            'actiondata' => $this->choice_results_actions($choices),
        ]);
    }

    /**
     * One column per option: its header title, the select/deselect toggle
     * for it, and the number of users who chose it, which is always zero.
     *
     * @param stdClass $choices
     * @return array
     */
    protected function choice_results_columns(stdClass $choices): array {
        global $OUTPUT;
        $columns = [];
        foreach ($choices->options as $optionid => $options) {
            $headertitle = $this->choice_option_headertitle($choices, $optionid);

            $mastercheckboxhtml = '';
            if ($choices->viewresponsecapability && $choices->deleterepsonsecapability) {
                $mastercheckbox = $this->choice_option_toggle($optionid, $headertitle);
                $mastercheckboxhtml = $OUTPUT->render($mastercheckbox);
            }

            $usernumbertext = (string) 0;
            if ($choices->limitanswers && $choices->showavailable) {
                $usernumbertext .= get_string('limita', 'choice', $options->maxanswer);
            }

            $columns[] = [
                'headertitle' => $headertitle,
                'mastercheckboxhtml' => $mastercheckboxhtml,
                'usernumbertext' => $usernumbertext,
            ];
        }
        return $columns;
    }

    /**
     * One option's header title: its text, and "(Full)" when a limited
     * option has as many responses as it allows.
     *
     * @param stdClass $choices
     * @param mixed $optionid
     * @return string
     */
    protected function choice_option_headertitle(stdClass $choices, $optionid): string {
        if ($choices->showunanswered && $optionid == 0) {
            return get_string('notanswered', 'choice');
        }
        if ($optionid <= 0) {
            return '';
        }
        $option = $choices->options[$optionid];
        $headertitle = format_string($option->text);
        if (empty($option->user) || count($option->user) === 0) {
            return $headertitle;
        }
        if ($choices->limitanswers && count($option->user) == $option->maxanswer) {
            $headertitle .= ' ' . get_string('full', 'choice');
        }
        return $headertitle;
    }

    /**
     * The select/deselect-all checkbox for one option's column.
     *
     * @param mixed $optionid
     * @param string $headertitle
     * @return \core\output\checkbox_toggleall
     */
    protected function choice_option_toggle($optionid, string $headertitle) {
        $selectallid = 'select-response-option-' . $optionid;
        $togglegroup = 'responses response-option-' . $optionid;
        return new \core\output\checkbox_toggleall($togglegroup, true, [
            'id' => $selectallid,
            'name' => $selectallid,
            'value' => 1,
            'selectall' => get_string('selectalloption', 'choice', $headertitle),
            'deselectall' => get_string('deselectalloption', 'choice', $headertitle),
            'label' => get_string('selectalloption', 'choice', $headertitle),
            'labelclasses' => 'accesshide',
        ]);
    }

    /**
     * The select-all-responses checkbox and the bulk action dropdown, when
     * the reader may act on responses; nobody has answered to act on.
     *
     * @param stdClass $choices
     * @return string
     */
    protected function choice_results_actions(stdClass $choices): string {
        global $OUTPUT;
        if (!$choices->viewresponsecapability || !$choices->deleterepsonsecapability) {
            return '';
        }

        $selectallcheckbox = new \core\output\checkbox_toggleall('responses', true, [
            'id' => 'select-all-responses',
            'name' => 'select-all-responses',
            'value' => 1,
            'label' => get_string('selectall'),
            'classes' => 'btn-secondary me-1',
        ], true);

        $actionurl = new moodle_url($this->here, ['sesskey' => sesskey(), 'action' => 'delete_confirmation()']);
        $actionoptions = ['delete' => get_string('delete')];
        foreach ($choices->options as $optionid => $option) {
            if ($optionid > 0) {
                $actionoptions['choose_' . $optionid] = get_string('chooseoption', 'choice', $option->text);
            }
        }
        $select = new single_select($actionurl, 'action', $actionoptions, null, ['' => get_string('chooseaction', 'choice')],
            'attemptsform');
        $select->set_label(get_string('withselected', 'choice'));
        $select->disabled = true;
        $select->attributes = [
            'data-action' => 'toggle',
            'data-togglegroup' => 'responses',
            'data-toggle' => 'action',
        ];

        return $OUTPUT->render($selectallcheckbox) . $OUTPUT->render($select);
    }
}
