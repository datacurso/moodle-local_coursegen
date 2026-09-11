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
 * coursegen-template service, and use whatever comes back to build a brand
 * new, complete real course — real images included, never broken links to
 * the service.
 *
 * Today the service is a pure pass-through (it echoes the same course JSON
 * back unchanged), so this round trip is expected to produce a near-exact
 * mirror of the source course. Once the service starts genuinely adapting
 * content, this same script keeps working unchanged — it only ever looks at
 * the shape of the response, never assumes it matches the request.
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

use local_coursegen\local\httpclient\coursegen_template_client;
use local_coursegen\local\service\course_exporter;
use local_coursegen\local\service\create_mod_service;

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

$usage = "Export a real course, round-trip it through the coursegen-template service, " .
    "and build a brand-new real course from whatever comes back.\n\n" .
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
 * Extract the real filename from an @@PLUGINFILE@@ token, e.g.
 * '@@PLUGINFILE@@/sub/dir/banner.png' -> 'banner.png'.
 *
 * @param string $token The token recorded alongside the image reference.
 * @return string
 */
function coursegen_filename_from_token(string $token): string {
    $parts = explode('/', $token);
    return end($parts) ?: 'image.png';
}

/**
 * Download one exported image back from the service and stage it in a
 * draft file area under the given itemid, ready for an editor field
 * (or lesson_page::create(), which uses the exact same mechanism) to pick
 * up and move into its final, permanent file area.
 *
 * @param coursegen_template_client $client
 * @param array $imageentry {field, token, image:{url,...}} as exported.
 * @param int $itemid Draft itemid to stage the file under.
 * @return void
 * @throws \moodle_exception If the download fails.
 */
function coursegen_stage_draft_image(coursegen_template_client $client, array $imageentry, int $itemid): void {
    global $USER;

    $url = (string) ($imageentry['image']['url'] ?? '');
    if ($url === '') {
        return;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = $client->get_base_url() . $url;
    }

    $content = $client->download_raw($url);
    $filename = coursegen_filename_from_token((string) ($imageentry['token'] ?? ''));

    $fs = get_file_storage();
    $usercontext = \context_user::instance($USER->id);

    // A page can reference the same filename more than once across
    // different tokens/paths; only stage each filename once per itemid.
    if ($fs->file_exists($usercontext->id, 'user', 'draft', $itemid, '/', $filename)) {
        return;
    }

    $fs->create_file_from_string([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $itemid,
        'filepath' => '/',
        'filename' => $filename,
    ], $content);
}

/**
 * Map an exported image's 'field' label to the parameter key that carries
 * that editor's text, for the generic (non-lesson) case.
 *
 * @param string $field The 'field' label recorded by course_exporter.
 * @return string|null The parameter key, or null when this field has no
 *     known editor-object destination (e.g. a resource/folder's own
 *     downloadable file, which is not an embedded content image at all).
 */
function coursegen_field_to_param_key(string $field): ?string {
    return match ($field) {
        'intro' => 'introeditor',
        'content' => 'page',
        default => null,
    };
}

/**
 * Reattach every real image this activity references, staging each into a
 * fresh draft area and pointing the matching parameter field's itemid at
 * it — the exact same mechanism Moodle's own editor-field processing
 * (text_editor_parameter_cleaner, and lesson_page::create()'s
 * file_postupdate_standard_editor()) already uses for a freshly-submitted
 * form, just fed from real downloaded files instead of a live upload.
 *
 * @param coursegen_template_client $client
 * @param array $parameters The activity's own parameters, modified in place.
 * @param array $images The activity's exported 'images' list.
 * @param string $modname
 * @return int Number of images actually staged.
 */
function coursegen_reattach_images(
    coursegen_template_client $client,
    array &$parameters,
    array $images,
    string $modname
): int {
    if (empty($images)) {
        return 0;
    }

    $staged = 0;

    if ($modname === 'lesson') {
        $bypage = [];
        foreach ($images as $image) {
            if (preg_match('/^page_contents:(\d+)$/', (string) ($image['field'] ?? ''), $m)) {
                $bypage[(int) $m[1]][] = $image;
            }
        }
        // Deliberately NOT `$parameters['mod_settings']['pages'] ?? []` here:
        // `??` produces a temporary expression, and a by-reference foreach over
        // an expression (rather than a real array-access lvalue) silently
        // writes into a throwaway copy — the caller's $parameters never
        // actually gets the itemid. isset() first, then foreach the real
        // array access directly.
        if (isset($parameters['mod_settings']['pages']) && is_array($parameters['mod_settings']['pages'])) {
            foreach ($parameters['mod_settings']['pages'] as &$page) {
                $oldpageid = (int) ($page['id'] ?? 0);
                if (empty($bypage[$oldpageid])) {
                    continue;
                }
                $itemid = file_get_unused_draft_itemid();
                foreach ($bypage[$oldpageid] as $image) {
                    coursegen_stage_draft_image($client, $image, $itemid);
                    $staged++;
                }
                $page['_draftitemid'] = $itemid;
            }
            unset($page);
        }
        return $staged;
    }

    $byfield = [];
    foreach ($images as $image) {
        $byfield[(string) ($image['field'] ?? '')][] = $image;
    }

    foreach ($byfield as $field => $imageentries) {
        $key = coursegen_field_to_param_key($field);
        if ($key === null || !isset($parameters[$key]) || !is_array($parameters[$key])
            || !array_key_exists('text', $parameters[$key])) {
            continue;
        }

        $itemid = file_get_unused_draft_itemid();
        foreach ($imageentries as $image) {
            coursegen_stage_draft_image($client, $image, $itemid);
            $staged++;
        }
        $parameters[$key]['itemid'] = $itemid;
    }

    return $staged;
}

/**
 * Recreate one grid-format section's tile image from its exported
 * reference — a direct format_grid_image row + real file, since this is a
 * course-format asset, not an editor field.
 *
 * @param coursegen_template_client $client
 * @param \stdClass $course The new course.
 * @param int $sectionid The new section's real id.
 * @param array $tileimage {displayedimagestate, image:{...}} as exported.
 * @return bool True when the tile image was recreated.
 */
function coursegen_recreate_grid_tile_image(
    coursegen_template_client $client,
    \stdClass $course,
    int $sectionid,
    array $tileimage
): bool {
    global $DB;

    $url = (string) ($tileimage['image']['url'] ?? '');
    if ($url === '') {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = $client->get_base_url() . $url;
    }

    $filename = (string) ($tileimage['image']['filename'] ?? 'section.png');
    $content = $client->download_raw($url);

    $coursecontext = \context_course::instance($course->id);
    $fs = get_file_storage();

    if (!$fs->file_exists($coursecontext->id, 'format_grid', 'sectionimage', $sectionid, '/', $filename)) {
        $fs->create_file_from_string([
            'contextid' => $coursecontext->id,
            'component' => 'format_grid',
            'filearea' => 'sectionimage',
            'itemid' => $sectionid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    $DB->delete_records('format_grid_image', ['courseid' => $course->id, 'sectionid' => $sectionid]);
    $DB->insert_record('format_grid_image', (object) [
        'courseid' => $course->id,
        'sectionid' => $sectionid,
        'image' => $filename,
        'contenthash' => sha1($content),
        'displayedimagestate' => (int) ($tileimage['displayedimagestate'] ?? 0),
    ]);

    return true;
}

/**
 * Stage a mod_resource's own real file into a fresh draft area, and point
 * mod_settings.draft_itemid at it — resource_parameters.php's own
 * backward-compatible guard picks this up instead of trying to download the
 * file from the real production Datacurso API (which this test course never
 * went through).
 *
 * @param coursegen_template_client $client
 * @param array $parameters The activity's own parameters, modified in place.
 * @return bool True when a package file was staged.
 */
function coursegen_stage_resource_package(coursegen_template_client $client, array &$parameters): bool {
    $package = $parameters['mod_settings']['package'] ?? null;
    if (!is_array($package)) {
        return false;
    }

    $url = (string) ($package['url'] ?? '');
    if ($url === '') {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = $client->get_base_url() . $url;
    }

    $filename = (string) ($package['filename'] ?? $package['original_filename'] ?? 'file');
    $content = $client->download_raw($url);

    global $USER;
    $itemid = file_get_unused_draft_itemid();
    $usercontext = \context_user::instance($USER->id);
    get_file_storage()->create_file_from_string([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $itemid,
        'filepath' => '/',
        'filename' => $filename,
    ], $content);

    $parameters['mod_settings']['draft_itemid'] = $itemid;
    unset($parameters['mod_settings']['package']);

    return true;
}

/**
 * Reconstruct a section's summary text with its real images reattached.
 *
 * course_update_section() (via sectionactions::update()) writes 'summary'
 * as a plain DB column with no file/editor processing at all — unlike an
 * activity's introeditor, nothing moves draft files into place or rewrites
 * @@PLUGINFILE@@ tokens on its own. This does that step manually, the same
 * way core's own course/editsection_form.php does for a real edit: stage
 * the real images in a draft area, then let file_postupdate_standard_editor()
 * move them into course/section/<sectionid> and rewrite the tokens.
 *
 * @param coursegen_template_client $client
 * @param \context_course $coursecontext
 * @param int $sectionid The new section's real id.
 * @param string $summary Exported summary text (with @@PLUGINFILE@@ tokens).
 * @param int $summaryformat
 * @param array $summaryimages Exported summary_images list.
 * @return array{summary:string,summaryformat:int} Values ready for course_update_section().
 */
function coursegen_reattach_section_summary_images(
    coursegen_template_client $client,
    \context_course $coursecontext,
    int $sectionid,
    string $summary,
    int $summaryformat,
    array $summaryimages
): array {
    if (empty($summaryimages)) {
        return ['summary' => $summary, 'summaryformat' => $summaryformat];
    }

    $itemid = file_get_unused_draft_itemid();
    foreach ($summaryimages as $image) {
        coursegen_stage_draft_image($client, $image, $itemid);
    }

    $data = new \stdClass();
    $data->id = $sectionid;
    $data->summary_editor = ['text' => $summary, 'format' => $summaryformat, 'itemid' => $itemid];
    $data = file_postupdate_standard_editor(
        $data,
        'summary',
        ['noclean' => true, 'maxfiles' => EDITOR_UNLIMITED_FILES, 'subdirs' => false],
        $coursecontext,
        'course',
        'section',
        $sectionid
    );

    return ['summary' => (string) $data->summary, 'summaryformat' => (int) $data->summaryformat];
}

/**
 * Build a unique course shortname from a base string.
 *
 * @param string $base
 * @return string
 */
function coursegen_unique_shortname(string $base): string {
    global $DB;

    $base = trim($base) !== '' ? \core_text::substr(trim($base), 0, 80) : 'course';
    $candidate = (string) \core_text::substr($base . '-' . time(), 0, 100);
    $suffix = 1;
    while ($DB->record_exists('course', ['shortname' => $candidate])) {
        $candidate = (string) \core_text::substr($base . '-' . time() . '-' . $suffix, 0, 100);
        $suffix++;
    }
    return $candidate;
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

    mtrace('Exporting source course and uploading images...');
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

    mtrace('Creating the new course...');
    $sourcecourse = get_course($courseid);
    $responsecourse = $response['course'];

    $coursedata = new \stdClass();
    $coursedata->fullname = \core_text::substr(
        (string) ($responsecourse['fullname'] ?? 'Recreated course') . ' (recreated) - ' . userdate(time(), '%d %b %Y'),
        0,
        255
    );
    $coursedata->shortname = coursegen_unique_shortname((string) ($responsecourse['shortname'] ?? 'course'));
    $coursedata->category = (int) $sourcecourse->category;
    $coursedata->visible = 0;
    if (!empty($responsecourse['format'])) {
        $coursedata->format = (string) $responsecourse['format'];
    }
    $newcourse = create_course($coursedata);

    // create_course() may auto-create a default "Announcements" forum in
    // section 0; the recreated course should only ever contain what the
    // response actually describes.
    foreach ($DB->get_records('course_modules', ['course' => $newcourse->id], '', 'id') as $record) {
        course_delete_module((int) $record->id);
    }

    $responsesections = $response['sections'] ?? [];
    $maxsectionnum = 0;
    foreach ($responsesections as $section) {
        $maxsectionnum = max($maxsectionnum, (int) ($section['sectionnum'] ?? 0));
    }
    course_create_sections_if_missing($newcourse, range(0, $maxsectionnum));
    // Sections were just created/renumbered — refresh the course object so
    // later calls (add_moduleinfo via create_from_ai_result) see the real
    // section count.
    $newcourse = get_course($newcourse->id);

    $sectionscreated = 0;
    $activitiescreated = 0;
    $imagesreattached = 0;
    $gridtilesreattached = 0;

    foreach ($responsesections as $section) {
        $sectionnum = (int) ($section['sectionnum'] ?? 0);
        $sectionrecord = $DB->get_record('course_sections', ['course' => $newcourse->id, 'section' => $sectionnum]);
        if (!$sectionrecord) {
            mtrace('  WARNING: could not find/create section ' . $sectionnum . ', skipping its activities.');
            continue;
        }

        $summaryfields = coursegen_reattach_section_summary_images(
            $client,
            \context_course::instance($newcourse->id),
            (int) $sectionrecord->id,
            (string) ($section['summary'] ?? ''),
            (int) ($section['summaryformat'] ?? FORMAT_HTML),
            $section['summary_images'] ?? []
        );
        $imagesreattached += count($section['summary_images'] ?? []);

        course_update_section($newcourse, $sectionrecord, array_merge(
            ['name' => $section['name'] ?? null],
            $summaryfields
        ));
        $sectionscreated++;

        $gridtile = $section['format']['grid_tile_image'] ?? null;
        if (is_array($gridtile)) {
            try {
                if (coursegen_recreate_grid_tile_image($client, $newcourse, (int) $sectionrecord->id, $gridtile)) {
                    $gridtilesreattached++;
                }
            } catch (\Throwable $e) {
                mtrace('  WARNING: could not recreate section ' . $sectionnum . ' tile image: ' . $e->getMessage());
            }
        }

        foreach ($section['activities'] ?? [] as $activity) {
            $modname = (string) ($activity['modname'] ?? $activity['resource_type'] ?? '');
            $parameters = $activity['parameters'] ?? [];

            try {
                $imagesreattached += coursegen_reattach_images($client, $parameters, $activity['images'] ?? [], $modname);
                if ($modname === 'resource') {
                    coursegen_stage_resource_package($client, $parameters);
                }

                $resultinfo = [
                    'resource_type' => (string) ($activity['resource_type'] ?? $modname),
                    'parameters' => $parameters,
                ];
                create_mod_service::create_from_ai_result($resultinfo, $newcourse, $sectionnum);
                $activitiescreated++;
            } catch (\Throwable $e) {
                mtrace('  WARNING: could not create activity "' . ($activity['name'] ?? $modname)
                    . '" (' . $modname . ') in section ' . $sectionnum . ': ' . $e->getMessage());
            }
        }
    }

    // format_grid renders a section's tile from a SEPARATE, resized
    // 'displayedsectionimage' file it derives from 'sectionimage' itself —
    // never the original directly. coursegen_recreate_grid_tile_image()
    // above only wrote the original; without this, every tile renders
    // blank despite the real file existing. This is format_grid's own real
    // resize step (reused as-is, not reimplemented) — it reads every
    // format_grid_image row for the course and (re)builds the displayed
    // variant from whatever 'sectionimage' file is really there.
    if ($gridtilesreattached > 0 && class_exists('\\format_grid\\toolbox')) {
        \format_grid\toolbox::update_displayed_images($newcourse->id);
    }

    $DB->set_field('course', 'visible', 1, ['id' => $newcourse->id]);

    mtrace('');
    mtrace('== Result ==');
    mtrace('New course id:       ' . $newcourse->id);
    mtrace('New course URL:      ' . (new \moodle_url('/course/view.php', ['id' => $newcourse->id]))->out(false));
    mtrace('Sections created:    ' . $sectionscreated);
    mtrace('Activities created:  ' . $activitiescreated);
    mtrace('Images reattached:   ' . $imagesreattached);
    mtrace('Grid tiles restored: ' . $gridtilesreattached);
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
