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
 * AI Course Creation Wizard - Step 1: Context
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();

// Check permissions.
$systemcontext = context_system::instance();
require_capability('moodle/course:create', $systemcontext);
require_capability('local/coursegen:createcoursewithai', $systemcontext);

// Set up the page.
$url = new moodle_url('/local/coursegen/aicoursecreation.php');
$PAGE->set_url($url);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('createwithai', 'local_coursegen'));

// Load courseai CSS + sidebar CSS.
$PAGE->requires->css('/local/coursegen/styles/aicoursecreation.css');
$PAGE->requires->css('/local/coursegen/styles/chatui.css');
$PAGE->requires->css('/local/coursegen/styles/sidebar.css');

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;

$resumesessionid = optional_param('sessionid', 0, PARAM_INT);

// Load system instructions (directrices institucionales).
$systeminstructions = [];
$records = $DB->get_records('local_coursegen_system_instruction', ['deleted' => 0], 'name ASC');
foreach ($records as $record) {
    $systeminstructions[] = [
        'id' => 'si_' . $record->id,
        'name' => $record->name,
        'category' => 'General', // The table doesn't have a category field, using default.
        'description' => $record->content ?? '',
    ];
}

// Get available languages (only those supported by the plugin).
$supportedlangs = ['es', 'en', 'de', 'ru', 'pt', 'fr', 'id'];
$alllanguages = get_string_manager()->get_list_of_languages(null, 'iso6391');

$languageoptions = [];
foreach ($supportedlangs as $code) {
    if (isset($alllanguages[$code])) {
        $languageoptions[] = [
            'code' => $code,
            'name' => $alllanguages[$code] . ' (' . strtoupper($code) . ')',
        ];
    }
}

// Helper to build session data array.
$buildsessiondata = function ($session, $maxtitle = 50) {
    $statuslabels = [
        course_session::STATUS_PENDING => get_string('status_pending', 'local_coursegen'),
        course_session::STATUS_CREATING => get_string('status_creating', 'local_coursegen'),
        course_session::STATUS_FAILED => get_string('status_failed', 'local_coursegen'),
    ];
    $coursedata = json_decode($session->get('coursedata') ?? '{}', true);
    $rawtitle = $coursedata['fullname'] ?? $coursedata['local_coursegen_custom_prompt'] ?? '';
    return [
        'id' => $session->get('id'),
        'title' => \core_text::str_max_bytes($rawtitle, $maxtitle) ?: get_string('courseai_untitled', 'local_coursegen'),
        'statuslabel' => $statuslabels[$session->get('status')] ?? '',
        'status' => $session->get('status'),
        'timecreated' => userdate($session->get('timecreated'), get_string('strftimedatetimeshort', 'langconfig')),
    ];
};

// Get recent 5 sessions for sidebar (including already created ones).
$recentrecords = course_session_service::get_user_inprogress_sessions($USER->id, 5, true);
$recent5 = [];
foreach ($recentrecords as $session) {
    $recent5[] = $buildsessiondata($session, 50);
}

// Get ALL sessions for the full list view.
$allrecords = course_session_service::get_user_inprogress_sessions($USER->id);
$allsessionsdata = [];
foreach ($allrecords as $session) {
    $allsessionsdata[] = $buildsessiondata($session, 80);
}

// Get logo URL.
$logourl = new moodle_url('/local/coursegen/pix/logo.png');

// Prepare template context.
$templatecontext = [
    'guidelines' => json_encode($systeminstructions),
    'languages' => json_encode($languageoptions),
    'defaultlang' => current_language(),
    'logourl' => $logourl->out(),
    'hassessions' => !empty($recent5),
    'sessions' => $recent5,
    'allsessions' => $allsessionsdata,
    'isresuming' => $resumesessionid > 0,
];

echo $OUTPUT->header();

// Navbar (floating top bar like reportbuilder/edit.php).
$navbarcontext = [
    'title' => get_string('createwithai', 'local_coursegen'),
    'logourl' => $logourl->out(),
    'closeurl' => (new moodle_url('/my/courses.php'))->out(false),
];
echo $OUTPUT->render_from_template('local_coursegen/editor_navbar', $navbarcontext);

echo $OUTPUT->render_from_template('local_coursegen/courseai_page', $templatecontext);

// Initialize JavaScript module.
$PAGE->requires->js_call_amd('local_coursegen/courseai', 'init', [
    [
        'guidelines' => $systeminstructions,
        'languages' => $languageoptions,
        'defaultlang' => current_language(),
        'sessions' => $allsessionsdata,
        'resumesessionid' => $resumesessionid,
        'isresuming' => $resumesessionid > 0,
    ],
]);

echo $OUTPUT->footer();
