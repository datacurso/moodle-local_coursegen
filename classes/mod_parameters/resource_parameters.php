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

use aiprovider_datacurso\httpclient\ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Class resource_parameters
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_parameters extends base_parameters {
    /**
     * Returns the adjusted parameters for the module resource.
     *
     * @return object Adjusted parameters for the module resource.
     */
    public function get_parameters() {
        // A caller that already resolved this resource's real file into a draft
        // itemid itself (e.g. local_coursegen's course-recreation CLI, working
        // from a real exported course rather than a live Datacurso response)
        // sets this directly — skip the production-only Datacurso download in
        // that case. Default (mock/production) behavior is unchanged: this key
        // is never set by anything else today.
        $modsettings = (array) ($this->parameters->mod_settings ?? []);
        if (!empty($modsettings['draft_itemid'])) {
            $this->parameters->files = (int) $modsettings['draft_itemid'];
            return $this->parameters;
        }

        $downloadinfo = $this->get_package_download_info();
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

        $client = new ai_course_api(null, $baseurl, $baseurleu);
        $file = $client->download_file($downloadinfo['endpoint'], $downloadinfo['filename']);
        $this->parameters->files = $file->get_itemid();
        return $this->parameters;
    }
}
