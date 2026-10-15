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

        $mform->addElement(
            'autocomplete',
            'courseid',
            get_string('template_transfer_course', 'local_coursegen'),
            $this->course_options((int) ($this->_customdata['categoryid'] ?? 0)),
            ['placeholder' => get_string('template_transfer_course_search', 'local_coursegen')]
        );
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

    /**
     * The courses that can be chosen: the ones of a category and its subcategories, or all of them.
     *
     * @param int $categoryid The category, or 0 for every course of the site.
     * @return array Course id => name.
     */
    private function course_options(int $categoryid): array {
        global $DB;

        $select = 'id <> :site';
        $params = ['site' => SITEID];
        if ($categoryid > 0) {
            $ids = \core_course_category::get($categoryid)->get_all_children_ids();
            $ids[] = $categoryid;
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $select .= ' AND category ' . $insql;
            $params += $inparams;
        }
        $options = [];
        foreach ($DB->get_records_select('course', $select, $params, 'fullname', 'id, fullname, shortname') as $course) {
            $options[$course->id] = format_string($course->fullname) . ' (' . s($course->shortname) . ')';
        }
        return $options;
    }
}
