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

namespace local_coursegen\external;

use aiprovider_datacurso\local\plugin_settings;
use context_system;
use context_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\event\external_transfer_initiated;
use local_coursegen\local\models\course_session;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\creation_permissions;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * External function to upload syllabus for courseai session.
 *
 * Two notes on the file validation implemented here.
 *
 * Antivirus is already covered: a file only reaches this endpoint through a
 * draft area, and the upload repository scans every incoming file with
 * \core\antivirus\manager::scan_file() (repository/upload/lib.php and
 * repository/lib.php). Scanning again here would duplicate that work.
 *
 * file_extension_in_typegroup() is deliberately not used. It looks like a
 * content check but is not: with $checktype = true its fallback is
 * mimeinfo('type', $filename), which is derived from the file name just like
 * stored_file::get_mimetype(). Renaming an executable to .pdf defeats it.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courseai_syllabus_upload extends external_api {
    /**
     * @var int Largest syllabus accepted by the server.
     *
     * Mirrors the maxbytes advertised by courseai_filepicker_init: the
     * filepicker only constrains the browser, so the limit is re-applied here.
     */
    public const MAX_SYLLABUS_BYTES = 10 * 1024 * 1024;

    /** @var string[] Extensions accepted by the server, lower case, no dot. */
    public const ACCEPTED_EXTENSIONS = ['pdf'];

    /**
     * @var array[] Content types legitimately sniffed from the bytes of each accepted extension.
     *
     * The AI service renders the syllabus as a PDF, so the bytes must be one.
     */
    protected const CONTENT_SIGNATURES = [
        'pdf' => ['application/pdf'],
    ];

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Course session ID'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft file area ID'),
        ]);
    }

    /**
     * Upload syllabus file to Datacurso API.
     *
     * @param int $sessionid Course session ID
     * @param int $draftitemid Draft file area ID
     * @return array Result with success status and filename
     * @throws \moodle_exception plugindisabled or plugindisabled_admin when the plugin is disabled.
     */
    public static function execute(int $sessionid, int $draftitemid): array {
        global $DB, $USER;

        plugin_settings::require_enabled('local_coursegen');

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'draftitemid' => $draftitemid,
        ]);

        // Check permissions.
        $context = context_system::instance();
        self::validate_context($context);
        creation_permissions::require_anywhere();
        require_capability('local/coursegen:uploadcoursesyllabus', $context);

        try {
            // Get session record.
            $session = new course_session($params['sessionid']);

            // Verify session belongs to current user.
            if ($session->get('userid') != $USER->id) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_not_your_session', 'local_coursegen'),
                ];
            }

            $threadid = $session->get('session_id');
            if (empty($threadid)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_invalid_session', 'local_coursegen'),
                ];
            }

            // Save file from draft area to permanent storage.
            $fs = get_file_storage();
            $syscontext = context_system::instance();
            $usercontext = context_user::instance($USER->id);

            // Prepare draft area.
            $draftfiles = $fs->get_area_files(
                $usercontext->id,
                'user',
                'draft',
                $params['draftitemid'],
                'id',
                false
            );

            if (empty($draftfiles)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_no_file_uploaded', 'local_coursegen'),
                ];
            }

            // Never trust the filepicker: its accepted_types and maxbytes only
            // constrain the browser. Re-check the draft before anything is
            // persisted into the site file storage.
            $rejection = self::syllabus_rejection_reason(reset($draftfiles));
            if ($rejection !== null) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string($rejection, 'local_coursegen'),
                ];
            }

            // Save to syllabus area. accepted_types is declared for parity with
            // courseai_filepicker_init; the binding check is the explicit
            // re-validation of the persisted file below.
            file_save_draft_area_files(
                $params['draftitemid'],
                $syscontext->id,
                'local_coursegen',
                'syllabus',
                $params['sessionid'],
                [
                    'subdirs' => 0,
                    'maxfiles' => 1,
                    'maxbytes' => self::MAX_SYLLABUS_BYTES,
                    'accepted_types' => self::accepted_filepicker_types(),
                ]
            );

            // Get the saved file.
            $files = $fs->get_area_files(
                $syscontext->id,
                'local_coursegen',
                'syllabus',
                $params['sessionid'],
                'id',
                false
            );

            if (empty($files)) {
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string('error_file_save_failed', 'local_coursegen'),
                ];
            }

            $file = reset($files);

            // Re-validate what actually got persisted (extension and mimetype)
            // before a single byte leaves the site.
            $rejection = self::syllabus_rejection_reason($file);
            if ($rejection !== null) {
                $file->delete();
                return [
                    'success' => false,
                    'filename' => '',
                    'message' => get_string($rejection, 'local_coursegen'),
                ];
            }

            $filename = $file->get_filename();

            // Upload to Datacurso API.
            $apiservice = static::get_api_service();
            $response = $apiservice->upload_syllabus($threadid, $file);

            // Audit the external transfer with non-identifying metadata only:
            // the original file name is personal data and must not outlive the
            // file itself in the standard log.
            external_transfer_initiated::create([
                'context' => $context,
                'other' => [
                    'fileextension' => self::file_extension($filename),
                    'filesize' => (int)$file->get_filesize(),
                    'threadid' => $threadid,
                    'sessionid' => (int)$params['sessionid'],
                ],
            ])->trigger();

            // Update session coursedata to include syllabus context type.
            $coursedata = json_decode($session->get('coursedata'), true);
            $coursedata['local_coursegen_context_type'] = 'syllabus';
            $session->set('coursedata', json_encode($coursedata));
            $session->update();

            return [
                'success' => true,
                'filename' => $filename,
                'message' => get_string('courseai_syllabus_upload_success', 'local_coursegen'),
            ];
        } catch (\Exception $e) {
            // Keep the technical detail in developer debugging only: the client
            // receives a localized message without internal information.
            debugging('Unexpected error while uploading syllabus: ' . $e->getMessage());
            return [
                'success' => false,
                'filename' => '',
                'message' => get_string('error_upload_failed', 'local_coursegen'),
            ];
        }
    }

    /**
     * Why a syllabus file must be refused, if it must.
     *
     * @param \stored_file $file File to check, either the draft or the stored copy.
     * @return string|null Language string identifier of the rejection, or null when accepted.
     */
    protected static function syllabus_rejection_reason(\stored_file $file): ?string {
        $extension = self::file_extension($file->get_filename());
        if (!in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            return 'error_syllabus_invalid_file_type';
        }

        if ((int)$file->get_filesize() > self::MAX_SYLLABUS_BYTES) {
            return 'error_syllabus_file_too_large';
        }

        // stored_file::get_mimetype() is not evidence about the content: it
        // holds what file_storage::mimetype() recorded, and that is derived
        // from the file name for every known extension. Inspect the bytes.
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file, true);
        $sniffed = \file_storage::mimetype_from_file($path);
        return in_array($sniffed, self::CONTENT_SIGNATURES[$extension], true) ? null : 'error_syllabus_invalid_file_type';
    }

    /**
     * Lower-case extension of a file name, without the leading dot.
     *
     * @param string $filename File name.
     * @return string Extension, or an empty string when there is none.
     */
    protected static function file_extension(string $filename): string {
        return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Accepted extensions in the dotted form the filepicker API expects.
     *
     * @return string[]
     */
    public static function accepted_filepicker_types(): array {
        return array_map(static function (string $extension): string {
            return '.' . $extension;
        }, self::ACCEPTED_EXTENSIONS);
    }

    /**
     * Build the AI course API service used by this endpoint.
     *
     * Extracted as a protected factory so PHPUnit tests can override it
     * through a testable subclass (late static binding).
     *
     * @return ai_course_api_service
     */
    protected static function get_api_service(): ai_course_api_service {
        return new ai_course_api_service();
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'filename' => new external_value(PARAM_TEXT, 'Uploaded filename'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message'),
        ]);
    }
}
