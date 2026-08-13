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

namespace local_coursegen;

use local_coursegen\local\service\ai_course_api_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Testable create_mod_stream that lets tests inject a mock API service through
 * the protected factory seam (late static binding).
 *
 * Loading this fixture pulls in lib/externallib.php (through
 * create_mod_stream), so any test using it must run in an isolated process.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_create_mod_stream extends \local_coursegen\external\create_mod_stream {
    /** @var ai_course_api_service|null Mock service injected by tests. */
    public static $mockservice = null;

    /**
     * Return the injected mock service instead of building a real one.
     *
     * @return ai_course_api_service
     */
    protected static function get_api_service(): ai_course_api_service {
        return static::$mockservice;
    }
}
