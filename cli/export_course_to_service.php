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
 * CLI: export one real, existing course to the coursegen-template service.
 *
 * Reads the course as it exists today (every section, every activity, hidden
 * ones included), uploads every image it references to the service one file at
 * a time as multipart/form-data, and POSTs the resulting JSON payload to the
 * service's ingest endpoint.
 *
 * Read-only with respect to Moodle: nothing in the course is modified. The only
 * site-config touch is a temporary addition to 'curlsecurityallowedport', always
 * restored before the script returns.
 *
 * Usage:
 *   php cli/export_course_to_service.php --courseid=397
 *   php cli/export_course_to_service.php --courseid=397 --service-url=http://localhost:3000
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\httpclient\coursegen_template_client;
use local_coursegen\local\service\course_exporter;

/** Endpoint that accepts one multipart image upload and returns its reference object. */
const COURSEGEN_EXPORT_IMAGE_ENDPOINT = '/api/images';

/** Endpoint that accepts the exported course payload. */
const COURSEGEN_EXPORT_INGEST_ENDPOINT = '/api/course/ingest';

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

$usage = "Export a real course to the coursegen-template service.\n\n" .
    "Options:\n" .
    " --courseid=ID       Id of the course to export (required).\n" .
    " --service-url=URL   Override the service base URL (default: the\n" .
    "                     'coursegen_template_service_url' admin setting,\n" .
    "                     then http://coursegen-template:3000).\n" .
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

// Run as an admin so nothing in the course is filtered out of the export by
// per-user visibility rules (get_fast_modinfo() is user-sensitive).
\core\session\manager::set_user(get_admin());

$exitcode = 0;
$portoverridden = false;
$originalallowedports = (string) $CFG->curlsecurityallowedport;

try {
    $client = new coursegen_template_client($serviceurl);
    $baseurl = $client->get_base_url();

    mtrace('== local_coursegen: export course to coursegen-template service ==');
    mtrace('Course id:   ' . $courseid);
    mtrace('Service URL: ' . $baseurl);
    mtrace('');

    // Moodle's curl security helper blocks any port not in the site's
    // 'curlsecurityallowedport' allowlist — the coursegen-template service's port is
    // very unlikely to already be on it. Temporarily add it for the duration of this
    // script only, and restore the original value in the finally below, so this CLI
    // run never leaves a lingering site config change behind.
    $serviceport = (string) (parse_url($baseurl, PHP_URL_PORT) ?: 80);
    $allowedportslist = array_filter(array_map('trim', explode("\n", $originalallowedports)), fn($e) => $e !== '');
    if (!in_array($serviceport, $allowedportslist, true)) {
        mtrace('Temporarily allowing outbound port ' . $serviceport . ' (curlsecurityallowedport) for this run...');
        set_config('curlsecurityallowedport', trim($originalallowedports . "\n" . $serviceport));
        $portoverridden = true;
    }

    // Every image found in the course is uploaded here, one at a time, as a real
    // multipart file — never inlined into the payload.
    $imageuploader = function (\stored_file $file) use ($client): array {
        $result = $client->upload_file(COURSEGEN_EXPORT_IMAGE_ENDPOINT, $file);
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

    mtrace('Exporting course and uploading images...');
    $exporter = new course_exporter($imageuploader);
    $payload = $exporter->export_course($courseid);

    $meta = $payload['meta'];
    mtrace('Sections:        ' . $meta['sections_count']);
    mtrace('Activities:      ' . $meta['activities_count']);
    mtrace('Images uploaded: ' . $meta['images_uploaded']);
    mtrace('');

    mtrace('Posting payload to ' . COURSEGEN_EXPORT_INGEST_ENDPOINT . '...');
    $response = $client->post_json(COURSEGEN_EXPORT_INGEST_ENDPOINT, $payload);

    mtrace('');
    mtrace('== Ingest response ==');
    if (!is_array($response)) {
        mtrace('(the service returned no JSON object)');
    } else {
        $reportfields = [
            'sections_received',
            'activities_received',
            'section_images_received',
            'activity_images_received',
            'stored_as',
        ];
        foreach ($reportfields as $field) {
            $value = $response[$field] ?? null;
            mtrace(str_pad($field . ':', 26) . ($value === null ? '(absent)' : (string) $value));
        }
    }

    mtrace('');
    mtrace('DONE: course ' . $courseid . ' exported successfully.');
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
