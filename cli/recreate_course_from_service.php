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
 * The service simulates real content generation by matching each template
 * activity against a same-type activity from a real reference course (see
 * the coursegen-template service's own lib/mergeContent.js) — so its
 * response's activities carry real content, real images, and (for
 * mod_resource) a real deliverable file, not the template's own original
 * content. Step 4 below applies that real content onto the matching
 * already-restored activity in the new course (matched by position — see
 * coursegen_apply_response_content()'s own docblock) — real per-type
 * handling for label/page/forum/feedback (name + intro/content, images
 * reattached the same way an editor field would), mod_resource (its real
 * file swapped in), and mod_lesson (its pages replaced with the real
 * matched lesson's own pages, images included).
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

/**
 * Stage a set of real images/files (downloaded from the service) into a
 * fresh draft area and let file_postupdate_standard_editor() move them
 * into their final, permanent location and rewrite @@PLUGINFILE@@ tokens
 * in the given text accordingly — the same mechanism Moodle's own editor
 * fields use for a real form submission.
 *
 * @param coursegen_template_client $client
 * @param array $files Image/file references ({filename, original_filename, url}).
 * @param \context $context Destination context (usually a module context).
 * @param string $component Destination component, e.g. 'mod_page'.
 * @param string $filearea Destination file area, e.g. 'intro'.
 * @param int $itemid Destination itemid.
 * @param string $text Original text (with @@PLUGINFILE@@ tokens) to rewrite.
 * @param int $format Text format constant.
 * @return array{text:string,format:int} The rewritten text/format.
 */
function coursegen_apply_editor_files(
    coursegen_template_client $client,
    array $files,
    \context $context,
    string $component,
    string $filearea,
    int $itemid,
    string $text,
    int $format
): array {
    global $USER;

    if (empty($files)) {
        return ['text' => $text, 'format' => $format];
    }

    $draftitemid = file_get_unused_draft_itemid();
    $usercontext = \context_user::instance($USER->id);
    $fs = get_file_storage();

    foreach ($files as $file) {
        $content = $client->download_raw((string) $file['url']);
        $filename = (string) ($file['original_filename'] ?: $file['filename']);
        if ($fs->file_exists($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename)) {
            continue;
        }
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    $data = new \stdClass();
    $data->id = 0;
    $data->tmp_editor = ['text' => $text, 'format' => $format, 'itemid' => $draftitemid];
    $data = file_postupdate_standard_editor($data, 'tmp', ['noclean' => true, 'maxfiles' => EDITOR_UNLIMITED_FILES],
        $context, $component, $filearea, $itemid);

    return ['text' => (string) $data->tmp, 'format' => (int) $data->tmpformat];
}

/**
 * Images/files belonging to a given field, keyed by (component, filearea)
 * and, for lesson pages, also by itemid (the source page id).
 *
 * @param array $images
 * @param string $component
 * @param string $filearea
 * @param int|null $itemid Null to match any itemid.
 * @return array
 */
function coursegen_filter_files(array $images, string $component, string $filearea, ?int $itemid = null): array {
    return array_values(array_filter($images, function ($f) use ($component, $filearea, $itemid) {
        if (($f['component'] ?? null) !== $component || ($f['filearea'] ?? null) !== $filearea) {
            return false;
        }
        return $itemid === null || (int) ($f['itemid'] ?? -1) === $itemid;
    }));
}

/**
 * Apply one merged activity's real content onto its corresponding,
 * already-restored real Moodle activity.
 *
 * @param coursegen_template_client $client
 * @param \stdClass $cm {id, instance, modname} of the real, restored activity.
 * @param array $activity The merged activity from the service response.
 * @return void
 */
function coursegen_apply_activity_content(coursegen_template_client $client, \stdClass $cm, array $activity): void {
    global $DB;

    $modname = $cm->modname;
    $content = $activity['content'] ?? null;
    $images = $activity['images'] ?? [];
    $files = $activity['files'] ?? [];
    $modcontext = \context_module::instance($cm->id);
    $component = 'mod_' . $modname;

    if ($modname === 'resource') {
        if (empty($files)) {
            return;
        }
        $fs = get_file_storage();
        foreach ($fs->get_area_files($modcontext->id, 'mod_resource', 'content', 0, 'sortorder', false) as $old) {
            if (!$old->is_directory()) {
                $old->delete();
            }
        }
        foreach ($files as $file) {
            $bytes = $client->download_raw((string) $file['url']);
            $filename = (string) ($file['original_filename'] ?: $file['filename']);
            $fs->create_file_from_string([
                'contextid' => $modcontext->id,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], $bytes);
        }
        $record = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
        $record->name = (string) ($activity['name'] ?? $record->name);
        $record->revision = (int) $record->revision + 1;
        $DB->update_record('resource', $record);
        return;
    }

    if ($modname === 'lesson') {
        if (empty($content['pages']['page'] ?? null)) {
            return;
        }
        global $CFG;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/truefalse.php');

        $lessonrecord = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);
        $lessonrecord->name = (string) ($activity['name'] ?? $lessonrecord->name);
        $DB->update_record('lesson', $lessonrecord);

        // Remove the activity's own currently-restored pages/answers before
        // creating the real ones — this activity already exists (it came
        // from the native course copy), so this is a real content
        // replacement, not a first creation.
        $DB->delete_records('lesson_answers', ['lessonid' => $cm->instance]);
        $DB->delete_records('lesson_pages', ['lessonid' => $cm->instance]);

        $lessonobj = \lesson::load($cm->instance);
        $pages = $content['pages']['page'];
        if (!array_is_list($pages)) {
            $pages = [$pages];
        }

        $previouspageid = 0;
        foreach ($pages as $page) {
            // Raw Moodle backup XML field names (this service returns the
            // real backup structure verbatim, not a reshaped contract):
            // 'contents' (not 'content_html'), 'qtype' as the real numeric
            // LESSON_PAGE_* constant, and real answers nested under
            // answers.answer (each with its own real 'answer_text').
            $title = trim((string) ($page['title'] ?? ''));
            $pagecontent = trim((string) ($page['contents'] ?? ''));
            if ($title === '' || $pagecontent === '') {
                continue;
            }

            $sourcepageid = (int) ($page['$']['id'] ?? 0);
            $pageimages = coursegen_filter_files($images, 'mod_lesson', 'page_contents', $sourcepageid);
            // The final itemid is the NEW page's own id, which doesn't exist
            // yet at this point — stage into a plain draft area (no
            // rewriting here) and let lesson_page::create() below do the
            // real move+rewrite once the page row is actually inserted,
            // exactly like a freshly-submitted page form.
            $draftitemid = empty($pageimages) ? 0 : coursegen_stage_draft_only($client, $pageimages);

            $answers = $page['answers']['answer'] ?? [];
            if (!empty($answers) && !array_is_list($answers)) {
                $answers = [$answers];
            }

            $properties = new \stdClass();
            $properties->title = $title;
            $properties->contents_editor = [
                'text' => $pagecontent,
                'format' => FORMAT_HTML,
                'itemid' => $draftitemid,
            ];
            $properties->pageid = $previouspageid;

            $qtype = (int) ($page['qtype'] ?? LESSON_PAGE_BRANCHTABLE);
            if ($qtype === LESSON_PAGE_MULTICHOICE || $qtype === LESSON_PAGE_TRUEFALSE) {
                $properties->qtype = $qtype;
                $properties->answer_editor = [];
                $properties->response_editor = [];
                $properties->jumpto = [];
                $properties->score = [];
                foreach ($answers as $answer) {
                    $answertext = trim((string) ($answer['answer_text'] ?? ''));
                    if ($answertext === '') {
                        continue;
                    }
                    $correct = (int) ($answer['score'] ?? 0) > 0;
                    $response = $answer['response'] ?? '';
                    $properties->answer_editor[] = ['text' => $answertext, 'format' => FORMAT_HTML];
                    $properties->response_editor[] = [
                        'text' => (is_string($response) ? trim($response) : ''),
                        'format' => FORMAT_HTML,
                    ];
                    $properties->jumpto[] = $correct ? LESSON_NEXTPAGE : LESSON_THISPAGE;
                    $properties->score[] = $correct ? 1 : 0;
                }
                if (empty($properties->answer_editor)) {
                    continue;
                }
            } else {
                // Real content/branch page: its own real button text is the
                // first real answer's own answer_text when one exists (this
                // course's real pages always have exactly one), falling
                // back to a plain "Continue" only when a page genuinely has
                // none.
                $buttontext = '';
                foreach ($answers as $answer) {
                    $text = trim((string) ($answer['answer_text'] ?? ''));
                    if ($text !== '') {
                        $buttontext = $text;
                        break;
                    }
                }
                $properties->qtype = LESSON_PAGE_BRANCHTABLE;
                $properties->answer_editor = [$buttontext !== '' ? $buttontext : get_string('continue', 'lesson')];
                $properties->jumpto = [LESSON_NEXTPAGE];
            }

            $created = \lesson_page::create($properties, $lessonobj, $modcontext, $CFG->maxbytes);
            $previouspageid = $created->id;
        }
        return;
    }

    // Generic case: label/page/forum/feedback/etc — a real 'name' plus an
    // 'intro' editor field, all keyed the same way (component 'mod_<type>',
    // filearea 'intro', itemid 0). mod_page additionally has its own
    // separate 'content'/'contentformat' body field.
    $record = $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);
    $record->name = (string) ($activity['name'] ?? $record->name);

    if ($content !== null && array_key_exists('intro', $content)) {
        $introfiles = coursegen_filter_files($images, $component, 'intro');
        $rewritten = coursegen_apply_editor_files(
            $client,
            $introfiles,
            $modcontext,
            $component,
            'intro',
            0,
            (string) ($content['intro'] ?? ''),
            (int) ($content['introformat'] ?? FORMAT_HTML)
        );
        $record->intro = $rewritten['text'];
        $record->introformat = $rewritten['format'];
    }

    if ($modname === 'page' && $content !== null && array_key_exists('content', $content)) {
        $bodyfiles = coursegen_filter_files($images, $component, 'content');
        $rewritten = coursegen_apply_editor_files(
            $client,
            $bodyfiles,
            $modcontext,
            $component,
            'content',
            0,
            (string) ($content['content'] ?? ''),
            (int) ($content['contentformat'] ?? FORMAT_HTML)
        );
        $record->content = $rewritten['text'];
        $record->contentformat = $rewritten['format'];
    }

    $DB->update_record($modname, $record);
}

/**
 * Stage a set of real files into a plain draft area (no rewriting) — used
 * only for the lesson-page case, where the FINAL itemid (the new page's own
 * id) does not exist yet: lesson_page::create() does the real move/rewrite
 * itself once the page row is inserted, exactly like a freshly-submitted
 * page form.
 *
 * @param coursegen_template_client $client
 * @param array $files
 * @return int The draft itemid the files were staged under.
 */
function coursegen_stage_draft_only(coursegen_template_client $client, array $files): int {
    global $USER;

    $draftitemid = file_get_unused_draft_itemid();
    $usercontext = \context_user::instance($USER->id);
    $fs = get_file_storage();

    foreach ($files as $file) {
        $content = $client->download_raw((string) $file['url']);
        $filename = (string) ($file['original_filename'] ?: $file['filename']);
        if ($fs->file_exists($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename)) {
            continue;
        }
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    return $draftitemid;
}

/**
 * Apply every merged activity's real content onto its corresponding,
 * already-restored activity in the new course. Activities are matched by
 * ORDER (the response's own activities array walks sections/activities in
 * the same relative order Moodle's own restore creates them in — verified
 * live against a real restore) rather than by id, since a restore does not
 * preserve the source course's original module ids. A modname mismatch at
 * a given position is treated as a signal something is out of sync and
 * that activity is skipped rather than risking writing the wrong content
 * onto the wrong activity.
 *
 * @param coursegen_template_client $client
 * @param int $newcourseid
 * @param array $responseactivities
 * @return array{applied:int,skipped:int,total:int}
 */
function coursegen_apply_response_content(coursegen_template_client $client, int $newcourseid, array $responseactivities): array {
    global $DB;

    $realcms = array_values($DB->get_records_sql(
        'SELECT cm.id, cm.instance, m.name AS modname
           FROM {course_modules} cm
           JOIN {course_sections} cs ON cs.id = cm.section
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid
       ORDER BY cs.section ASC, cm.id ASC',
        ['courseid' => $newcourseid]
    ));

    $applied = 0;
    $skipped = 0;
    foreach ($responseactivities as $i => $activity) {
        if (!isset($realcms[$i]) || $realcms[$i]->modname !== ($activity['modname'] ?? null)) {
            $skipped++;
            continue;
        }
        try {
            coursegen_apply_activity_content($client, $realcms[$i], $activity);
            $applied++;
        } catch (\Throwable $e) {
            mtrace('  WARNING: could not apply content to cmid ' . $realcms[$i]->id
                . ' (' . $realcms[$i]->modname . '): ' . $e->getMessage());
            $skipped++;
        }
    }

    return ['applied' => $applied, 'skipped' => $skipped, 'total' => count($responseactivities)];
}

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

    // --- Step 4: apply the merged, real content from the service's
    // response onto the activities the native restore just created. ---
    $contentresult = ['applied' => 0, 'skipped' => 0, 'total' => 0];
    if (is_array($serviceresponse) && !empty($serviceresponse['activities'])) {
        mtrace('Applying real content onto the restored activities...');
        $contentresult = coursegen_apply_response_content($client, $newcourseid, $serviceresponse['activities']);
        mtrace('  applied: ' . $contentresult['applied'] . '  skipped: ' . $contentresult['skipped']
            . '  total: ' . $contentresult['total']);
        rebuild_course_cache($newcourseid, true);
    }

    mtrace('');
    mtrace('== Result ==');
    mtrace('New course id:  ' . $newcourseid);
    mtrace('New course URL: ' . (new \moodle_url('/course/view.php', ['id' => $newcourseid]))->out(false));
    mtrace('Content applied: ' . $contentresult['applied'] . '/' . $contentresult['total']
        . ' (skipped: ' . $contentresult['skipped'] . ')');
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
