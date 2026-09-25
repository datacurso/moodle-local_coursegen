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
 * Read-only preview of one activity that does not exist yet.
 *
 * Nothing is created to draw this. The point of reviewing a plan is to decide
 * whether it is worth building, so a preview that builds it has answered the
 * question by asking it: until the teacher approves, the course has no
 * activities, no sections and no scratch copies of either.
 *
 * What there is, is the answer the AI returned, which describes the activity
 * completely. Each type is drawn from that description by a preview written
 * against that module's own view, so what the teacher reads is what they will
 * receive.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;
use local_coursegen\local\preview\activity_preview_lookup;
use local_coursegen\local\preview\preview_factory;
use local_coursegen\local\service\template_export_service;

/**
 * The uid's own section number, from the payload's activity list.
 *
 * @param array $payload
 * @param string $uid
 * @return int|null
 */
function local_coursegen_activity_preview_section_number(array $payload, string $uid): ?int {
    $activities = $payload['activities'] ?? [];
    foreach ($activities as $activity) {
        $activityuid = $activity['uid'] ?? '';
        $activityuid = (string) $activityuid;
        if ($activityuid === $uid) {
            $parameters = $activity['parameters'] ?? [];
            $section = $parameters['section'] ?? 0;
            return (int) $section;
        }
    }
    return null;
}

/**
 * The section's own name, from the payload's section list.
 *
 * @param array $payload
 * @param int $sectionnumber
 * @return string
 */
function local_coursegen_activity_preview_section_name(array $payload, int $sectionnumber): string {
    $sections = $payload['sections_info'] ?? [];
    foreach ($sections as $info) {
        $infosection = $info['section'] ?? -1;
        $infosection = (int) $infosection;
        if ($infosection === $sectionnumber) {
            $name = $info['name'] ?? '';
            return (string) $name;
        }
    }
    return '';
}

/**
 * Where this activity sits, which is how a reader gets back out of it. A real
 * activity page builds this from the course it belongs to; this one has no
 * course to ask, so it is read from the payload, which says the same thing.
 *
 * @param array $payload
 * @param string $uid
 * @param int $sessionid
 */
function local_coursegen_activity_preview_add_breadcrumb(array $payload, string $uid, int $sessionid): void {
    global $PAGE;

    $courseconfiguration = $payload['course_configuration'] ?? [];
    $coursename = $courseconfiguration['fullname'] ?? '';
    $coursename = (string) $coursename;
    if ($coursename !== '') {
        $courseurl = new moodle_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid]);
        $PAGE->navbar->add($coursename, $courseurl);
    }

    $sectionnumber = local_coursegen_activity_preview_section_number($payload, $uid);
    if ($sectionnumber === null) {
        return;
    }
    $sectionname = local_coursegen_activity_preview_section_name($payload, $sectionnumber);
    if ($sectionname === '') {
        return;
    }
    $sectionurl = new moodle_url('/local/coursegen/course_preview.php', [
        'sessionid' => $sessionid,
        'section' => $sectionnumber,
    ]);
    $PAGE->navbar->add($sectionname, $sectionurl);
}

/**
 * A module's own side blocks are part of how it looks: a lesson with its menu
 * turned on is read with that menu beside it.
 *
 * @param \local_coursegen\local\preview\activity_preview $preview
 */
function local_coursegen_activity_preview_add_side_blocks($preview): void {
    global $PAGE;
    $sideblocks = $preview->side_blocks();
    foreach ($sideblocks as $block) {
        $PAGE->blocks->add_fake_block($block, BLOCK_POS_LEFT);
    }
}

/**
 * A course page marks no entry of the primary navigation as where the reader
 * is, so neither does this one. The navigation marks the site home on any page
 * it cannot place, and a page it is told about picks an entry instead; a
 * course page ends up with none, so none is what is shown here, by unmarking
 * whatever the navigation chose once it has chosen.
 */
function local_coursegen_activity_preview_deactivate_primary_nav(): void {
    global $PAGE;
    foreach ($PAGE->primarynav->children as $entry) {
        $entry->make_inactive();
    }
}

$sessionid = required_param('sessionid', PARAM_INT);

// Every element of a run carries a uid, in what was sent and in what came
// back, and that is the only thing this page is asked for. Deriving an id here
// instead is how the 900000-based number came about, and it bought nothing.
$uid = required_param('uid', PARAM_ALPHANUMEXT);

// Which page of it, for an activity that is read a page at a time.
$page = optional_param('page', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/coursegen:createcoursewithai', $context);

$session = new course_session($sessionid);
if ((int) $session->get('userid') !== (int) $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'preview this generation');
}

// Exactly what was sent to the service, read again rather than remembered. It
// describes every activity of the template, kept or written, and the mould a
// written one is built into, and names each by the uid the answer echoes.
$coursedata = $session->get('coursedata');
$coursedata = (string) $coursedata;
$coursedata = json_decode($coursedata, true);
$templateid = $coursedata['templateid'] ?? 0;
$templateid = (int) $templateid;

$payload = [];
if ($templateid > 0) {
    $payload = template_export_service::build_init_payload($templateid);
}

$found = activity_preview_lookup::resolve($uid, $payload, $session);
$modname = $found['modname'];
$parameters = $found['parameters'];
$source = $found['source'];

// The activity is read in its course's language, which is the language its
// content is in and the one it will be read in once it exists.
if (!empty($payload['lang'])) {
    force_current_language((string) $payload['lang']);
}

$preview = preview_factory::for_activity($modname, $parameters, $source);
// A preview told which page to open is opened at that page; one that was not
// opens where the module would open, which is not always its first page.
$hereparams = ['sessionid' => $sessionid, 'uid' => $uid];
if (optional_param('page', null, PARAM_INT) !== null) {
    $hereparams['page'] = $page;
}
$hereurl = new moodle_url('/local/coursegen/activity_preview.php', $hereparams);
$preview->opened_at($hereurl, $page);
$name = $preview->name();

// The page belongs to the course the template is built on, and says so, the
// way a real course page does. That is what keeps the site's own chrome
// behaving like a course page's: without a course, the primary navigation
// falls back to marking the site home as where the reader is, which on a page
// about a course is a lie. Nothing of the course is drawn from this: the
// course index, which would list the real course, is turned off, and the
// trail is written here from the payload rather than taken from the course.
$templaterecord = template::get_record(['id' => $templateid]);
$templatecourseid = $templaterecord->get('courseid');
$templatecourse = get_course($templatecourseid);
$PAGE->set_course($templatecourse);
$PAGE->set_show_course_index(false);

// The page is told which course module it is about, because that is what the
// theme draws a module page's own chrome from: the activity icon beside the
// heading, the activity header with the description under it, the skip target
// where a module page puts it. For a kept activity that is the activity
// itself; for one the run writes it is the mould it is built into. Nothing of
// the module's state is drawn from it: completion, which would show the
// reader's standing in the template course, is hidden, and the activity
// navigation at the foot, which would lead to the template's real modules, is
// not drawn on a course whose format has a course index, which is where this
// page's formats keep it.
$sourcecmid = $source['cmid'] ?? 0;
$sourcecmid = (int) $sourcecmid;
$modinfo = get_fast_modinfo($PAGE->course);
$courseformat = course_get_format($PAGE->course);
if ($sourcecmid > 0 && isset($modinfo->cms[$sourcecmid]) && $courseformat->uses_course_index()) {
    $sourcecm = $modinfo->get_cm($sourcecmid);
    $PAGE->set_cm($sourcecm);
    $record = $preview->activity_record();
    $recordid = $record->id ?? 0;
    $recordid = (int) $recordid;
    if ($record !== null && $recordid === (int) $PAGE->cm->instance) {
        $record->course = $PAGE->course->id;
        $PAGE->set_activity_record($record);
    }
}
$PAGE->navbar->ignore_active(true);
$PAGE->set_url('/local/coursegen/activity_preview.php',
    ['sessionid' => $sessionid, 'uid' => $uid, 'page' => $page]);
$PAGE->set_pagelayout('incourse');
// The width the module reads its own page at, and the kind of page it is,
// which is where a module's own styles are hung. Without them the activity is
// laid out by nothing that belongs to it: its content keeps the width its
// author gave it and everything around it spreads to the window, so the two
// stop lining up.
if ($preview->limited_width()) {
    $PAGE->add_body_class('limitedwidth');
}
$PAGE->set_pagetype('mod-' . $modname . '-view');
$PAGE->add_body_class('local-coursegen-activity-preview');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($name);
$PAGE->set_heading($name);

local_coursegen_activity_preview_add_breadcrumb($payload, $uid, $sessionid);
$PAGE->navbar->add($name);

// What the module itself adds to the page header, beside the heading.
$headerbutton = $preview->header_button();
if ($headerbutton !== '') {
    $PAGE->set_button($headerbutton);
}

// Moodle's own activity header, the strip every module page opens with, in
// the theme's own markup: the theme decides whether the name is repeated in
// it, and the description is what the module puts there, read from the
// payload. Completion is the reader's standing in the template course, not
// part of the template, so it is not drawn.
$PAGE->activityheader->set_attrs(['hidecompletion' => true]);
// A module that sets the header's title does so only where the theme allows
// one there (mod/workshop/view.php asks the same).
$headertitle = $preview->header_title();
if ($headertitle !== '' && $PAGE->activityheader->is_title_allowed()) {
    $PAGE->activityheader->set_attrs(['title' => $headertitle]);
}
$headerdescription = $preview->header_description();
$PAGE->activityheader->set_description($headerdescription);

local_coursegen_activity_preview_add_side_blocks($preview);

// Asking for the navigation settles the theme, so it comes after everything
// the theme is told about the page: its layout, its kind, its width and its
// blocks.
local_coursegen_activity_preview_deactivate_primary_nav();

echo $OUTPUT->header();
$noticemessage = get_string('courseai_preview_notice', 'local_coursegen');
echo $OUTPUT->notification($noticemessage, \core\output\notification::NOTIFY_INFO);
echo $preview->render();
echo $OUTPUT->footer();
