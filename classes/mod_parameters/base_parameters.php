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

/**
 * Class base_parameters
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
     * Validate and normalize the package download info from mod_settings.
     *
     * Package-type results must carry the remote file_path and file_name in
     * mod_settings. The remote path is URL-encoded as a single query value
     * (mirroring the image download endpoints) and the file name is reduced
     * to a valid Moodle file name.
     *
     * @return array Array with 'endpoint' and 'filename' keys.
     * @throws \moodle_exception When file_path or file_name is missing, or the
     *                           file name cleans down to an empty string.
     */
    protected function get_package_download_info(): array {
        $modsettings = (array) ($this->parameters->mod_settings ?? []);

        $filepath = $modsettings['file_path'] ?? '';
        $filename = $modsettings['file_name'] ?? '';
        if (!is_string($filepath) || trim($filepath) === '' || !is_string($filename) || trim($filename) === '') {
            throw new \moodle_exception('error_missing_package_info', 'local_coursegen');
        }

        $cleanname = clean_param(basename($filename), PARAM_FILE);
        if ($cleanname === '') {
            throw new \moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        return [
            'endpoint' => '/files/download?path=' . rawurlencode($filepath),
            'filename' => $cleanname,
        ];
    }
}
