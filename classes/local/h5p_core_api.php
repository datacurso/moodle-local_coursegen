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

namespace local_coursegen\local;

/**
 * Class h5p_core_api
 *
 * Resolves the H5P framework (core API) version this Moodle site runs, so the
 * AI service can package generated .h5p files with libraries compatible with
 * that version (v127 vs v128 library set). Shared by the individual activity
 * flow and the course planning flow.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_core_api {

    /**
     * Resolve the site H5P core API version as "major.minor".
     *
     * When the version cannot be resolved, null is returned and the request
     * simply travels without the field: the service falls back to its
     * most-compatible library set and the generation continues.
     *
     * @return string|null Version as "major.minor", or null when unresolvable.
     */
    public static function resolve(): ?string {
        try {
            (new \core_h5p\factory())->get_core(); // Ensures the active H5P handler is autoloaded.
            $coreapi = \core_h5p\core::$coreApi;
            if (!empty($coreapi['majorVersion'])) {
                return $coreapi['majorVersion'] . '.' . $coreapi['minorVersion'];
            }
        } catch (\Throwable $e) {
            debugging('local_coursegen: could not resolve H5P core API: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }
}
