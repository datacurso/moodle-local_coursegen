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
 * Base-course picker — two standard native autocomplete fields, exactly the
 * same category/course search pattern already used on Moodle's own "Edit
 * course settings" page, instead of a custom-built category-tree/course-list
 * browsing screen.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form wrapping the category/course autocomplete pair used to pick a base course.
 */
class course_picker_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();

        // Category — plain autocomplete over the full category list, exactly
        // like the "Category" field on course/edit_form.php: no AJAX needed,
        // the category list is small enough to send whole.
        $displaylist = \core_course_category::make_categories_list();

        // Append each category's own course count, e.g. "Category 1 (courses: 108)",
        // so the admin can tell at a glance which categories are actually worth
        // searching before opening the course field below.
        $coursecounts = [];
        foreach (\core_course_category::get_all() as $category) {
            $coursecounts[$category->id] = $category->coursecount;
        }
        foreach ($displaylist as $categoryid => $name) {
            $count = $coursecounts[$categoryid] ?? 0;
            $displaylist[$categoryid] = $name . ' (courses: ' . $count . ')';
        }

        $mform->addElement('autocomplete', 'category', get_string('category'), $displaylist, [
            'noselectionstring' => get_string('template_select_category_hint', 'local_coursegen'),
        ]);
        $mform->setType('category', PARAM_INT);
        $mform->setDefault('category', $this->_customdata['categoryid'] ?? '');

        // Course — AJAX autocomplete, scoped server-side to whichever
        // category is currently selected (see
        // amd/src/local/template/form_course_selector.js, which reads the
        // category field's live value and calls
        // local_coursegen_get_courses_by_category). Moodle does not ship a
        // ready-made "courses within one category" autocomplete, unlike the
        // category field above.
        $mform->addElement('autocomplete', 'courseid', get_string('course'), [], [
            'ajax' => 'local_coursegen/local/template/form_course_selector',
            'noselectionstring' => get_string('template_select_course_hint', 'local_coursegen'),
        ]);
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', $this->_customdata['courseid'] ?? '');
    }
}
