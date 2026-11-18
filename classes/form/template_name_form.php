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
    /** The data-form hook of this form, read by the template editor script. */
    const HOOK_FORM = 'local_coursegen/template/name-form';

    /** The data-region hook of the name field. */
    const HOOK_NAME = 'local_coursegen/template/template-name';

    /** The data-region hook of the description field. */
    const HOOK_DESCRIPTION = 'local_coursegen/template/template-description';

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // Change tracking stays ENABLED (Moodle's default) — see the
        // matching note on course_picker_form::definition() for why.

        $mform->updateAttributes(['data-form' => self::HOOK_FORM]);

        $nameattributes = ['size' => 60, 'data-region' => self::HOOK_NAME];
        $namelabel = get_string('template_name', 'local_coursegen');
        $mform->addElement('text', 'templatename', $namelabel, $nameattributes);
        $mform->setType('templatename', PARAM_TEXT);
        $requiredmessage = get_string('required');
        $mform->addRule('templatename', $requiredmessage, 'required', null, 'client');
        $mform->addHelpButton('templatename', 'template_name', 'local_coursegen');

        $descattributes = ['rows' => 3, 'cols' => 60, 'data-region' => self::HOOK_DESCRIPTION];
        $desclabel = get_string('template_description', 'local_coursegen');
        $mform->addElement('textarea', 'templatedesc', $desclabel, $descattributes);
        $mform->setType('templatedesc', PARAM_TEXT);
    }
}
