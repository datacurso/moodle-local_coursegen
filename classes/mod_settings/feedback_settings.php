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

namespace local_coursegen\mod_settings;

use local_coursegen\mod_settings\feedback\presentation_builder;

/**
 * Class feedback_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_settings extends base_settings {
    /**
     * Add specific settings for feedback module.
     */
    public function add_settings() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/feedback/lib.php');

        foreach ($this->modsettings['questions'] as $question) {
            $this->add_question($question);
        }
    }

    /**
     * Add question to feedback.
     *
     * @param array $question Question data.
     * @return \stdClass Question info.
     */
    protected function add_question($question) {
        global $DB;
        $type = $question['typ'];
        $itemobj = feedback_get_item_class($type);

        $position = $DB->count_records('feedback_item', ['feedback' => $this->cm->instance]) + 1;
        $question['position'] = $position;

        $presentationbuilder = new presentation_builder();
        $presentation = $presentationbuilder->build((object)$question);
        if (!empty($presentation)) {
            $question['presentation'] = $presentation;
        }

        // The item is new: the item classes read its id unguarded to tell an insert from an update.
        $question['id'] = 0;
        $question['cmid'] = $this->cm->coursemodule;
        $question['feedback'] = $this->cm->instance;

        if (property_exists($itemobj, 'context')) {
            // An item type that keeps the context of its editor texts only gets it when its form is built, as
            // the page that edits an item does before saving it.
            $cm = get_coursemodule_from_id('feedback', $this->cm->coursemodule, 0, false, MUST_EXIST);
            $feedback = $DB->get_record('feedback', ['id' => $this->cm->instance], '*', MUST_EXIST);
            $itemobj->build_editform((object) ['id' => '', 'position' => $position, 'typ' => $type, 'options' => '',
                'required' => 0], $feedback, $cm);
        }

        $itemobj->set_data((object) $question);
        return $itemobj->save_item();
    }
}
