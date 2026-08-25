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
// Boost's popup layout still reserves margin-top on #page for a site navbar
// that this page never renders (nonavbar) — drop it so the app layout can
// reach the true top of the viewport (see aicoursecreation.css).
$PAGE->add_body_class('local-coursegen-aicoursecreation');

// Load courseai CSS + sidebar CSS. Direct plugin stylesheets get NO revision
// from Moodle's cache pipeline, so browsers keep stale copies across plugin
// upgrades — bust them with the plugin version.
$cssrev = get_config('local_coursegen', 'version');
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/aicoursecreation.css', ['v' => $cssrev]));
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/chatui.css', ['v' => $cssrev]));
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/sidebar.css', ['v' => $cssrev]));

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

// Load available course templates.
$coursetemplates = [];
$tplrecords = \local_coursegen\local\models\template::get_records([], 'name', 'ASC');
foreach ($tplrecords as $tpl) {
    $tplcourse = $DB->get_record('course', ['id' => $tpl->get('courseid')], 'id, fullname', IGNORE_MISSING);
    $coursetemplates[] = [
        'id' => (int) $tpl->get('id'),
        'name' => $tpl->get('name'),
        'courseid' => (int) $tpl->get('courseid'),
        'coursefullname' => $tplcourse ? format_string($tplcourse->fullname) : '',
        'description' => $tpl->get('description') ?? '',
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

// Subsections toggle only renders when the feature is enabled and mod_subsection is available.
$subsectionsenabled = \local_coursegen\local\service\course_planning_service::subsections_available();

// Get logo URL (sidebar top bar, left of the collapse toggle).
$logourl = new moodle_url('/local/coursegen/pix/logo.png');

// Prepare template context.
$templatecontext = [
    'guidelines' => json_encode($systeminstructions),
    'coursetemplates' => $coursetemplates,
    'hascoursetemplates' => !empty($coursetemplates),
    'languages' => json_encode($languageoptions),
    'defaultlang' => current_language(),
    'logourl' => $logourl->out(),
    'hassessions' => !empty($recent5),
    'sessions' => $recent5,
    'allsessions' => $allsessionsdata,
    'isresuming' => $resumesessionid > 0,
    'subsectionsenabled' => $subsectionsenabled,
];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_coursegen/courseai_page', $templatecontext);

// Initialize JavaScript module.
$PAGE->requires->js_call_amd('local_coursegen/courseai', 'init', [
    [
        'guidelines' => $systeminstructions,
        'coursetemplates' => $coursetemplates,
        'languages' => $languageoptions,
        'defaultlang' => current_language(),
        'sessions' => $allsessionsdata,
        'resumesessionid' => $resumesessionid,
        'isresuming' => $resumesessionid > 0,
    ],
]);

echo $OUTPUT->footer();
