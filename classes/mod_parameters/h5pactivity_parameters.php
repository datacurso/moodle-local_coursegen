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

use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class h5pactivity_parameters
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_parameters extends base_parameters {
    /**
     * Returns the adjusted parameters for the module scorm.
     *
     * @return object Adjusted parameters for the module scorm.
     */
    public function get_parameters() {
        $modsettings = $this->parameters->mod_settings;
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $client = api_client_factory::ai_course_api($baseurl, $baseurleu);
        $endpoint = '/files/download?path=' . $modsettings['file_path'];
        $file = $client->download_file($endpoint, $modsettings['file_name']);
        $this->parameters->packagefile = $file->get_itemid();
        return $this->parameters;
    }
}
