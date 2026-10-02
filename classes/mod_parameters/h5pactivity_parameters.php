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

/**
 * Class h5pactivity_parameters
 *
 * Downloads the generated .h5p package into the 'packagefile' form field and
 * validates it cheaply before the activity is created.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_parameters extends base_parameters {
    /**
     * Returns the adjusted parameters for the module h5pactivity.
     *
     * @return object Adjusted parameters for the module h5pactivity.
     */
    public function get_parameters() {
        $file = $this->download_package_into('packagefile');
        $this->validate_package($file, $file->get_filename());
        return $this->parameters;
    }

    /**
     * Validate the downloaded package before it is attached to the activity.
     *
     * The module form validation is bypassed in this flow, so an invalid
     * package would otherwise only fail when a student opens the activity.
     * The check is deliberately cheap: extension, non-empty content and a
     * readable zip containing the h5p.json manifest. No core_h5p deployment
     * is attempted here.
     *
     * @param \stored_file $file Downloaded package file.
     * @param string $filename Clean package file name.
     * @return void
     * @throws moodle_exception When the package is not a valid .h5p file.
     */
    private function validate_package(\stored_file $file, string $filename): void {
        if (\core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'h5p') {
            throw new moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        if ((int) $file->get_filesize() === 0) {
            throw new moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
        }

        $temppath = $file->copy_content_to_temp();
        try {
            $zip = new \ZipArchive();
            $opened = $zip->open($temppath);
            $hasmanifest = false;
            if ($opened === true) {
                $hasmanifest = $zip->locateName('h5p.json') !== false;
                $zip->close();
            }
            if ($opened !== true || !$hasmanifest) {
                throw new moodle_exception('error_invalid_package', 'local_coursegen', '', $filename);
            }
        } finally {
            @unlink($temppath);
        }
    }
}
