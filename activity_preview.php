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

// The finished activity when there is one, the draft while there is not. A run
// under review has no result yet, and asking for one is how that is found out.
$modname = '';
$parameters = [];
try {
    foreach (($api->get_result($threadid)['generated_activities'] ?? []) as $activity) {
        if ((string) ($activity['uid'] ?? '') === $uid) {
            $modname = (string) ($activity['resource_type'] ?? '');
            $parameters = (array) ($activity['parameters'] ?? []);
            break;
        }
    }
} catch (moodle_exception $exception) {
    $parameters = [];
}

if (!$parameters) {
    foreach (($api->get_plan($threadid)['template_plan'] ?? []) as $entry) {
        if ((string) ($entry['uid'] ?? '') === $uid) {
            $modname = (string) ($entry['resource_type'] ?? '');
            $parameters = plan_activity::to_parameters((array) $entry);
            // A plan describes the pieces the mould offered to fill, and a
            // mould also holds pieces it offers to nobody, which carry through
            // to the delivered activity as they are. So the mould is what is
            // shown, with the plan laid over it.
            $parameters = plan_activity::over_mould(
                $parameters,
                $modname,
                (int) ($entry['source_cmid'] ?? 0),
                $session
            );
            break;
        }
    }
}

// An activity the run keeps rather than writes is not in the answer at all,
// so it is read from what was sent: the payload describes every activity of
// the template completely, and names each one by the same uid.
if (!$parameters) {
    $coursedata = json_decode((string) $session->get('coursedata'), true);
    $templateid = (int) ($coursedata['templateid'] ?? 0);
    if ($templateid > 0) {
        foreach ((template_export_service::build_init_payload($templateid)['activities'] ?? []) as $activity) {
            if ((string) ($activity['uid'] ?? '') === $uid) {
                $modname = (string) ($activity['resource_type'] ?? '');
                $parameters = real_activity::to_parameters($activity);
                break;
            }
        }
    }
}

if (!$parameters) {
    throw new moodle_exception('courseai_preview_not_found', 'local_coursegen');
}

$coursedata = json_decode((string) $session->get('coursedata'), true);
$templateid = (int) ($coursedata['templateid'] ?? 0);
$payload = $templateid > 0 ? template_export_service::build_init_payload($templateid) : [];

// The activity is read in its course's language, which is the language its
// content is in and the one it will be read in once it exists.
if (!empty($payload['lang'])) {
    force_current_language((string) $payload['lang']);
}

$preview = preview_factory::for_activity($modname, $parameters);
$preview->opened_at(
    new moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => $sessionid, 'uid' => $uid]),
    $page
);
$name = $preview->name();

$PAGE->set_url('/local/coursegen/activity_preview.php',
    ['sessionid' => $sessionid, 'uid' => $uid, 'page' => $page]);
$PAGE->set_context($context);
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

// Moodle's own activity header, the strip every module page opens with: the
// activity's name and its description, in the theme's own markup. It is built
// from a page and a user rather than from a course module, so a preview can
// carry the real one instead of drawing a heading that resembles it, and every
// type gets it without a line of its own.
$PAGE->activityheader->set_title($name);
$PAGE->activityheader->set_description($preview->header_description());

// A module's own side blocks are part of how it looks: a lesson with its menu
// turned on is read with that menu beside it.
foreach ($preview->side_blocks() as $block) {
    $PAGE->blocks->add_fake_block($block, BLOCK_POS_LEFT);
}

echo $OUTPUT->header();
echo $OUTPUT->notification(
    get_string('courseai_preview_notice', 'local_coursegen'),
    \core\output\notification::NOTIFY_INFO
);
echo $preview->render();
echo $OUTPUT->footer();
