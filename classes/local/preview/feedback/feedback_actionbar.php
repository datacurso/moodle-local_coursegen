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

namespace local_coursegen\local\preview\feedback;

use action_link;
use moodle_url;

/**
 * standard_action_bar::get_items(), rendered through
 * mod_feedback/main_action_menu. Kept apart from view.php only because
 * together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait feedback_actionbar {
    /**
     * standard_action_bar::get_items(), rendered through mod_feedback/main_action_menu.
     *
     * @param bool $viewcompletion
     * @return string
     */
    protected function main_action_bar(bool $viewcompletion): string {
        global $OUTPUT;
        $items = [];
        if (has_capability('mod/feedback:edititems', $this->context)) {
            $items['left'][]['actionlink'] = new action_link(new moodle_url($this->here, ['tab' => 'edit']),
                get_string('edit_items', 'feedback'), null, ['class' => 'btn btn-secondary']);
        }
        // The preview icon should be displayed only to users with capability to edit or view reports (to include
        // non-editing teachers too).
        $capabilities = [
            'mod/feedback:edititems',
            'mod/feedback:viewreports',
        ];
        if (has_any_capability($capabilities, $this->context)) {
            $items['left'][]['actionlink'] = new action_link(new moodle_url($this->here, ['tab' => 'print']),
                get_string('previewquestions', 'feedback'), null, ['class' => 'btn btn-secondary']);
        }
        if ($viewcompletion) {
            // Display a link to complete feedback: nobody has started one to resume.
            $label = get_string('complete_the_form', 'feedback');
            $items['left'][]['actionlink'] = new action_link(new moodle_url($this->here, ['tab' => 'complete']),
                $label, null, ['class' => 'btn btn-primary']);
        }
        // base_action_bar::export_for_template().
        foreach ($items['left'] ?? [] as $i => $item) {
            $items['left'][$i]['actionlink'] = $item['actionlink']->export_for_template($OUTPUT);
        }
        return $OUTPUT->render_from_template('mod_feedback/main_action_menu', $items);
    }
}
