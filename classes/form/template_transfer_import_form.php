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

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The form that imports a template's configuration over a course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_transfer_import_form extends \moodleform {
    /**
     * Define the fields.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('course', 'courseid', get_string('template_transfer_course', 'local_coursegen'), [
            'multiple' => false,
            'includefrontpage' => false,
        ]);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $mform->addElement('filepicker', 'configfile', get_string('template_transfer_file', 'local_coursegen'), null, [
            'accepted_types' => ['.json'],
            'maxfiles' => 1,
        ]);
        $mform->addRule('configfile', null, 'required', null, 'client');

        $mform->addElement('text', 'templatename', get_string('template_transfer_name', 'local_coursegen'));
        $mform->setType('templatename', PARAM_TEXT);
        $mform->addHelpButton('templatename', 'template_transfer_name', 'local_coursegen');

        $this->add_action_buttons(false, get_string('template_transfer_import_button', 'local_coursegen'));
    }
}
