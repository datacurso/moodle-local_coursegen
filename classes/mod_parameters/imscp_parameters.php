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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class imscp_parameters
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imscp_parameters extends base_parameters {
    /**
     * Returns the adjusted parameters for the module imscp.
     *
     * When the AI provider client cannot be built or the download fails the
     * parameters are returned without the package (the module is still created)
     * and a package_download_skipped event is triggered.
     *
     * @return object Adjusted parameters for the module imscp.
     */
    public function get_parameters() {
        $downloadinfo = $this->get_package_download_info();

        $file = $this->download_package($downloadinfo['endpoint'], $downloadinfo['filename']);
        if ($file !== null) {
            $this->parameters->package = $file->get_itemid();
        }
        return $this->parameters;
    }
}
