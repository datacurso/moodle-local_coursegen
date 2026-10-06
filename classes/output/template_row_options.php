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

/**
 * Select-option builders for the "Course sections" review rows.
 *
 * Extracted out of sections_config so that file stays focused on assembling
 * the render context; this class only ever builds small option arrays for a
 * single row, with no knowledge of modinfo or the overall page.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\output;

use local_coursegen\local\template\template_actions;

/**
 * Builds the per-row select options for sections_config.
 */
class template_row_options {
    /**
     * Build the per-activity action select options: keep it intact or let the AI modify it.
     *
     * @param int $cmid Course module id.
     * @param string|null $savedaction The action saved for this activity, for example "ai";
     *     null when there is none (new template, or a new activity).
     * @return array Select option contexts.
     */
    public static function activity_actions(int $cmid, ?string $savedaction = null): array {
        $default = template_actions::KEEP;
        if ($savedaction !== null && template_actions::is_valid($savedaction)) {
            $default = $savedaction;
        }

        $items = [];
        foreach (template_actions::all() as $key) {
            $label = get_string('template_activity_' . $key, 'local_coursegen');
            $tip = get_string('template_activity_' . $key . '_tip', 'local_coursegen');
            $items[] = [
                'value' => $key,
                'cmid' => $cmid,
                'label' => $label,
                'tip' => $tip,
                'active' => $key === $default,
            ];
        }
        return $items;
    }

    /**
     * Resolve which action out of activity_actions() is actually preselected.
     *
     * @param array $actionoptions Return value of self::activity_actions().
     * @return string The active action value, "keep" if somehow none is.
     */
    public static function active_action(array $actionoptions): string {
        foreach ($actionoptions as $option) {
            if (!empty($option['active'])) {
                return $option['value'];
            }
        }
        return template_actions::KEEP;
    }
}
