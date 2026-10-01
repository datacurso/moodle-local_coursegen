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

namespace local_coursegen\mod_parameters;

use core\exception\moodle_exception;
use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class base_parameters
 *
 * Base of the per-module parameter handlers that adjust the AI result before
 * the module form data is handed to add_moduleinfo(). The four package-type
 * handlers (imscp, resource, scorm and h5pactivity) share
 * download_package_into(); folder handles a tree of files on its own.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_parameters {
    /**
     * @var object $parameters Default parameters for the module.
     */
    protected $parameters;

    /**
     * Constructor.
     *
     * @param object $parameters Default parameters for the module.
     */
    public function __construct($parameters) {
        $this->parameters = $parameters;
    }

    /**
     * Returns the adjusted parameters for the module.
     *
     * @return object Adjusted parameters for the module.
     */
    abstract public function get_parameters();

    /**
     * Download the generated package and store its draft item id in the given form field.
     *
     * The package location comes from mod_settings (see get_package_download_info());
     * the file is downloaded through the AI service into the current user's draft
     * area and the module form field (e.g. 'package' for imscp) receives the draft
     * item id, exactly as a teacher's upload would.
     *
     * @param string $field Module form field that receives the draft item id.
     * @return \stored_file The downloaded package, for handlers that validate its content.
     * @throws moodle_exception When the package info is missing or the download fails.
     */
    protected function download_package_into(string $field): \stored_file {
        $downloadinfo = $this->get_package_download_info();

        $file = api_client_factory::ai_course_api_service()->download_file(
            $downloadinfo['endpoint'],
            $downloadinfo['filename']
        );
        if ($file === null) {
            throw new moodle_exception('error_invalid_package', 'local_coursegen', '', $downloadinfo['filename']);
        }

        $this->parameters->{$field} = $file->get_itemid();

        return $file;
    }

    /**
     * Validate and normalize the package download info from mod_settings.
     *
     * Package-type results must carry the remote file_path and file_name in
     * mod_settings. The remote path is URL-encoded as a single query value
     * (mirroring the image download endpoints) and the file name is reduced
     * to a valid Moodle file name.
     *
     * @return array Array with 'endpoint' and 'filename' keys.
     * @throws moodle_exception When file_path or file_name is missing, or the
     *                           file name cleans down to an empty string.
     */
    protected function get_package_download_info(): array {
        $modsettings = (array) ($this->parameters->mod_settings ?? []);

        $filepath = $modsettings['file_path'] ?? '';
        $filename = $modsettings['file_name'] ?? '';
        if (!is_string($filepath) || trim($filepath) === '' || !is_string($filename) || trim($filename) === '') {
            throw new moodle_exception('error_missing_package_info', 'local_coursegen');
        }

        $cleanname = clean_param(basename($filename), PARAM_FILE);
        if ($cleanname === '') {
            throw new moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        return [
            'endpoint' => self::build_download_endpoint($filepath),
            'filename' => $cleanname,
        ];
    }

    /**
     * Build the service download endpoint for a remote file path.
     *
     * The path travels as a single query value, so it is percent-encoded
     * (RFC 3986): reserved characters such as '&', '#' or spaces cannot alter
     * the request, and the service decodes it back to the original path.
     *
     * @param string $path Remote file path as returned by the AI service.
     * @return string Endpoint path with the encoded query value.
     */
    protected static function build_download_endpoint(string $path): string {
        return '/files/download?path=' . rawurlencode($path);
    }
}
