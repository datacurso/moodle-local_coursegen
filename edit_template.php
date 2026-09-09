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
$search = optional_param('search', '', PARAM_TEXT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);

admin_externalpage_setup('local_coursegen_manage_templates');

// Render course preview + configuration controls AFTER admin setup, using a
// separate page object to avoid "theme already set" on the real $PAGE.
$coursename = '';
$courseshortname = '';
$sectionsconfightml = '';
if ($courseid > 0) {
    $course = get_course($courseid);
    $coursename = format_string($course->fullname);
    $courseshortname = $course->shortname;

    $renderpage = new moodle_page();
    $renderpage->set_context(context_course::instance($course->id));
    $renderpage->set_course($course);
    $renderpage->set_url(new moodle_url('/course/view.php', ['id' => $course->id]));
    $renderpage->set_pagelayout('course');

    $format = course_get_format($course);
    $renderer = $format->get_renderer($renderpage);
    $outputclass = $format->get_output_classname('content');
    $widget = new $outputclass($format);
    $previewhtml = $renderer->render($widget);

    $modinfo = get_fast_modinfo($course);
    $sectionsconfightml = \local_coursegen\output\sections_config::render($previewhtml, $modinfo);
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

// Build flat category list with depth for mustache rendering.
$flatcats = [];
$allcats = core_course_category::get_all();
$buildflat = function($parentid, $depth) use (&$buildflat, &$flatcats, $allcats) {
    foreach ($allcats as $cat) {
        if ((int)$cat->parent !== $parentid) {
            continue;
        }
        $haschildren = false;
        foreach ($allcats as $child) {
            if ((int)$child->parent === (int)$cat->id) {
                $haschildren = true;
                break;
            }
        }
        $flatcats[] = [
            'id' => (int) $cat->id,
            'name' => format_string($cat->name),
            'coursecount' => (int) $cat->coursecount,
            'depth' => $depth,
            'hiddenclass' => ($depth > 0) ? 'd-none' : '',
            'haschildren' => $haschildren,
        ];
        $buildflat((int)$cat->id, $depth + 1);
    }
};
$buildflat(0, 0);

// Get installed activity module types for the "allowed types" control.
$modtypes = [];
$mods = get_module_types_names();
foreach ($mods as $modname => $displayname) {
    $modtypes[] = ['id' => $modname, 'label' => $displayname];
}

// Render template name form (native moodleform).
$nameform = new \local_coursegen\form\template_name_form(null, null, 'post', '', ['id' => 'tpl-name-form']);
ob_start();
$nameform->display();
$nameformhtml = ob_get_clean();

$templatecontext = [
    'templateid' => $id,
    'sesskey' => sesskey(),
    'wwwroot' => $CFG->wwwroot,
    'flatcats' => $flatcats,
    'modtypes' => $modtypes,
    'nameformhtml' => $nameformhtml,
    'initialcourseid' => $courseid,
    'initialcoursename' => $coursename,
    'initialcourseshortname' => $courseshortname,
    'initialsearch' => $search,
    'initialcategoryid' => $categoryid,
    'sectionsconfightml' => $sectionsconfightml,
    'haspreview' => !empty($sectionsconfightml),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/template_wizard', $templatecontext);

$PAGE->requires->js_call_amd('local_coursegen/local/template/init', 'init', [$templatecontext]);

echo $OUTPUT->footer();
