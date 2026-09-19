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
$PAGE->requires->css(new moodle_url('/local/coursegen/styles/template_mode_prompt.css', ['v' => $cssrev]));

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;

$resumesessionid = optional_param('sessionid', 0, PARAM_INT);
$showsessionsview = optional_param('view', '', PARAM_ALPHA) === 'courses';
// A fresh visit opens on the choice of starting point (free creation or from
// a template). The old ?mode=template link still works, as "the template path
// with the list of templates open", and ?templateid= opens it with that
// template chosen; a resumed session skips the choice, as it was made.
// Picking a card writes its own ?mode= into the address, so a reload lands
// back on that path instead of the choice screen: the parameter being
// absent is what means "nothing chosen yet", not the value 'free' itself.
$modeparam = optional_param('mode', null, PARAM_ALPHA);
$opentemplates = $modeparam === 'template';
$preselecttemplateid = optional_param('templateid', 0, PARAM_INT);
$startchooser = !$resumesessionid && $modeparam === null && !$preselecttemplateid;
// The top bar's path crumb (start_path.js) needs to know which name to show
// from the very first render too, or it sits empty until the JS bundle
// finishes loading: null while the cards are showing, otherwise whichever
// path is actually opening - the same rule start_path.js falls back to itself.
$initialstartpath = $startchooser ? null : (($opentemplates || $preselecttemplateid > 0) ? 'template' : 'free');

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
    $tplcoursefullname = '';
    if ($tplcourse) {
        $tplcoursefullname = format_string($tplcourse->fullname);
    }
    $coursetemplates[] = [
        'id' => (int) $tpl->get('id'),
        'name' => $tpl->get('name'),
        'courseid' => (int) $tpl->get('courseid'),
        'coursefullname' => $tplcoursefullname,
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
    $title = \core_text::str_max_bytes($rawtitle, $maxtitle);
    if (empty($title)) {
        $title = get_string('courseai_untitled', 'local_coursegen');
    }
    return [
        'id' => $session->get('id'),
        'title' => $title,
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

// The sidebar's pinned/closed state is a per-user preference: read it here so
// the first render already carries the right class, with no flash and no
// dependency on browser storage (see lib.php's local_coursegen_user_preferences()).
$sidebarpinned = (bool) get_user_preferences('local_coursegen_sidebar_pinned', true);

// Native Moodle form (single autocomplete field) whose <select> is the value
// template_mode.js listens to. It is the template column's picker: a template
// named in the address is its value from the first render, so the field
// shows it as a tag straight away.
$templatepickerform = new \local_coursegen\form\course_template_picker_form(
    null, ['templates' => $coursetemplates, 'preselect' => $preselecttemplateid], 'post', '', ['id' => 'tpl-select-form']);
ob_start();
$templatepickerform->display();
$templatepickerformhtml = ob_get_clean();

// The template picker's label carries Moodle's standard help icon, with the
// same explanation the native form field used to show.
$templatepickerhelp = $OUTPUT->help_icon('courseai_template_picker', 'local_coursegen');

// Prepare template context.
$templatecontext = [
    'templatepickerhelp' => $templatepickerhelp,
    'guidelines' => json_encode($systeminstructions),
    'coursetemplates' => $coursetemplates,
    'templatepickerformhtml' => $templatepickerformhtml,
    'hascoursetemplates' => !empty($coursetemplates),
    'languages' => json_encode($languageoptions),
    'defaultlang' => current_language(),
    'logourl' => $logourl->out(),
    'hassessions' => !empty($recent5),
    'sessions' => $recent5,
    'allsessions' => $allsessionsdata,
    'isresuming' => $resumesessionid > 0,
    'showsessionsview' => $showsessionsview,
    'subsectionsenabled' => $subsectionsenabled,
    'closeurl' => (new moodle_url('/my/courses.php'))->out(false),
    'sidebarclosed' => !$sidebarpinned,
    'startchooser' => $startchooser,
    // Which path opens is decided here, not learned after the JS bundle runs:
    // the class that shows the template column belongs on the very first
    // render, or a moment of the free hero flashes before JS corrects it.
    'initialtemplate' => $opentemplates || $preselecttemplateid > 0,
    'showstartcrumb' => $initialstartpath !== null,
    'initialstartpath' => $initialstartpath,
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
        'opentemplates' => $opentemplates,
        'preselecttemplateid' => $preselecttemplateid,
    ],
]);

echo $OUTPUT->footer();
