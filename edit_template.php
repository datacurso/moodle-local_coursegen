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
 * Configure a course template — one screen, no step navigation.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_coursegen\local\template\template_access;
use local_coursegen\local\template\template_service;
use local_coursegen\output\sections_config;

// Template to edit, for example ?id=3. Without it the page creates a new template.
$id = optional_param('id', 0, PARAM_INT);
// Course chosen in the picker, for example ?courseid=42. Without it the course of the template is used.
$courseid = optional_param('courseid', 0, PARAM_INT);

admin_externalpage_setup('local_coursegen_edit_template');
template_access::require_manage();

// The template wizard's stylesheet is NOT the plugin's root styles.css (the
// only sheet Moodle auto-loads through the theme pipeline) — a styles/
// subdirectory sheet reaches a page only via an explicit require like this
// one, which this page never did: every .tpl-* rule silently applied
// nowhere. Same version-busting pattern as aicoursecreation.php, since
// direct plugin stylesheets get no revision from Moodle's cache pipeline.
$cssrev = get_config('local_coursegen', 'version');
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/templates.css', ['v' => $cssrev]));
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/templates-widgets.css', ['v' => $cssrev]));

// Edit mode: the base course comes from the template itself (no courseid
// param needed), the name form prefills below and the sections review
// preselects the saved action and instruction of every activity.
$service = new template_service();
$loaded = $service->load_for_edit($id, $courseid);
$template = $loaded['template'];
$courseid = $loaded['courseid'];
template_access::require_course_visible($courseid);

// Render the "Course sections" review from the structure of the course — no
// course format renderer involved (see classes/output/sections_config.php).
$coursename = '';
$courseshortname = '';
$coursecategoryid = 0;
$sectionsconfightml = '';
if ($loaded['courseusable']) {
    $course = get_course($courseid);
    $coursename = format_string($course->fullname);
    $courseshortname = $course->shortname;
    $coursecategoryid = (int) $course->category;
    $sectionsconfightml = sections_config::render($loaded['sections'], $courseid);
}

$pagetitle = get_string('template_create', 'local_coursegen');
if ($id > 0) {
    $pagetitle = get_string('template_edit', 'local_coursegen');
}

$PAGE->set_url('/local/coursegen/edit_template.php', ['id' => $id]);
$PAGE->set_pagelayout('admin');
$PAGE->navigation->override_active_url(new moodle_url('/local/coursegen/manage_templates.php'));
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);
$PAGE->navbar->add($pagetitle);

// Base-course picker: two standard autocompletes (category, then course
// scoped to it) — see classes/form/course_picker_form.php. Rendered as a
// plain widget generator, the same way template_name_form below is: its
// fields are read directly by JS (see init.js), the form itself is never
// submitted.
$presetcategory = null;
if ($coursecategoryid > 0) {
    $presetcategory = $coursecategoryid;
}
$presetcourse = null;
if ($courseid > 0) {
    $presetcourse = $courseid;
}
$courseform = new \local_coursegen\form\course_picker_form(null, [
    'categoryid' => $presetcategory,
    'courseid' => $presetcourse,
], 'post', '', ['id' => 'tpl-course-form']);
ob_start();
$courseform->display();
$courseformhtml = ob_get_clean();

// Render template name form (native moodleform), prefilled in edit mode.
$nameform = new \local_coursegen\form\template_name_form(null, null, 'post', '', ['id' => 'tpl-name-form']);
if ($template) {
    $nameform->set_data([
        'templatename' => $template->name,
        'templatedesc' => (string) $template->description,
    ]);
}
ob_start();
$nameform->display();
$nameformhtml = ob_get_clean();

$templatecontext = [
    'templateid' => $id,
    'sesskey' => sesskey(),
    'wwwroot' => $CFG->wwwroot,
    'courseformhtml' => $courseformhtml,
    'nameformhtml' => $nameformhtml,
    'initialcourseid' => $courseid,
    'initialcoursename' => $coursename,
    'initialcourseshortname' => $courseshortname,
    'sectionsconfightml' => $sectionsconfightml,
    'haspreview' => !empty($sectionsconfightml),
];

$jsconfig = [
    'templateid' => $id,
    'initialcourseid' => $courseid,
    'initialcoursename' => $coursename,
    'initialcourseshortname' => $courseshortname,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/template_wizard', $templatecontext);

$PAGE->requires->js_call_amd('local_coursegen/local/template/init', 'init', [$jsconfig]);

echo $OUTPUT->footer();
