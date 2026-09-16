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
 * Read-only preview of the course one generation will produce.
 *
 * The course does not exist yet, so nothing that draws a course page can draw
 * this: those renderers all begin from a course id. What does exist is the
 * answer the AI returned, and the template's own structure, and between them
 * they describe the course completely.
 *
 * So the page is assembled from those two: the template says which sections
 * there are and which activities are copied into them, the answer says what the
 * AI is writing, and every activity the AI writes links to its own preview,
 * which draws that activity from the same answer.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\template_ai_api_service;

$sessionid = required_param('sessionid', PARAM_INT);

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

$structure = get_template_structure::execute($templateid);

// What the AI has said so far about each activity it is writing, keyed by the
// name the structure knows that activity by.
$api = new template_ai_api_service();
$summaries = [];
foreach (($api->get_plan((string) $session->get('session_id'))['template_plan'] ?? []) as $entry) {
    $summaries[(string) ($entry['uid'] ?? '')] = (string) ($entry['summary'] ?? '');
}

$sections = [];
foreach ($structure['sections'] as $section) {
    $activities = [];
    foreach ($section['activities'] as $activity) {
        $generationuid = (string) ($activity['generationuid'] ?? '');
        $activities[] = [
            'name' => $activity['name'],
            'modname' => $activity['modname'],
            'purpose' => $activity['purpose'],
            'iconhtml' => $activity['iconhtml'],
            'typelabel' => $activity['typelabel'],
            'aigenerated' => !empty($activity['aigenerated']),
            'summary' => $summaries[$generationuid] ?? '',
            // Only what the AI writes has a preview to open: everything else is
            // copied from the base course unchanged and already exists there.
            'previewurl' => $generationuid !== ''
                ? (new moodle_url('/local/coursegen/activity_preview.php', [
                    'sessionid' => $sessionid,
                    'uid' => $generationuid,
                ]))->out(false)
                : '',
        ];
    }
    $sections[] = [
        'name' => $section['name'],
        'activitycount' => count($activities),
        'activities' => $activities,
    ];
}

$PAGE->set_url('/local/coursegen/course_preview.php', ['sessionid' => $sessionid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');
$PAGE->add_body_class('local-coursegen-course-preview');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('courseai_preview_course_title', 'local_coursegen'));
$PAGE->set_heading(get_string('courseai_preview_course_title', 'local_coursegen'));

echo $OUTPUT->header();
echo $OUTPUT->notification(
    get_string('courseai_preview_course_notice', 'local_coursegen'),
    \core\output\notification::NOTIFY_INFO
);
echo $OUTPUT->render_from_template('local_coursegen/course_preview', ['sections' => $sections]);
echo $OUTPUT->footer();
