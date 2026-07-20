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
 * Edit or create a course template.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);
$step = optional_param('step', 1, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);

admin_externalpage_setup('local_coursegen_manage_templates');

$context = context_system::instance();
require_capability('local/coursegen:managetemplates', $context);

$pagetitle = $id > 0
    ? get_string('template_edit', 'local_coursegen')
    : get_string('template_create', 'local_coursegen');

$PAGE->set_url('/local/coursegen/edit_template.php', ['id' => $id]);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);
$PAGE->navbar->add($pagetitle);

// Resolve selected course name if courseid is in URL.
$coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
    if ($course) {
        $coursename = format_string($course->fullname);
    }
}

// Build category tree for JS.
$cattree = local_coursegen_build_category_tree();

$templatecontext = [
    'templateid' => $id,
    'sesskey' => sesskey(),
    'cattree' => json_encode($cattree),
    'initialstep' => $step,
    'initialcourseid' => $courseid,
    'initialcoursename' => $coursename,
    'initialsearch' => $search,
    'initialcategoryid' => $categoryid,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursegen/template_wizard', $templatecontext);

$PAGE->requires->js_call_amd('local_coursegen/local/template/init', 'init', [$templatecontext]);

echo $OUTPUT->footer();

/**
 * Build a nested category tree with course counts.
 *
 * @return array
 */
function local_coursegen_build_category_tree(): array {
    $categories = core_course_category::get_all();
    $tree = [];
    $map = [];

    foreach ($categories as $cat) {
        $map[$cat->id] = [
            'id' => (int) $cat->id,
            'name' => format_string($cat->name),
            'parent' => (int) $cat->parent,
            'coursecount' => (int) $cat->coursecount,
            'children' => [],
        ];
    }

    foreach ($map as $id => $catdata) {
        if ($catdata['parent'] == 0) {
            $tree[] = &$map[$id];
        } else if (isset($map[$catdata['parent']])) {
            $map[$catdata['parent']]['children'][] = &$map[$id];
        }
    }

    return array_values($tree);
}
