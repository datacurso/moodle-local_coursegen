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
 * Manual test page: create a course from the unification of a real existing
 * course's structure and a real .mbz backup's content, without going through
 * the real Datacurso AI backend.
 *
 * Uses the standard post/redirect/get pattern. The result cannot travel as a
 * session-flash notification: create_course_service::create_course() calls
 * \core\session\manager::write_close() internally (so other tabs are not
 * blocked during a potentially long operation), and anything written to the
 * session after that point is silently never persisted. The outcome travels
 * as redirect URL parameters instead.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_coursegen\local\service\course_export_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

// Test service started via coursegen_template/compose.yml, on the same
// external 'moodle' docker network as this Moodle site.
$nodeserviceurl = 'http://coursegen-template:3000';

$sourcecourseid = optional_param('sourcecourseid', 422, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/coursegen/testcoursegen.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('testcoursegen_title', 'local_coursegen'));
$PAGE->set_heading(get_string('testcoursegen_title', 'local_coursegen'));

if ($action === 'create' && confirm_sesskey()) {
    $redirectparams = ['sourcecourseid' => $sourcecourseid];

    try {
        $courseexport = course_export_service::export_course($sourcecourseid);

        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $response = $curl->post($nodeserviceurl . '/api/course-result', json_encode($courseexport));

        if ($curl->get_errno()) {
            throw new \Exception('Could not reach the coursegen_template test service: ' . $curl->error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['result'])) {
            throw new \Exception('Unexpected response from the coursegen_template test service: ' . $response);
        }
        $resultdata = $decoded['result'];

        $session = course_session_service::create_from_form_data(
            new stdClass(),
            $USER->id,
            'testcoursegen-' . bin2hex(random_bytes(8))
        );

        $creationresult = create_course_service::create_course($session, $resultdata, []);

        if (!empty($creationresult['success'])) {
            $redirectparams['courseid'] = $creationresult['courseid'];
            $redirectparams['warnings'] = count($creationresult['activityerrors'] ?? []);
        } else {
            $redirectparams['error'] = $creationresult['message'];
        }
    } catch (\Throwable $e) {
        $redirectparams['error'] = $e->getMessage();
    }

    redirect(new moodle_url('/local/coursegen/testcoursegen.php', $redirectparams));
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$warnings = optional_param('warnings', 0, PARAM_INT);
$error = optional_param('error', '', PARAM_TEXT);

echo $OUTPUT->header();

if ($courseid > 0) {
    $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    $message = get_string('testcoursegen_success', 'local_coursegen', $courseid) . ' ' . $courseurl->out(false);
    if ($warnings > 0) {
        $message .= ' (' . $warnings . ' activity warning(s), see the debug log)';
    }
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
} else if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

if ($courseid > 0 || $error !== '') {
    // Scrub courseid/warnings/error from the visible URL once shown, so a
    // plain reload lands back on the clean form instead of re-showing (or
    // re-submitting) this same result.
    echo html_writer::script('history.replaceState(null, "", ' . json_encode($pageurl->out(false)) . ');');
}

echo html_writer::tag('p', get_string('testcoursegen_desc', 'local_coursegen', $sourcecourseid));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
echo html_writer::div(
    html_writer::label(get_string('testcoursegen_sourcecourse', 'local_coursegen'), 'id_sourcecourseid') .
    html_writer::empty_tag('input', [
        'type' => 'number',
        'id' => 'id_sourcecourseid',
        'name' => 'sourcecourseid',
        'value' => $sourcecourseid,
        'class' => 'form-control w-auto d-inline-block ml-2',
    ]),
    'mb-3'
);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('testcoursegen_button', 'local_coursegen'),
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
