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
 * The course is drawn from two things and nothing else: the payload that was
 * sent to the service, which describes the template's sections and every
 * activity in them, and the answer that came back, which says what the run
 * intends to write. Both name every element by a uid, and that is what this
 * page asks for things by.
 *
 * Nothing on the site is read and nothing is created. An earlier version drew
 * the template's real course by handing it to its own format, which produced
 * a page that looked right and was the wrong page: it showed the activities
 * that exist rather than the ones being decided about, and opening one led
 * into them. A preview exists so the teacher can decide before anything does.
 *
 * The sections and rows are drawn by core's own course format templates, fed
 * with what the payload says, so they are the same rows a course page has.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\preview\course_from_payload;
use local_coursegen\local\preview\grid_from_payload;
use local_coursegen\local\service\template_ai_api_service;
use local_coursegen\local\service\template_export_service;

$sessionid = required_param('sessionid', PARAM_INT);

// Which section to show, for a format that shows them one at a time.
$section = optional_param('section', null, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/coursegen:createcoursewithai', $context);

$session = new course_session($sessionid);
if ((int) $session->get('userid') !== (int) $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'preview this generation');
}

$coursedata = json_decode((string) $session->get('coursedata'), true);
$templateid = (int) ($coursedata['templateid'] ?? 0);
if ($templateid <= 0) {
    throw new moodle_exception('invalidtemplate', 'local_coursegen');
}

// Exactly what was sent to the service, read again rather than remembered, so
// the preview and the run can never be describing different things.
$payload = template_export_service::build_init_payload($templateid);

// What the answer says each activity will contain. A run still under review
// has no result, so the plan is what there is to show of its intent.
$summaries = [];
try {
    $api = new template_ai_api_service();
    foreach (($api->get_plan((string) $session->get('session_id'))['template_plan'] ?? []) as $entry) {
        $summaries[(string) ($entry['uid'] ?? '')] = (string) ($entry['summary'] ?? '');
    }
} catch (moodle_exception $exception) {
    $summaries = [];
}

$configuration = $payload['course_configuration'] ?? [];
$coursename = (string) ($configuration['fullname'] ?? '');
$format = (string) ($configuration['format'] ?? 'topics');

// The course is read in its own language, the way it would be read once it
// exists: it is what the payload was built in, and what its content is in.
if (!empty($configuration['lang'])) {
    force_current_language((string) $configuration['lang']);
}

// The page belongs to the course the template is built on, and says so, the
// way a real course page does. That is what keeps the site's own chrome
// behaving like a course page's: without a course, the primary navigation
// falls back to marking the site home as where the reader is, which on a page
// about a course is a lie. Nothing of the course is drawn from this: the
// course index, which would list the real course, is turned off, and the
// trail is written here from the payload rather than taken from the course.
$PAGE->set_course(get_course(template::get_record(['id' => $templateid])->get('courseid')));
$PAGE->set_show_course_index(false);
$PAGE->navbar->ignore_active(true);
$PAGE->set_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid]);
$PAGE->set_pagelayout('course');
// The width a course page is read at. Without it the page runs the whole width
// of the window, which no course page does.
$PAGE->add_body_class('limitedwidth');
// The class a page carries for the format laying it out, which is what the
// format styles itself by: without it a grid's own dialog is sized by nothing
// and comes out the size of any other dialog.
$PAGE->add_body_class('format-' . $format);
$PAGE->add_body_class('local-coursegen-course-preview');
// What kind of page this is, which a theme and a format both read.
$PAGE->set_pagetype('course-view-' . $format);
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('courseai_preview_course_title', 'local_coursegen'));
$PAGE->set_heading($coursename);

// A course page marks no entry of the primary navigation as where the reader
// is, so neither does this one. The navigation marks the site home on any page
// it cannot place, and a page it is told about picks an entry instead; a
// course page ends up with none, so none is what is shown here, by unmarking
// whatever the navigation chose once it has chosen. Asking for the navigation
// settles the theme, so it comes after everything the theme is told about the
// page: its layout, its kind, its width and its blocks.
foreach ($PAGE->primarynav->children as $entry) {
    $entry->make_inactive();
}

echo $OUTPUT->header();
echo $OUTPUT->notification(
    get_string('courseai_preview_course_notice', 'local_coursegen'),
    \core\output\notification::NOTIFY_INFO
);

// The course is drawn by its own format's template when it has one, so the
// preview is laid out the way the template's course is: the same tiles, the
// same pictures, the same settings, all of them read from what was sent.
$content = course_from_payload::content($payload, $summaries, $sessionid, $section);
$template = 'core_courseformat/local/content';
if ($section === null && grid_from_payload::applies($payload)) {
    $content = grid_from_payload::content($content, $payload, $sessionid);
    $template = 'format_grid/local/content';
}

echo html_writer::start_tag('div', ['class' => 'course-content']);
echo $OUTPUT->render_from_template($template, $content);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
