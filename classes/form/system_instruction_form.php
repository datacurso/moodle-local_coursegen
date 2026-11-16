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

/**
 * System instruction form for DataCurso plugin.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * System instruction form class.
 */
class system_instruction_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;

        // System instruction name field.
        $mform->addElement('text', 'name', get_string('systeminstructionname', 'local_coursegen'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('name', 'systeminstructionname', 'local_coursegen');

        // System instruction content field (rich text editor).
        $mform->addElement(
            'editor',
            'content_editor',
            get_string('systeminstructioncontent', 'local_coursegen'),
            ['rows' => 15],
            $this->get_editor_options()
        );
        $mform->setType('content_editor', PARAM_RAW);
        $mform->addHelpButton('content_editor', 'systeminstructioncontent', 'local_coursegen');

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Get editor options.
     *
     * @return array
     */
    private function get_editor_options() {
        return [
            'maxfiles' => 0,
            'trusttext' => true,
            'subdirs' => false,
        ];
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        if (empty(trim($data['name']))) {
            $errors['name'] = get_string('required');
        } else {
            // Check if name is unique.
            $name = trim($data['name']);

            // If editing, exclude current record from uniqueness check.
            if (!empty($data['id'])) {
                $sql = "SELECT id FROM {local_coursegen_system_instruction} WHERE name = ? AND deleted = 0 AND id != ?";
                $params = [$name, $data['id']];
            } else {
                $sql = "SELECT id FROM {local_coursegen_system_instruction} WHERE name = ? AND deleted = 0";
                $params = [$name];
            }

            if ($DB->record_exists_sql($sql, $params)) {
                $errors['name'] = get_string('systeminstructionnameexists', 'local_coursegen');
            }
        }

        return $errors;
    }
}
