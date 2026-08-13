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

namespace local_coursegen\local\service;

/**
 * Builds the site's file-type group catalog for the AI service payloads.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filetype_catalog_service {
    /**
     * Get the site's file-type group catalog (group key => dot-prefixed extensions).
     *
     * Built from core's filetypes utility so custom file types and groups defined
     * by the site admin are included. Lets the AI service infer and validate the
     * accepted file types of generated activities against groups that actually
     * exist on this site. Returns null on failure or when there are no groups,
     * so callers simply omit the payload field and the service falls back to the
     * stock Moodle catalog.
     *
     * @return array|null Map of group key to extension list, or null when unavailable.
     */
    public static function get_groups(): ?array {
        try {
            $filetypegroups = [];
            foreach ((new \core_form\filetypes_util())->get_groups_info() as $groupkey => $groupinfo) {
                // Already a flat list of dot-prefixed extensions (see filetypes_util::get_groups_info()).
                $filetypegroups[$groupkey] = $groupinfo->extensions;
            }
            return !empty($filetypegroups) ? $filetypegroups : null;
        } catch (\Throwable $e) {
            debugging('local_coursegen: could not resolve file-type groups: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}
