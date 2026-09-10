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
 * CLI end-to-end test: "create a course from a template" pipeline, driven by
 * the real coursegen-template HTTP service instead of the mock AI.
 *
 * Simulates, from the command line, the entire flow a professor/admin
 * triggers from the web UI:
 *
 * 1. Reads a hand-authored template configuration fixture (NOT a saved
 *    template row) describing which real activities in a real base course
 *    get which behavior.
 * 2. Calls \local_coursegen\external\save_template::execute() with it,
 *    exactly mirroring what the web UI's "Save" action does, so real
 *    template_section/template_activity rows are persisted.
 * 3. Swaps in http_template_ai_service (HTTP calls to coursegen-template)
 *    via template_course_builder_service::set_ai_service().
 * 4. Calls template_course_builder_service::create_course_from_template().
 * 5. Prints the result, then resets the AI service back to null so nothing
 *    lingers changed for any other process.
 *
 * Usage:
 *   php cli/create_course_from_template_e2e.php
 *   php cli/create_course_from_template_e2e.php --fixture=/path/to/config.json --userid=2
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\external\save_template;
use local_coursegen\local\models\template;
use local_coursegen\local\service\http_template_ai_service;
use local_coursegen\local\service\template_course_builder_service;

[$options, $unrecognised] = cli_get_params(
    [
        'fixture' => __DIR__ . '/fixtures/cli_e2e_template_config.json',
        'userid'  => 2,
        'help'    => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(
        "Create a course from a local_coursegen template, end-to-end, via the coursegen-template HTTP service.\n\n" .
        "Options:\n" .
        " --fixture=PATH   Path to the template configuration JSON fixture " .
        "(default: cli/fixtures/cli_e2e_template_config.json)\n" .
        " --userid=ID      User id performing the creation (default: 2)\n" .
        " -h, --help       Print this help.\n"
    );
    exit(0);
}

$fixturepath = (string) $options['fixture'];
$userid = (int) $options['userid'];

cli_writeln('== local_coursegen: template CLI end-to-end test ==');
cli_writeln('Fixture: ' . $fixturepath);
cli_writeln('User id: ' . $userid);
cli_writeln('');

if (!is_readable($fixturepath)) {
    cli_error('Fixture file not found or not readable: ' . $fixturepath);
}

$json = file_get_contents($fixturepath);
$config = json_decode($json, true);
if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
    cli_error('Fixture file is not valid JSON: ' . $fixturepath . ' (' . json_last_error_msg() . ')');
}

$admin = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
\core\session\manager::set_user($admin);

// Step 1-2: persist the template exactly like the web UI's "Save" action does.
cli_writeln('Saving template configuration (mirrors template_config_form / manage_templates save)...');
$saved = save_template::execute(
    (int) ($config['id'] ?? 0),
    (string) ($config['name'] ?? ''),
    (string) ($config['description'] ?? ''),
    (int) $config['courseid'],
    (int) ($config['maxsections'] ?? 0),
    (bool) ($config['nolimit'] ?? false),
    (string) ($config['allowedtypes'] ?? '[]'),
    (string) ($config['namingpattern'] ?? ''),
    (int) ($config['namingstart'] ?? 1),
    $config['sections'] ?? []
);

$templateid = (int) $saved['id'];
cli_writeln('Saved template id=' . $templateid . ', name="' . $saved['name'] . '"');
cli_writeln('');

$template = new template($templateid);

// Step 3: wire in the real HTTP-based AI implementation.
cli_writeln('Wiring http_template_ai_service (calls the coursegen-template HTTP service)...');
template_course_builder_service::set_ai_service(new http_template_ai_service());

// Moodle's curl security helper blocks any port not in the site's
// 'curlsecurityallowedport' allowlist — the coursegen-template test service's
// port is very unlikely to already be on it. Temporarily add it for the
// duration of this script only, and restore the original value afterwards,
// so this CLI test never leaves a lingering site config change behind.
$templatebaseurl = get_config('local_coursegen', 'coursegen_template_service_url') ?: 'http://coursegen-template:3000';
$templateport = (string) (parse_url($templatebaseurl, PHP_URL_PORT) ?: 80);
$originalallowedports = (string) $CFG->curlsecurityallowedport;
$allowedportslist = array_filter(array_map('trim', explode("\n", $originalallowedports)), fn($e) => $e !== '');
$portoverridden = false;
if (!in_array($templateport, $allowedportslist, true)) {
    cli_writeln('Temporarily allowing outbound port ' . $templateport . ' (curlsecurityallowedport) for this run...');
    set_config('curlsecurityallowedport', trim($originalallowedports . "\n" . $templateport));
    $portoverridden = true;
}

// Step 4: run the exact same builder the web UI's "Create course" action calls.
cli_writeln('Building the course from the template (no brand-new sections/activities in this fixture)...');
cli_writeln('');

try {
    $result = template_course_builder_service::create_course_from_template(
        $template,
        [], // No new sections: this fixture only acts on real, existing course activities.
        [], // No new activities: same reason.
        $userid
    );
} finally {
    // Always reset, so nothing lingers changed for any other process/request.
    template_course_builder_service::set_ai_service(null);
    if ($portoverridden) {
        set_config('curlsecurityallowedport', $originalallowedports);
    }
}

cli_writeln('== Result ==');
cli_writeln('success:       ' . ($result['success'] ? 'YES' : 'NO'));
cli_writeln('courseid:      ' . $result['courseid']);
cli_writeln('courseurl:     ' . $result['courseurl']);
cli_writeln('fullname:      ' . $result['fullname']);
cli_writeln('shortname:     ' . $result['shortname']);
cli_writeln('message:       ' . $result['message']);
cli_writeln('haswarnings:   ' . ($result['haswarnings'] ? 'YES' : 'NO'));
cli_writeln('warningscount: ' . $result['warningscount']);

if (!empty($result['activityerrors'])) {
    cli_writeln('');
    cli_writeln('activityerrors:');
    foreach ($result['activityerrors'] as $error) {
        $title = $error['title'] ?? '';
        cli_writeln(
            ' - [' . ($error['resource_type'] ?? '?') . '] section ' . ($error['section'] ?? '?')
            . ($title !== '' ? ' "' . $title . '"' : '') . ': ' . ($error['message'] ?? '')
        );
    }
}

cli_writeln('');
cli_writeln($result['success']
    ? ('DONE: course ' . $result['courseid'] . ' created successfully.')
    : 'FAILED: course was not created.');

exit($result['success'] ? 0 : 1);
