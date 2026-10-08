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
 * Service URL setting that enforces HTTPS, stored for the current tenant.
 *
 * The dev/staging URL overrides carry prompts, syllabus files and generated
 * content to the Datacurso service, so plain HTTP would expose that traffic.
 * An empty value is accepted (the override is optional) and HTTP is allowed
 * only for localhost / 127.0.0.1 while developer debugging is enabled.
 * The value belongs to the tenant the user is currently in
 * ({@see tenant_scoped_setting}).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_https_url extends admin_setting_configtext {
    use tenant_scoped_setting;

    /**
     * Validate the URL: empty, https://, or http://localhost under debugdeveloper.
     *
     * @param string $data The submitted value.
     * @return mixed True if ok, localized error string otherwise.
     */
    public function validate($data) {
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }

        return self::validate_url((string)$data) ?? true;
    }

    /**
     * Validates a service URL against the HTTPS policy.
     *
     * Empty values are valid (the override is optional) and so is any https://
     * URL. Plain HTTP is accepted only for the loopback host (localhost or
     * 127.0.0.1) while developer debugging is enabled.
     *
     * @param string $url The URL to validate.
     * @return string|null Null when valid, the localized error message otherwise.
     */
    public static function validate_url(string $url): ?string {
        global $CFG;

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^https://#i', $url)) {
            return null;
        }

        // Local development exception: plain HTTP to the loopback host only,
        // and only while developer debugging is enabled.
        if (!empty($CFG->debugdeveloper) && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/.*)?$#i', $url)) {
            return null;
        }

        return get_string('error_https_required', 'local_coursegen');
    }
}
