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
 * Course filter form for the template wizard.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for filtering courses in the template wizard.
 */
class course_filter_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // Remove default form actions — we handle submit via JS.
        $mform->disable_form_change_checker();

        $mform->addElement('text', 'fullname', get_string('fullnamecourse'), ['size' => 40]);
        $mform->setType('fullname', PARAM_TEXT);

        $mform->addElement('text', 'shortname', get_string('shortnamecourse'), ['size' => 40]);
        $mform->setType('shortname', PARAM_TEXT);

        $categories = \core_course_category::make_categories_list();
        $catoptions = ['' => get_string('all')] + $categories;
        $mform->addElement('select', 'category', get_string('category'), $catoptions);

        $this->add_action_buttons(false, get_string('search'));
    }
}
