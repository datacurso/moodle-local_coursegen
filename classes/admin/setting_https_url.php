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

namespace local_coursegen\admin;

use admin_setting_configtext;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Service URL setting that enforces HTTPS.
 *
 * The dev/staging URL overrides carry prompts, syllabus files and generated
 * content to the Datacurso service, so plain HTTP would expose that traffic.
 * An empty value is accepted (the override is optional) and HTTP is allowed
 * only for localhost / 127.0.0.1 while developer debugging is enabled.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_https_url extends admin_setting_configtext {
    /**
     * Validate the URL: empty, https://, or http://localhost under debugdeveloper.
     *
     * @param string $data The submitted value.
     * @return mixed True if ok, localized error string otherwise.
     */
    public function validate($data) {
        global $CFG;

        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }

        $url = trim((string)$data);
        if ($url === '') {
            return true;
        }

        if (preg_match('#^https://#i', $url)) {
            return true;
        }

        // Local development exception: plain HTTP to the loopback host only,
        // and only while developer debugging is enabled.
        if (!empty($CFG->debugdeveloper) && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/.*)?$#i', $url)) {
            return true;
        }

        return get_string('error_https_required', 'local_coursegen');
    }
}
