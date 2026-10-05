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
 * Create or edit a template: the course, and what the AI does with each activity.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_coursegen\local\template\template_access;
use local_coursegen\output\edit_template_page;

admin_externalpage_setup('local_coursegen_edit_template');
template_access::require_manage();

// Template to edit, for example ?id=3. Without it the page creates a new template.
$templateid = optional_param('id', 0, PARAM_INT);
// Course chosen in the picker, for example ?courseid=42. Without it the course of the template is used.
$courseid = optional_param('courseid', 0, PARAM_INT);

template_access::require_course_visible($courseid);

$PAGE->set_url('/local/coursegen/edit_template.php', ['id' => $templateid, 'courseid' => $courseid]);
$PAGE->navigation->override_active_url(new moodle_url('/local/coursegen/manage_templates.php'));
$heading = get_string('template_create', 'local_coursegen');
if ($templateid > 0) {
    $heading = get_string('template_edit', 'local_coursegen');
}
$PAGE->set_title($heading);
$PAGE->set_heading($heading);
$PAGE->navbar->add($heading);
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/template_editor.css'));

$page = new edit_template_page($templateid, $courseid);
$data = $page->export_for_template($OUTPUT);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/edit_template', $data);
echo $OUTPUT->footer();
