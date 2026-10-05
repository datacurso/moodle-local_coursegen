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
 * Course template picker — a single native autocomplete field for the
 * "create course from template" page.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form wrapping a single autocomplete element to pick a course template.
 */
class course_template_picker_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();

        // A leading blank choice is required: without it, no <option> carries
        // "selected" and the browser defaults the native <select> to its first
        // real entry — silently pre-picking a template with no user action and
        // no 'change' event, so nothing downstream (structure load, search-row
        // collapse) ever fires until the professor happens to touch the field.
        $choices = ['' => ''];
        foreach (($this->_customdata['templates'] ?? []) as $template) {
            $choices[$template['id']] = $template['name'];
        }

        $mform->addElement('autocomplete', 'templateid',
            get_string('courseai_template_picker', 'local_coursegen'),
            $choices,
            [
                'casesensitive' => false,
                'showsuggestions' => true,
                'noselectionstring' => get_string('courseai_template_picker_placeholder', 'local_coursegen'),
            ]
        );
        $mform->setType('templateid', PARAM_INT);
        $mform->setDefault('templateid', '');
        $mform->addHelpButton('templateid', 'courseai_template_picker', 'local_coursegen');
    }
}
