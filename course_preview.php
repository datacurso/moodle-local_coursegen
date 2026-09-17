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
 * Read-only preview of the course a template run is going to produce.
 *
 * A course looks like its format makes it look. Drawing a list of sections and
 * activities instead produces something that is not the course: a course in
 * grid format is a grid, one in weeks is dated, and a teacher deciding whether
 * to accept a plan is deciding about the page they will actually receive.
 *
 * So the page is the real one. The template's own course is rendered through
 * its own format, by the same contract course/view.php uses to hand a course
 * to a format, and what the run is going to add is put into the sections it
 * will be added to. Nothing is created to draw it: the template's course
 * already exists, and the activities that do not exist yet are drawn from the
 * plan.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_export_service;

$sessionid = required_param('sessionid', PARAM_INT);

// Which section to show, for the formats that show one at a time. A format
// that shows them all ignores it, the same way the real course page does.
$section = optional_param('section', null, PARAM_INT);

require_login();
require_capability('local/coursegen:createcoursewithai', context_system::instance());

$session = new course_session($sessionid);
if ((int) $session->get('userid') !== (int) $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'preview this generation');
}

$coursedata = json_decode((string) $session->get('coursedata'), true);
$templateid = (int) ($coursedata['templateid'] ?? 0);
$template = $templateid > 0 ? template::get_record(['id' => $templateid]) : false;
if (!$template) {
    throw new moodle_exception('invalidtemplate', 'local_coursegen');
}

$course = get_course($template->get('courseid'));

// The course is set before anything else happens, because setting it settles
// the theme, and the theme cannot be settled twice. Anything that draws -
// even one activity icon - settles it, so nothing may draw until here.
$PAGE->set_course($course);
$PAGE->set_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid]);
$PAGE->set_pagelayout('course');
// The width a course page is read at. Without it the page runs the whole
// width of the window, which no course page does, and a format that lays its
// sections out in columns gets one column instead of three.
$PAGE->add_body_class('limitedwidth');
$PAGE->add_body_class('local-coursegen-course-preview');
$PAGE->set_secondary_navigation(false);
// What kind of page this is, which is where a theme and a format get the body
// classes they style the page with. A course page that does not say it is one
// is styled as though it were anything else.
$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->set_title(get_string('courseai_preview_course_title', 'local_coursegen'));
$PAGE->set_heading($course->fullname);

// What the run is going to add, and what it has said so far about each one.
// A run under review has no result, so the plan is what there is to show.
$summaries = [];
try {
    $api = new template_ai_api_service();
    foreach (($api->get_plan((string) $session->get('session_id'))['template_plan'] ?? []) as $entry) {
        $summaries[(string) ($entry['uid'] ?? '')] = (string) ($entry['summary'] ?? '');
    }
} catch (moodle_exception $exception) {
    $summaries = [];
}

$planned = [];
foreach (template_instance::get_records(['templateid' => $templateid], 'sortorder') as $instance) {
    $uid = template_export_service::instance_uid($instance);
    $sectionid = (int) $instance->get('sectionid');
    $planned[$sectionid] ??= ['sectionid' => $sectionid, 'activities' => []];
    $planned[$sectionid]['activities'][] = [
        'uid' => $uid,
        'name' => $instance->get('name'),
        'modname' => $instance->get('modname') ?: 'lesson',
        'summary' => $summaries[$uid] ?? '',
        'badge' => get_string('courseai_template_instance_badge', 'local_coursegen'),
        'badgetip' => get_string('courseai_template_instance_badge_tip', 'local_coursegen'),
        'icon' => $OUTPUT->image_icon(
            'monologo',
            $instance->get('modname') ?: 'lesson',
            'mod_' . ($instance->get('modname') ?: 'lesson'),
            ['class' => 'icon activityicon']
        ),
        'url' => (new moodle_url('/local/coursegen/activity_preview.php', [
            'sessionid' => $sessionid,
            'uid' => $uid,
        ]))->out(false),
    ];
}

// What course/view.php hands a format. A format reads these as globals rather
// than as arguments, because it is included rather than called, so every one
// of them has to be here even when it is only read to be compared against:
// an undefined $marker compares equal to zero, and a format that marks the
// current section then believes it was asked to move the mark.
$modinfo = get_fast_modinfo($course);
$modnames = get_module_types_names();
$modnamesplural = get_module_types_names(true);
$modnamesused = $modinfo->get_used_module_names();
$mods = $modinfo->get_cms();
$sections = $modinfo->get_section_info_all();
$marker = -1;
$hide = 0;
$show = 0;
$move = 0;
$edit = -1;
// Null, not zero: a format asked for section zero shows that one section
// alone, and the formats that take this check whether it is null rather than
// whether it is set.
$displaysection = $section;

// A format's own scripts and styles are registered here, not by the format
// itself, so a format that arranges its sections gets nothing to arrange them
// with when this is skipped.
include_course_ajax($course, $modnamesused);

echo $OUTPUT->header();
echo $OUTPUT->notification(
    get_string('courseai_preview_course_notice', 'local_coursegen'),
    \core\output\notification::NOTIFY_INFO
);

// What the preview adds to the page the format draws: the activities the run
// is going to write, and the fact that following a link must stay inside the
// preview rather than land on the template's real course.
//
// Both are done to the page rather than to the markup of the page. A rendered
// course is HTML, and editing HTML as text to add two rows to it means
// deciding by hand where an element ends; the page itself already knows.
$PAGE->requires->js_call_amd(
    'local_coursegen/local/courseai/template/course_preview',
    'init',
    [$sessionid, (int) $course->id, array_values($planned)]
);

// The wrapper a course page puts around its format's output. A format lays
// its sections out inside it, so without it they sit against a different edge
// than the sections the format drew above them.
echo html_writer::start_tag('div', ['class' => 'course-content']);
require($CFG->dirroot . '/course/format/' . $course->format . '/format.php');
echo html_writer::end_tag('div');

// What a course page runs once its sections are on screen.
$PAGE->requires->js_call_amd('core_course/view', 'init');

echo $OUTPUT->footer();
