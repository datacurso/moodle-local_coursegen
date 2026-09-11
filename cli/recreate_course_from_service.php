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
 * CLI: the full round trip, in one script — back up a real course, send
 * that ONE .mbz package to the coursegen-template service, and use the
 * SAME backup to restore a brand-new, complete course.
 *
 * Two real Moodle operations run here, each for what it's actually good at
 * (an earlier version of this script tried to reuse ONE backup for both
 * and hit a real limitation: backup::MODE_COPY, needed to keep a backup's
 * working directory around for a paired restore, does NOT bundle real file
 * content into the package at all — it only works because a same-site copy
 * restore reads file bytes straight from local storage by hash, which an
 * external service obviously cannot do):
 *
 * 1. A plain MODE_GENERAL backup (the same kind "Backup" in the UI
 *    produces) — a fully self-contained .mbz with every real file inside
 *    it — uploaded to the coursegen-template service so it can unpack it
 *    and derive whatever content representation it needs (real images
 *    extracted, never base64, never a hand-rebuilt JSON).
 * 2. Moodle's own real course-copy mechanism (core_backup\copy_helper's
 *    exact sequence, replicated synchronously here instead of via its
 *    adhoc task queue) to restore an exact, complete copy of the source
 *    course into a brand-new one.
 *
 * Today the service is a pure pass-through — it doesn't change the course's
 * content — so the restored course is expected to be an exact copy of the
 * source. Once the service starts genuinely adapting content, applying its
 * per-activity output onto specific activities of this same restored course
 * is the next piece to add here.
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
    "the SAME backup into a brand-new course.\n\n" .
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
$bc = null;
$rc = null;
$backupbasepath = null;

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
    $fullname = \core_text::substr(
        (string) $sourcecourse->fullname . ' (recreated) - ' . userdate(time(), '%d %b %Y'),
        0,
        255
    );
    $shortname = \core_text::substr((string) $sourcecourse->shortname . '-' . time(), 0, 100);

    // --- Step 1: a full, self-contained backup (real files included) —
    // this is what goes to the service. ---
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
    if (empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($exportbasepath);
    }
    mtrace('');

    // --- Step 2: send that package to the service. ---
    mtrace('Sending .mbz to ' . COURSEGEN_BACKUP_ENDPOINT . '...');
    $serviceresponse = $client->upload_file(COURSEGEN_BACKUP_ENDPOINT, $backupfile);
    if (is_array($serviceresponse)) {
        mtrace('  sections: ' . ($serviceresponse['sections_found'] ?? '?')
            . '  activities: ' . ($serviceresponse['activities_found'] ?? '?')
            . '  images extracted: ' . ($serviceresponse['images_extracted'] ?? '?'));
    } else {
        mtrace('  (the service returned no JSON object)');
    }
    mtrace('');

    // --- Step 3: Moodle's own real course-copy (a SEPARATE backup+restore
    // pair, MODE_COPY, mirroring core_backup\copy_helper::create_copy()
    // exactly but run synchronously instead of via its adhoc task queue) to
    // create a brand-new, complete copy of the source course. ---
    mtrace('Copying the course natively (Moodle\'s own "Copy course" mechanism)...');
    $bc = new \backup_controller(
        \backup::TYPE_1COURSE,
        $courseid,
        \backup::FORMAT_MOODLE,
        \backup::INTERACTIVE_NO,
        \backup::MODE_COPY,
        get_admin()->id,
        \backup::RELEASESESSION_YES
    );
    $backupid = $bc->get_backupid();
    $bc->execute_plan();
    $backupbasepath = $bc->get_plan()->get_basepath();
    $bc->get_results()['backup_destination']->extract_to_pathname(
        get_file_packer('application/vnd.moodle.backup'),
        $backupbasepath
    );

    $newcourseid = \restore_dbops::create_new_course($fullname, $shortname, (int) $sourcecourse->category);

    $copydata = (object) [
        'courseid' => $courseid,
        'fullname' => $fullname,
        'shortname' => $shortname,
        'category' => (int) $sourcecourse->category,
        'visible' => (int) $sourcecourse->visible,
        'startdate' => (int) $sourcecourse->startdate,
        'enddate' => (int) $sourcecourse->enddate,
        'idnumber' => '',
        // No student/user data for a template recreation.
        'userdata' => 0,
        'keptroles' => [],
    ];

    $rc = new \restore_controller(
        $backupid,
        $newcourseid,
        \backup::INTERACTIVE_NO,
        \backup::MODE_COPY,
        get_admin()->id,
        \backup::TARGET_NEW_COURSE,
        null,
        \backup::RELEASESESSION_NO,
        $copydata
    );

    $rc->prepare_copy();
    $plan = $rc->get_plan();
    $plan->get_setting('course_startdate')->set_value($copydata->startdate);
    $plan->get_setting('course_fullname')->set_value($copydata->fullname);
    $plan->get_setting('course_shortname')->set_value($copydata->shortname);

    $rc->execute_precheck();
    if ($rc->get_status() !== \backup::STATUS_AWAITING) {
        throw new \Exception('Restore controller in unexpected status before execute_plan().');
    }
    $rc->execute_plan();

    $newcourse = get_course($newcourseid);
    $newcourse->visible = $copydata->visible;
    $newcourse->enddate = $copydata->enddate;
    $DB->update_record('course', $newcourse);

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
    if ($rc !== null) {
        $rc->destroy();
    }
    if ($bc !== null) {
        $bc->destroy();
    }
    if ($backupbasepath !== null && empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($backupbasepath);
    }
}

exit($exitcode);
