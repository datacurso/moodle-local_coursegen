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

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

admin_externalpage_setup('local_coursegen_manage_templates');

// The template wizard's stylesheet is NOT the plugin's root styles.css (the
// only sheet Moodle auto-loads through the theme pipeline) — a styles/
// subdirectory sheet reaches a page only via an explicit require like this
// one, which this page never did: every .tpl-* rule silently applied
// nowhere. Same version-busting pattern as aicoursecreation.php, since
// direct plugin stylesheets get no revision from Moodle's cache pipeline.
$cssrev = get_config('local_coursegen', 'version');
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/templates.css', ['v' => $cssrev]));

// Edit mode: the whole saved configuration hydrates the page — the base
// course comes from the template itself (no courseid param needed), the
// name/config forms prefill below, the sections review preselects the saved
// actions/behaviors, and the saved per-activity reference/prompt values are
// handed to JS so a re-save round-trips them (they have no visible controls).
$template = null;
$savedsections = new stdClass();
$savedactivities = new stdClass();
if ($id > 0) {
    $template = new \local_coursegen\local\models\template($id);
    if ($courseid <= 0) {
        $courseid = (int) $template->get('courseid');
    }
    foreach (\local_coursegen\local\models\template_section::get_records(['templateid' => $id]) as $record) {
        $savedsections->{(int) $record->get('sectionid')} = $record->get('behavior');
    }
    foreach (\local_coursegen\local\models\template_activity::get_records(['templateid' => $id]) as $record) {
        $savedactivities->{(int) $record->get('cmid')} = [
            'action' => $record->get('action'),
            'useasreference' => (bool) $record->get('useasreference'),
            'prompt' => (string) $record->get('prompt'),
        ];
    }
}

// Render the "Course sections" review straight from modinfo — no course
// format renderer involved (see classes/output/sections_config.php).
$coursename = '';
$courseshortname = '';
$coursecategoryid = 0;
$sectionsconfightml = '';
if ($courseid > 0) {
    $course = get_course($courseid);
    $coursename = format_string($course->fullname);
    $courseshortname = $course->shortname;
    $coursecategoryid = (int) $course->category;

    $modinfo = get_fast_modinfo($course);
    $sectionsconfightml = \local_coursegen\output\sections_config::render($modinfo, $id);
}

$context = context_system::instance();
require_capability('local/coursegen:managetemplates', $context);

$pagetitle = $id > 0
    ? get_string('template_edit', 'local_coursegen')
    : get_string('template_create', 'local_coursegen');

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
$courseform = new \local_coursegen\form\course_picker_form(null, [
    'categoryid' => $coursecategoryid ?: null,
    'courseid' => $courseid ?: null,
], 'post', '', ['id' => 'tpl-course-form']);
ob_start();
$courseform->display();
$courseformhtml = ob_get_clean();

// Kind-defaults/limits/allowed-types: a real \core_form\dynamic_form (see
// classes/form/template_config_form.php). "courseid"/"templateid" are passed
// as ajax form data — the same args DynamicForm.load() sends when the form
// reloads via AJAX on a course change — so this initial instantiation only
// avoids a visible round-trip when the page already loads with a course
// (courseid param, or edit mode deriving it from the template).
$configform = new \local_coursegen\form\template_config_form(
    null,
    null,
    'post',
    '',
    ['id' => 'tpl-config-form'],
    true,
    ['courseid' => $courseid, 'templateid' => $id]
);
ob_start();
$configform->display();
$configformhtml = ob_get_clean();

// Render template name form (native moodleform), prefilled in edit mode.
$nameform = new \local_coursegen\form\template_name_form(null, null, 'post', '', ['id' => 'tpl-name-form']);
if ($template) {
    $nameform->set_data([
        'templatename' => $template->get('name'),
        'templatedesc' => (string) $template->get('description'),
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
    'configformhtml' => $configformhtml,
    'nameformhtml' => $nameformhtml,
    'initialcourseid' => $courseid,
    'initialcoursename' => $coursename,
    'initialcourseshortname' => $courseshortname,
    'sectionsconfightml' => $sectionsconfightml,
    'haspreview' => !empty($sectionsconfightml),
    // Saved per-section/per-activity configuration for JS state seeding in
    // edit mode (empty objects otherwise) — the rendered controls already
    // preselect action/behavior server-side, but useasreference and prompt
    // have no controls, so they must round-trip through the JS state.
    'savedsections' => $savedsections,
    'savedactivities' => $savedactivities,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/template_wizard', $templatecontext);

$PAGE->requires->js_call_amd('local_coursegen/local/template/init', 'init', [$templatecontext]);

echo $OUTPUT->footer();
