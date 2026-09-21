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
use local_coursegen\local\service\template_ai_api_service;

$sessionid = required_param('sessionid', PARAM_INT);

// The name the answer itself gives this activity, used exactly as it arrives.
// Deriving one here instead is how the 900000-based number came about, and it
// bought nothing: the answer already names every element it describes.
$uid = required_param('uid', PARAM_ALPHANUMEXT);

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
            break;
        }
    }
}

if (!$parameters) {
    throw new moodle_exception('courseai_preview_not_found', 'local_coursegen');
}

$preview = preview_factory::for_activity($modname, $parameters);
$name = $preview->name();

$PAGE->set_url('/local/coursegen/activity_preview.php', ['sessionid' => $sessionid, 'uid' => $uid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('local-coursegen-activity-preview');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($name);
$PAGE->set_heading($name);

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
