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
 * CLI: back up a real course with Moodle's own real backup mechanism and
 * upload the resulting .mbz package to the coursegen-template service in
 * ONE request.
 *
 * Replaces course_exporter's per-activity, per-image JSON construction: an
 * .mbz already IS a single package containing everything (course settings,
 * sections, activities, blocks, completion, every real file) in Moodle's
 * own real backup format — sending that one file is both simpler and more
 * complete than hand-rebuilding an equivalent JSON representation
 * activity-by-activity. The service is responsible for unpacking it and
 * deriving whatever JSON/content representation it needs.
 *
 * Usage:
 *   php cli/backup_course_to_service.php --courseid=397
 *   php cli/backup_course_to_service.php --courseid=397 --service-url=http://localhost:3000
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

use local_coursegen\local\httpclient\coursegen_template_client;

/** Endpoint that accepts the whole .mbz package as one multipart upload. */
const COURSEGEN_BACKUP_ENDPOINT = '/api/course/backup';

[$options, $unrecognised] = cli_get_params(
    [
        'courseid' => null,
        'service-url' => null,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

$usage = "Back up a real course and upload the .mbz package to the coursegen-template service.\n\n" .
    "Options:\n" .
    " --courseid=ID       Id of the course to back up (required).\n" .
    " --service-url=URL   Override the service base URL.\n" .
    " -h, --help          Print this help.\n";

if ($options['help']) {
    cli_writeln($usage);
    exit(0);
}

$courseidraw = $options['courseid'];
if ($courseidraw === null || $courseidraw === '' || !is_numeric($courseidraw) || (int) $courseidraw <= 0) {
    cli_writeln('ERROR: --courseid is required and must be a positive integer.');
    cli_writeln('');
    cli_writeln($usage);
    exit(1);
}
$courseid = (int) $courseidraw;

$serviceurl = $options['service-url'];
$serviceurl = ($serviceurl === null || trim((string) $serviceurl) === '') ? null : trim((string) $serviceurl);

\core\session\manager::set_user(get_admin());

$exitcode = 0;
$portoverridden = false;
$originalallowedports = (string) $CFG->curlsecurityallowedport;
$bc = null;
$backupbasepath = null;

try {
    $client = new coursegen_template_client($serviceurl);
    $baseurl = $client->get_base_url();

    mtrace('== local_coursegen: back up course and send to coursegen-template service ==');
    mtrace('Course id:   ' . $courseid);
    mtrace('Service URL: ' . $baseurl);
    mtrace('');

    $serviceport = (string) (parse_url($baseurl, PHP_URL_PORT) ?: 80);
    $allowedportslist = array_filter(array_map('trim', explode("\n", $originalallowedports)), fn($e) => $e !== '');
    if (!in_array($serviceport, $allowedportslist, true)) {
        mtrace('Temporarily allowing outbound port ' . $serviceport . ' (curlsecurityallowedport) for this run...');
        set_config('curlsecurityallowedport', trim($originalallowedports . "\n" . $serviceport));
        $portoverridden = true;
    }

    mtrace('Backing up course (Moodle\'s own real backup, same .mbz format as the "Backup" UI)...');
    $bc = new \backup_controller(
        \backup::TYPE_1COURSE,
        $courseid,
        \backup::FORMAT_MOODLE,
        \backup::INTERACTIVE_NO,
        \backup::MODE_GENERAL,
        get_admin()->id,
        \backup::RELEASESESSION_YES
    );
    $bc->execute_plan();

    $results = $bc->get_results();
    /** @var \stored_file $file */
    $file = $results['backup_destination'];
    $backupbasepath = $bc->get_plan()->get_basepath();

    mtrace('  package: ' . $file->get_filename() . ' (' . display_size($file->get_filesize()) . ')');
    mtrace('');

    mtrace('Uploading .mbz to ' . COURSEGEN_BACKUP_ENDPOINT . '...');
    $response = $client->upload_file(COURSEGEN_BACKUP_ENDPOINT, $file);

    mtrace('');
    mtrace('== Response ==');
    if (!is_array($response)) {
        mtrace('(the service returned no JSON object)');
    } else {
        mtrace(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    mtrace('');
    mtrace('DONE.');
} catch (\Throwable $e) {
    $exitcode = 1;
    mtrace('');
    mtrace('FAILED: ' . $e->getMessage());
    if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
        mtrace('Details: ' . $e->debuginfo);
    }
} finally {
    if ($portoverridden) {
        set_config('curlsecurityallowedport', $originalallowedports);
    }
    if ($bc !== null) {
        $bc->destroy();
    }
    if ($backupbasepath !== null && empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($backupbasepath);
    }
}

exit($exitcode);
