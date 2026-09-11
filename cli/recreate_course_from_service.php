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
 * CLI end-to-end round trip: export a real course, send it to the
 * coursegen-template service (a pure pass-through today), and produce a
 * brand-new course.
 *
 * Rebuilding a course field-by-field from the exported JSON (course
 * settings, format options, completion, blocks, ...) kept discovering a new
 * missing setting every time, because it was re-implementing what Moodle's
 * OWN course-copy feature already does correctly and completely. This
 * script no longer does that: the new course is created with Moodle's real
 * course-copy mechanism (the same backup+restore machinery behind "Copy
 * course" in the UI, core_backup\copy_helper — see
 * lib/classes/task/asynchronous_copy_task.php for the exact sequence this
 * mirrors, run synchronously here instead of via the task queue so this
 * script doesn't need to wait on cron), which guarantees an exact, complete
 * copy — blocks, format options, completion settings, everything — without
 * hand-reconstructing any of it.
 *
 * The export -> service -> ingest round trip still runs (it's what proves
 * the coursegen-template integration itself works: real images uploaded,
 * never base64, a valid response comes back), but its response is no
 * longer what BUILDS the new course. Once the service does real content
 * adaptation (today it only echoes), applying its per-activity output onto
 * the already-complete native copy is the next piece to add here — this
 * script's structure leaves the natural place for that (right after the
 * native copy finishes) but does not implement it yet, since there is
 * nothing genuinely different to apply while the service is a pass-through.
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
require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');

use local_coursegen\local\httpclient\coursegen_template_client;
use local_coursegen\local\service\course_exporter;

const COURSEGEN_IMAGE_ENDPOINT = '/api/images';
const COURSEGEN_INGEST_ENDPOINT = '/api/course/ingest';

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

$usage = "Copy a real course natively, and separately round-trip its export through the " .
    "coursegen-template service to validate that integration.\n\n" .
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

/**
 * Run Moodle's own real course-copy mechanism synchronously, instead of
 * queuing it as an adhoc task and waiting on cron.
 *
 * Mirrors \core\task\asynchronous_copy_task::execute() exactly (the same
 * class the "Copy course" UI queues) — that method is written to run from
 * a task, but nothing about the backup/restore calls it makes actually
 * requires the task queue; the result is identical either way. Kept as a
 * genuine copy of that logic (not a call into it) because the task class
 * reads its input from the adhoc task's own custom-data record, not from
 * plain parameters — calling it directly would mean queuing a real task
 * anyway just to immediately execute it out of band.
 *
 * @param \stdClass $sourcecourse The real course to copy.
 * @return int The new course's id.
 * @throws \Exception If the backup or restore step fails.
 */
function coursegen_native_course_copy(\stdClass $sourcecourse): int {
    global $USER, $DB;

    $copydata = (object) [
        'courseid' => $sourcecourse->id,
        'fullname' => \core_text::substr(
            (string) $sourcecourse->fullname . ' (recreated) - ' . userdate(time(), '%d %b %Y'),
            0,
            255
        ),
        'shortname' => \core_text::substr(
            (string) $sourcecourse->shortname . '-' . time(),
            0,
            100
        ),
        'category' => (int) $sourcecourse->category,
        'visible' => (int) $sourcecourse->visible,
        'startdate' => (int) $sourcecourse->startdate,
        'enddate' => (int) $sourcecourse->enddate,
        'idnumber' => '',
        // No student/user data for a template recreation — this is
        // testing content structure, not cloning a live cohort.
        'userdata' => 0,
        'keptroles' => [],
    ];

    $copyids = \copy_helper::create_copy($copydata);

    $backuprecord = $DB->get_record(
        'backup_controllers',
        ['backupid' => $copyids['backupid']],
        'id, itemid',
        MUST_EXIST
    );
    $restorerecord = $DB->get_record(
        'backup_controllers',
        ['backupid' => $copyids['restoreid']],
        'id, itemid',
        MUST_EXIST
    );

    mtrace('  Backing up source course (id=' . $backuprecord->itemid . ')...');
    $bc = \backup_controller::load_controller($copyids['backupid']);
    $rc = \restore_controller::load_controller($copyids['restoreid']);
    $copyinfo = $rc->get_copy();
    $backupplan = $bc->get_plan();

    $keepuserdata = (bool) $copyinfo->userdata;
    $keptroles = $copyinfo->keptroles;
    $bc->set_kept_roles($keptroles);
    if (empty($keptroles) || !$keepuserdata) {
        $backupplan->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $backupplan->get_setting('users')->set_value('0');
    } else {
        $backupplan->get_setting('users')->set_value('1');
    }

    if ($bc->get_status() !== \backup::STATUS_AWAITING) {
        throw new \Exception('Backup controller in unexpected status before execute_plan().');
    }
    $bc->execute_plan();

    $results = $bc->get_results();
    $backupbasepath = $backupplan->get_basepath();
    $file = $results['backup_destination'];
    $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);

    mtrace('  Restoring into new course (id=' . $restorerecord->itemid . ')...');
    $rc->prepare_copy();
    $plan = $rc->get_plan();
    $plan->get_setting('course_startdate')->set_value($copyinfo->startdate);
    $plan->get_setting('course_fullname')->set_value($copyinfo->fullname);
    $plan->get_setting('course_shortname')->set_value($copyinfo->shortname);

    $rc->execute_precheck();
    if ($rc->get_status() !== \backup::STATUS_AWAITING) {
        throw new \Exception('Restore controller in unexpected status before execute_plan().');
    }
    $rc->execute_plan();

    // No kept roles/userdata for this recreation, so no enrolments to copy
    // (mirrors asynchronous_copy_task's own "only if userdata kept" guard).

    $course = $DB->get_record('course', ['id' => $restorerecord->itemid], '*', MUST_EXIST);
    $course->visible = $copyinfo->visible;
    $course->idnumber = $copyinfo->idnumber;
    $course->enddate = $copyinfo->enddate;
    $DB->update_record('course', $course);

    $bc->destroy();
    $rc->destroy();
    $file->delete();
    if (empty($CFG->keeptempdirectoriesonbackup)) {
        fulldelete($backupbasepath);
    }

    rebuild_course_cache($restorerecord->itemid, true);
    \cache_helper::purge_by_event('changesincourse');

    return (int) $restorerecord->itemid;
}

try {
    $client = new coursegen_template_client($serviceurl);
    $baseurl = $client->get_base_url();

    mtrace('== local_coursegen: recreate course from coursegen-template service ==');
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

    $imageuploader = function (\stored_file $file) use ($client): array {
        $result = $client->upload_file(COURSEGEN_IMAGE_ENDPOINT, $file);
        if (!$result) {
            throw new \moodle_exception(
                'error_template_service_response',
                'local_coursegen',
                '',
                'empty image upload response for ' . $file->get_filename()
            );
        }
        return $result;
    };

    mtrace('Exporting source course and uploading images (validates the service integration)...');
    $exporter = new course_exporter($imageuploader);
    $payload = $exporter->export_course($courseid);
    mtrace('  sections: ' . $payload['meta']['sections_count']
        . '  activities: ' . $payload['meta']['activities_count']
        . '  images uploaded: ' . $payload['meta']['images_uploaded']);

    mtrace('Sending to ' . COURSEGEN_INGEST_ENDPOINT . '...');
    $response = $client->post_json(COURSEGEN_INGEST_ENDPOINT, $payload);
    if (!is_array($response) || !isset($response['sections']) || !isset($response['course'])) {
        throw new \moodle_exception(
            'error_template_service_response',
            'local_coursegen',
            '',
            'ingest response missing expected course/sections shape'
        );
    }
    mtrace('  service responded with a course payload.');
    mtrace('');

    mtrace('Copying the course natively (Moodle\'s own backup+restore, same as "Copy course")...');
    $sourcecourse = get_course($courseid);
    $newcourseid = coursegen_native_course_copy($sourcecourse);
    $newcourse = get_course($newcourseid);

    mtrace('');
    mtrace('== Result ==');
    mtrace('New course id:  ' . $newcourse->id);
    mtrace('New course URL: ' . (new \moodle_url('/course/view.php', ['id' => $newcourse->id]))->out(false));
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
}

exit($exitcode);
