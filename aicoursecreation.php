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

use core\context\system;
use core\url;
use local_coursegen\local\language_options;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\system_instruction_service;

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();

// Check permissions.
$systemcontext = system::instance();
require_capability('moodle/course:create', $systemcontext);
require_capability('local/coursegen:createcoursewithai', $systemcontext);

// Set up the page.
$url = new url('/local/coursegen/aicoursecreation.php');
$PAGE->set_url($url);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('createwithai', 'local_coursegen'));

// Load courseai CSS + sidebar CSS. Direct plugin stylesheets get NO revision
// from Moodle's cache pipeline, so browsers keep stale copies across plugin
// upgrades — bust them with the plugin version.
$cssrev = get_config('local_coursegen', 'version');
$PAGE->requires->css(new url('/local/coursegen/styles/aicoursecreation.css', ['v' => $cssrev]));
$PAGE->requires->css(new url('/local/coursegen/styles/chatui.css', ['v' => $cssrev]));
$PAGE->requires->css(new url('/local/coursegen/styles/sidebar.css', ['v' => $cssrev]));

$resumesessionid = optional_param('sessionid', 0, PARAM_INT);

// Load the institutional guidelines (system instructions), alphabetically.
$systeminstructions = [];
foreach (system_instruction_service::get_all('name', 'ASC') as $instruction) {
    $systeminstructions[] = [
        'id' => 'si_' . $instruction->get('id'),
        'name' => $instruction->get('name'),
        'category' => 'General', // The table doesn't have a category field, using default.
        'description' => $instruction->get('content') ?? '',
    ];
}

// Get available languages (only those supported by the plugin).
$languageoptions = language_options::options();

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
$logourl = new url('/local/coursegen/pix/logo.png');

// Subsections toggle only renders when the feature is enabled and mod_subsection is available.
$subsectionsenabled = \local_coursegen\local\service\course_planning_service::subsections_available();

// The page default is always a supported code: the hidden language select and
// the JavaScript state start from it, and the planning endpoint normalises the
// same way (language_options::resolve), so an unsupported Moodle language
// (e.g. "ja") or a regional one ("pt_br") never travels as is.
$defaultlang = language_options::normalize(current_language());

// Prepare template context.
$templatecontext = [
    'guidelines' => json_encode($systeminstructions),
    'languages' => json_encode($languageoptions),
    'defaultlang' => $defaultlang,
    'logourl' => $logourl->out(),
    'hassessions' => !empty($recent5),
    'sessions' => $recent5,
    'allsessions' => $allsessionsdata,
    'isresuming' => $resumesessionid > 0,
    'subsectionsenabled' => $subsectionsenabled,
    // The compact composer renders the shared chips/toolbar partials in their compact variant.
    'compactcomposer' => ['compact' => true],
    // Initial (empty) guideline listboxes; JavaScript re-renders them from the same templates.
    'guidelinelist' => [
        'listlabel' => get_string('courseai_guidelines_list_label', 'local_coursegen'),
        'emptytext' => get_string('courseai_no_results', 'local_coursegen'),
        'hasitems' => false,
        'items' => [],
    ],
];

echo $OUTPUT->header();

// Navbar (floating top bar like reportbuilder/edit.php).
$navbarcontext = [
    'title' => get_string('createwithai', 'local_coursegen'),
    'logourl' => $logourl->out(),
    'closeurl' => (new url('/my/courses.php'))->out(false),
];
echo $OUTPUT->render_from_template('local_coursegen/editor_navbar', $navbarcontext);

echo $OUTPUT->render_from_template('local_coursegen/courseai_page', $templatecontext);

// Initialize JavaScript module.
$PAGE->requires->js_call_amd('local_coursegen/courseai', 'init', [
    [
        'guidelines' => $systeminstructions,
        'languages' => $languageoptions,
        'defaultlang' => $defaultlang,
        'sessions' => $allsessionsdata,
        'resumesessionid' => $resumesessionid,
        'isresuming' => $resumesessionid > 0,
    ],
]);

echo $OUTPUT->footer();
