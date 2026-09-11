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
 * CLI: the full round trip, in one script — back up a real course, send that
 * ONE .mbz package to the coursegen-template service, and restore whatever
 * .mbz package it hands back into a brand-new course.
 *
 * The service (see the coursegen-template repo) unpacks the uploaded backup,
 * splices a real reference course's own activity XML and files directly into
 * the template's OWN backup files (keeping the template's own module/section
 * identity — moduleid/contextid — untouched), and returns an already-merged
 * .mbz. That means Moodle only ever has to do exactly what it always does
 * with a real backup file: extract it and restore it. There is no per
 * activity-type PHP here at all — mod_lesson, mod_resource, mod_page,
 * whatever the course contains, all go through the exact same core restore
 * machinery every real backup goes through, because that IS what this is: a
 * real backup file, indistinguishable from one produced by "Backup" in the
 * UI. This mirrors admin/cli/restore_backup.php's own restore-from-file
 * sequence.
 *
 * Usage:
 *   php cli/recreate_course_from_service.php --courseid=397
 *   php cli/recreate_course_from_service.php --courseid=397 --service-url=http://localhost:3000
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

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

$usage = "Back up a real course, send it to the coursegen-template service, and restore " .
    "the merged backup it returns into a brand-new course.\n\n" .
    "Options:\n" .
    " --courseid=ID       Id of the source course (required).\n" .
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
$exportbc = null;
$rc = null;
$restorebasepath = null;
$tmpmbzpath = null;

try {
    $client = new coursegen_template_client($serviceurl);
    $baseurl = $client->get_base_url();

    mtrace('== local_coursegen: recreate course via coursegen-template service ==');
    mtrace('Source course id: ' . $courseid);
    mtrace('Service URL:      ' . $baseurl);
    mtrace('');

    $serviceport = (string) (parse_url($baseurl, PHP_URL_PORT) ?: 80);
    $allowedportslist = array_filter(array_map('trim', explode("\n", $originalallowedports)), fn($e) => $e !== '');
    if (!in_array($serviceport, $allowedportslist, true)) {
        mtrace('Temporarily allowing outbound port ' . $serviceport . ' (curlsecurityallowedport) for this run...');
        set_config('curlsecurityallowedport', trim($originalallowedports . "\n" . $serviceport));
        $portoverridden = true;
    }

    $sourcecourse = get_course($courseid);

    // --- Step 1: a full, self-contained backup (real files included) — the
    // service needs the real bytes to splice real files into it. ---
    mtrace('Backing up course (Moodle\'s own real backup, same as "Backup")...');
    $exportbc = new \backup_controller(
        \backup::TYPE_1COURSE,
        $courseid,
        \backup::FORMAT_MOODLE,
        \backup::INTERACTIVE_NO,
        \backup::MODE_GENERAL,
        get_admin()->id,
        \backup::RELEASESESSION_YES
    );
    $exportbc->execute_plan();
    /** @var \stored_file $backupfile */
    $backupfile = $exportbc->get_results()['backup_destination'];
    mtrace('  package: ' . $backupfile->get_filename() . ' (' . display_size($backupfile->get_filesize()) . ')');
    $exportbasepath = $exportbc->get_plan()->get_basepath();
    $exportbc->destroy();
    $exportbc = null;
    if (empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($exportbasepath);
    }
    mtrace('');

    // --- Step 2: send that package to the service; it returns a URL to an
    // already-merged .mbz (the template's own package, with real reference
    // content spliced directly into its activity XML/files). ---
    mtrace('Sending .mbz to ' . COURSEGEN_BACKUP_ENDPOINT . '...');
    $serviceresponse = $client->upload_file(COURSEGEN_BACKUP_ENDPOINT, $backupfile);
    if (!is_array($serviceresponse) || empty($serviceresponse['backup_url'])) {
        throw new \moodle_exception('error_template_service_response', 'local_coursegen', '',
            'Response did not include a backup_url.');
    }
    mtrace('  sections: ' . ($serviceresponse['sections_found'] ?? '?')
        . '  activities: ' . ($serviceresponse['activities_found'] ?? '?')
        . '  spliced: ' . ($serviceresponse['activities_spliced'] ?? '?')
        . '  skipped: ' . ($serviceresponse['activities_skipped'] ?? '?'));
    mtrace('');

    // --- Step 3: download the merged package and restore it exactly the way
    // admin/cli/restore_backup.php restores any uploaded backup file: extract
    // it into a fresh backup temp dir, then a plain restore_controller
    // targeting a new course. Real backup in, real course out — no per
    // activity-type code involved. ---
    mtrace('Downloading merged backup...');
    $mbzcontent = $client->download_raw((string) $serviceresponse['backup_url']);
    $tmpmbzpath = $CFG->tempdir . '/coursegen_merged_' . uniqid() . '.mbz';
    file_put_contents($tmpmbzpath, $mbzcontent);
    mtrace('  saved: ' . display_size(strlen($mbzcontent)));
    mtrace('');

    mtrace('Restoring the merged backup into a new course...');
    $backupid = \restore_controller::get_tempdir_name(SITEID, get_admin()->id);
    $restorebasepath = make_backup_temp_directory($backupid);
    get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($tmpmbzpath, $restorebasepath);
    unlink($tmpmbzpath);
    $tmpmbzpath = null;

    $newcourseid = \restore_dbops::create_new_course(
        (string) $sourcecourse->fullname,
        \core_text::substr((string) $sourcecourse->shortname . '-' . time(), 0, 100),
        (int) $sourcecourse->category
    );

    $rc = new \restore_controller(
        $backupid,
        $newcourseid,
        \backup::INTERACTIVE_NO,
        \backup::MODE_GENERAL,
        get_admin()->id,
        \backup::TARGET_NEW_COURSE
    );
    $rc->execute_precheck();
    $rc->execute_plan();
    $rc->destroy();
    $rc = null;

    rebuild_course_cache($newcourseid, true);
    \cache_helper::purge_by_event('changesincourse');

    mtrace('');
    mtrace('== Result ==');
    mtrace('New course id:  ' . $newcourseid);
    mtrace('New course URL: ' . (new \moodle_url('/course/view.php', ['id' => $newcourseid]))->out(false));
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
    if ($exportbc !== null) {
        $exportbc->destroy();
    }
    if ($rc !== null) {
        $rc->destroy();
    }
    if ($tmpmbzpath !== null && file_exists($tmpmbzpath)) {
        unlink($tmpmbzpath);
    }
    if ($restorebasepath !== null && empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($restorebasepath);
    }
}

exit($exitcode);
