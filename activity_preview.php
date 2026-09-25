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
use local_coursegen\local\preview\plan_activity;
use local_coursegen\local\preview\preview_factory;
use local_coursegen\local\preview\real_activity;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_ai_api_service;

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

$api = new template_ai_api_service();
$threadid = (string) $session->get('session_id');

// Exactly what was sent to the service, read again rather than remembered. It
// describes every activity of the template, kept or written, and the mould a
// written one is built into, and names each by the uid the answer echoes.
$coursedata = json_decode((string) $session->get('coursedata'), true);
$templateid = (int) ($coursedata['templateid'] ?? 0);
$payload = [];
if ($templateid > 0) {
    $payload = template_export_service::build_init_payload($templateid);
}
$activitybycmid = static function (int $cmid) use ($payload): array {
    foreach (($payload['activities'] ?? []) as $activity) {
        if ((int) ($activity['cmid'] ?? 0) === $cmid) {
            return $activity;
        }
    }
    return [];
};

// The finished activity when there is one, the draft while there is not. A run
// under review has no result yet, and asking for one is how that is found out.
// Alongside what will be shown travels what it is shown against: the activity
// as the payload describes it, which for one the run writes is its mould.
$modname = '';
$parameters = [];
$source = [];
try {
    foreach (($api->get_result($threadid)['generated_activities'] ?? []) as $activity) {
        if ((string) ($activity['uid'] ?? '') === $uid) {
            $modname = (string) ($activity['resource_type'] ?? '');
            $parameters = (array) ($activity['parameters'] ?? []);
            $source = $activitybycmid((int) (($activity['template_behavior'] ?? [])['template_source_cmid'] ?? 0));
            break;
        }
    }
} catch (moodle_exception $exception) {
    $parameters = [];
}

// A run the service no longer knows, or one that never reached it, has no
// plan to ask for; its kept activities are still in the payload and still
// preview.
$plan = [];
try {
    $plan = $api->get_plan($threadid)['template_plan'] ?? [];
} catch (moodle_exception $exception) {
    $plan = [];
}

// A plan describes the pieces the mould offered to fill, and a mould also
// holds pieces it offers to nobody, which carry through to the delivered
// activity as they are. So the mould is what is shown, with the plan laid
// over it.
$fromplan = static function () use ($plan, $uid, $session, $activitybycmid): array {
    foreach ($plan as $entry) {
        if ((string) ($entry['uid'] ?? '') !== $uid) {
            continue;
        }
        $entrymodname = (string) ($entry['resource_type'] ?? '');
        $entryparameters = plan_activity::to_parameters((array) $entry);
        $entryparameters = plan_activity::over_mould(
            $entryparameters,
            $entrymodname,
            (int) ($entry['source_cmid'] ?? 0),
            $session
        );
        return [
            'modname' => $entrymodname,
            'parameters' => $entryparameters,
            'source' => $activitybycmid((int) ($entry['source_cmid'] ?? 0)),
        ];
    }
    return [];
};
if (!$parameters) {
    $found = $fromplan();
    if ($found) {
        $modname = $found['modname'];
        $parameters = $found['parameters'];
        $source = $found['source'];
    }
}

// An activity the run keeps rather than writes is not in the answer at all,
// so it is read from what was sent: the payload describes every activity of
// the template completely, and names each one by the same uid.
$fromkept = static function () use ($payload, $uid): array {
    foreach (($payload['activities'] ?? []) as $activity) {
        if ((string) ($activity['uid'] ?? '') !== $uid) {
            continue;
        }
        return [
            'modname' => (string) ($activity['resource_type'] ?? ''),
            'parameters' => real_activity::to_parameters($activity),
            'source' => $activity,
        ];
    }
    return [];
};
if (!$parameters) {
    $found = $fromkept();
    if ($found) {
        $modname = $found['modname'];
        $parameters = $found['parameters'];
        $source = $found['source'];
    }
}

if (!$parameters) {
    throw new moodle_exception('courseai_preview_not_found', 'local_coursegen');
}

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
$preview->opened_at(
    new moodle_url('/local/coursegen/activity_preview.php', $hereparams),
    $page
);
$name = $preview->name();

// The page belongs to the course the template is built on, and says so, the
// way a real course page does. That is what keeps the site's own chrome
// behaving like a course page's: without a course, the primary navigation
// falls back to marking the site home as where the reader is, which on a page
// about a course is a lie. Nothing of the course is drawn from this: the
// course index, which would list the real course, is turned off, and the
// trail is written here from the payload rather than taken from the course.
$PAGE->set_course(get_course(template::get_record(['id' => $templateid])->get('courseid')));
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
$sourcecmid = (int) ($source['cmid'] ?? 0);
$modinfo = get_fast_modinfo($PAGE->course);
if ($sourcecmid > 0 && isset($modinfo->cms[$sourcecmid]) && course_get_format($PAGE->course)->uses_course_index()) {
    $PAGE->set_cm($modinfo->get_cm($sourcecmid));
    $record = $preview->activity_record();
    if ($record !== null && (int) ($record->id ?? 0) === (int) $PAGE->cm->instance) {
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

// Where this activity sits, which is how a reader gets back out of it. A real
// activity page builds this from the course it belongs to; this one has no
// course to ask, so it is read from the payload, which says the same thing.
$coursename = (string) (($payload['course_configuration'] ?? [])['fullname'] ?? '');
if ($coursename !== '') {
    $PAGE->navbar->add(
        $coursename,
        new moodle_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid])
    );
}
$sectionnumber = null;
foreach (($payload['activities'] ?? []) as $activity) {
    if ((string) ($activity['uid'] ?? '') === $uid) {
        $sectionnumber = (int) (($activity['parameters'] ?? [])['section'] ?? 0);
        break;
    }
}
foreach (($payload['sections_info'] ?? []) as $info) {
    if ($sectionnumber !== null && (int) ($info['section'] ?? -1) === $sectionnumber) {
        $PAGE->navbar->add(
            (string) ($info['name'] ?? ''),
            new moodle_url('/local/coursegen/course_preview.php', [
                'sessionid' => $sessionid,
                'section' => $sectionnumber,
            ])
        );
        break;
    }
}
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
$PAGE->activityheader->set_description($preview->header_description());

// A module's own side blocks are part of how it looks: a lesson with its menu
// turned on is read with that menu beside it.
foreach ($preview->side_blocks() as $block) {
    $PAGE->blocks->add_fake_block($block, BLOCK_POS_LEFT);
}

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
    get_string('courseai_preview_notice', 'local_coursegen'),
    \core\output\notification::NOTIFY_INFO
);
echo $preview->render();
echo $OUTPUT->footer();
