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
 * TODO describe file aicoursecreation
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

require_login();
$sessionid = required_param('sessionid', PARAM_INT);

$url = new moodle_url('/local/coursegen/aicoursecreation.php', ['sessionid' => $sessionid]);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());

$PAGE->set_heading($SITE->fullname);
echo $OUTPUT->header();

$record = $DB->get_record(
    'local_coursegen_course_sessions',
    ['id' => $sessionid, 'userid' => $USER->id],
    '*',
    MUST_EXIST
);

$contexttype = null;
if (!empty($record->coursedata)) {
    $coursedata = json_decode($record->coursedata);
    if (!empty($coursedata) && !empty($coursedata->local_coursegen_context_type)) {
        $contexttype = (string) $coursedata->local_coursegen_context_type;
    }
}

$pdffilename = null;
if ($contexttype === 'syllabus') {
    $fs = get_file_storage();
    $syscontext = context_system::instance();
    $files = $fs->get_area_files($syscontext->id, 'local_coursegen', 'syllabus', $record->id, 'itemid', false);
    if (!empty($files)) {
        $file = reset($files);
        if ($file) {
            $pdffilename = $file->get_filename();
        }
    }
}

$baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
$baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

$client = new \aiprovider_datacurso\httpclient\ai_course_api(null, $baseurl, $baseurleu);
$streamingurl = $client->get_streaming_url_for_session($record->session_id);

echo $OUTPUT->render_from_template('local_coursegen/aicoursecreation_page', [
    'contexttype' => $contexttype,
    'pdffilename' => $pdffilename,
    'recordid' => (int) $record->id,
]);

$PAGE->requires->js_call_amd('local_coursegen/aicoursecreation_page', 'init', [
    [
        'recordid' => (int) $record->id,
        'streamingurl' => $streamingurl,
    ],
]);

echo $OUTPUT->footer();
