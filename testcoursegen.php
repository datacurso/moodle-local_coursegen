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
 * The coursegen_template test service is called in two separate steps,
 * correlated by one request-scoped id ($requestid): first the real image
 * files are posted to /api/course-images, then the lightweight JSON payload
 * is posted to /api/course-generation, referencing those same images only by
 * content_hash. Splitting them keeps each endpoint doing one thing (binary
 * upload vs. JSON reconciliation) instead of mixing both in one multipart
 * request.
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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\course_export_service;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\create_course_service;
use local_coursegen\local\service\template_course_builder_service;

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

    // One request-scoped id, generated up front and reused everywhere in this
    // request: it correlates the image upload below with the JSON generation
    // call that references those same images, and is also the course session's
    // own external reference - one id, one meaning, instead of a throwaway
    // batch id invented just for the node service.
    $requestid = 'testcoursegen-' . bin2hex(random_bytes(8));

    try {
        $courseexport = course_export_service::export_course($sourcecourseid);
        $imagefiles = course_export_service::get_exported_image_files();

        // First call: real image file parts only, no JSON. Passing a
        // stored_file as an array value makes Moodle's curl class upload it
        // as a real CURLFile part (see stored_file::add_to_curl_request());
        // it never touches base64 or needs a manual temp copy.
        if (!empty($imagefiles)) {
            $imagepostparams = [];
            foreach ($imagefiles as $contenthash => $file) {
                $imagepostparams[$contenthash] = $file;
            }

            $imagescurl = new \curl();
            $imagescurl->post(
                $nodeserviceurl . '/api/course-images?session_id=' . urlencode($requestid),
                $imagepostparams
            );

            if ($imagescurl->get_errno()) {
                throw new \Exception(
                    'Could not upload images to the coursegen_template test service: ' . $imagescurl->error
                );
            }
        }

        // Second call: the lightweight JSON payload, referencing those same
        // images only by content_hash - it never carries image bytes.
        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $response = $curl->post($nodeserviceurl . '/api/course-generation', json_encode([
            'session_id' => $requestid,
            'course_content' => $courseexport,
        ]));

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
            $requestid
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

// Separate manual test path: the real course-template AI backend (not the
// coursegen_template mock above), targeting exactly one real activity in
// $sourcecourseid. Builds a throwaway template with every activity set to
// 'keep' except the chosen one, set to 'modify' with the given prompt, then
// reuses template_course_builder_service::create_course_from_template()
// verbatim - the same single-request flow the real "create course from
// template" UI already runs in production.
if ($action === 'create_template_test' && confirm_sesskey()) {
    $redirectparams = ['sourcecourseid' => $sourcecourseid];
    $targetcmid = required_param('targetcmid', PARAM_INT);
    $targetprompt = optional_param('targetprompt', '', PARAM_RAW);

    try {
        $course = get_course($sourcecourseid);
        $modinfo = get_fast_modinfo($course);

        $tpl = new template(0);
        $tpl->set('name', 'testcoursegen-template-' . bin2hex(random_bytes(4)));
        $tpl->set('courseid', $sourcecourseid);
        $tpl->create();
        $templateid = (int) $tpl->get('id');

        // One row per real activity, covering every cmid in the course: a
        // missing row defaults to action=modify (export_course_for_template()'s
        // own contract), so every activity needs an explicit 'keep' row here
        // except the single target one.
        foreach ($modinfo->get_cms() as $cm) {
            $sectioninfo = $modinfo->get_section_info($cm->sectionnum);

            $act = new template_activity(0);
            $act->set('templateid', $templateid);
            $act->set('sectionid', (int) $sectioninfo->id);
            $act->set('cmid', (int) $cm->id);

            if ((int) $cm->id === $targetcmid) {
                $act->set('action', 'modify');
                $act->set('prompt', $targetprompt !== '' ? $targetprompt : null);
            } else {
                $act->set('action', 'keep');
            }
            $act->create();
        }

        $creationresult = template_course_builder_service::create_course_from_template($tpl, [], [], $USER->id);

        if (!empty($creationresult['success'])) {
            $redirectparams['courseid'] = $creationresult['courseid'];
            $redirectparams['warnings'] = (int) ($creationresult['warningscount'] ?? 0);
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
    $courselink = html_writer::link($courseurl, $courseurl->out(false), [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
    ]);
    $message = get_string('testcoursegen_success', 'local_coursegen', $courseid) . ' ' . $courselink;
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

echo html_writer::tag('hr', '');
echo html_writer::tag('h4', get_string('testcoursegen_template_title', 'local_coursegen'));
echo html_writer::tag('p', get_string('testcoursegen_template_desc', 'local_coursegen', $sourcecourseid));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create_template_test']);
echo html_writer::div(
    html_writer::label(get_string('testcoursegen_template_targetcmid', 'local_coursegen'), 'id_targetcmid') .
    html_writer::empty_tag('input', [
        'type' => 'number',
        'id' => 'id_targetcmid',
        'name' => 'targetcmid',
        // Real cmid of "Lección 1" inside "Módulo #1" in course 422, looked
        // up directly from this site's own modinfo - override freely.
        'value' => 3125,
        'class' => 'form-control w-auto d-inline-block ml-2',
    ]),
    'mb-3'
);
echo html_writer::div(
    html_writer::label(get_string('testcoursegen_template_targetprompt', 'local_coursegen'), 'id_targetprompt') .
    html_writer::tag('textarea', '', [
        'id' => 'id_targetprompt',
        'name' => 'targetprompt',
        'rows' => 3,
        'class' => 'form-control',
    ]),
    'mb-3'
);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('testcoursegen_template_button', 'local_coursegen'),
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
