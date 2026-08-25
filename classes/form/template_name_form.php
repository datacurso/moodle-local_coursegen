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
 * Template name and description form.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for naming a course template.
 */
class template_name_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();

        $mform->addElement('text', 'templatename',
            get_string('template_name', 'local_coursegen'), ['size' => 60]);
        $mform->setType('templatename', PARAM_TEXT);
        $mform->addRule('templatename', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('templatename', 'template_name', 'local_coursegen');

        $mform->addElement('textarea', 'templatedesc',
            get_string('template_description', 'local_coursegen'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('templatedesc', PARAM_TEXT);
    }
}
