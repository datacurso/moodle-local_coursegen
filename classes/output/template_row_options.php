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

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_section;

/**
 * Builds the per-row select options for sections_config.
 */
class template_row_options {
    /**
     * Build the original section behavior control options.
     *
     * @param int $sectionid Section id.
     * @param string $behavior Saved behavior.
     * @return array
     */
    public static function section_actions(int $sectionid, string $behavior = template_section::BEHAVIOR_AI_MODIFY): array {
        if (!in_array($behavior, template_section::BEHAVIORS, true)) {
            $behavior = template_section::BEHAVIOR_AI_MODIFY;
        }
        $keys = [template_section::BEHAVIOR_AI_MODIFY, template_section::BEHAVIOR_KEEP];
        if ($behavior === template_section::BEHAVIOR_EXCLUDE) {
            $keys = template_section::BEHAVIORS;
        }
        $items = [];
        foreach ($keys as $key) {
            $items[] = [
                'value' => $key,
                'sectionid' => $sectionid,
                'label' => get_string('template_section_' . $key, 'local_coursegen'),
                'tip' => get_string('template_section_' . $key . '_tip', 'local_coursegen'),
                'active' => $key === $behavior,
            ];
        }
        return $items;
    }

    /**
     * Build the per-activity action select options.
     *
     * Every activity offers only Keep intact and Modify with AI. The saved
     * Modify with AI action is preserved; legacy action values render as Keep.
     *
     * @param int $cmid Course module id.
     * @param string $modname Module type name, retained for caller compatibility.
     * @param string|null $savedaction The template's saved action for this
     *     cmid, null when there is none (new template, or a new activity).
     * @return array Select option contexts.
     */
    public static function activity_actions(int $cmid, string $modname, ?string $savedaction = null): array {
        // The editor exposes only the two supported activity decisions. Legacy
        // values remain valid in storage and generation, but are not presented.
        $keys = [template_activity::ACTION_KEEP, template_activity::ACTION_TEMPLATE];
        $default = template_activity::ACTION_KEEP;
        if ($savedaction === template_activity::ACTION_TEMPLATE) {
            $default = $savedaction;
        }

        $items = [];
        foreach ($keys as $key) {
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
        return template_activity::ACTION_KEEP;
    }

    /**
     * Build the template-scope select options for a row marked action=template.
     *
     * @param int $cmid Course module id.
     * @param string $scope The scope to preselect; an unrecognised value
     *     falls back to "course".
     * @return array Select option contexts.
     */
    public static function template_scope_options(int $cmid, string $scope = template_activity::SCOPE_COURSE): array {
        if (!in_array($scope, template_activity::SCOPES, true)) {
            $scope = template_activity::SCOPE_COURSE;
        }

        $items = [];
        foreach (template_activity::SCOPES as $key) {
            $label = get_string('template_activity_scope_' . $key, 'local_coursegen');
            $tip = get_string('template_activity_scope_' . $key . '_tip', 'local_coursegen');
            $items[] = [
                'value' => $key,
                'cmid' => $cmid,
                'label' => $label,
                'tip' => $tip,
                'active' => $key === $scope,
            ];
        }
        return $items;
    }

    /**
     * Resolve the label of the currently active scope option.
     *
     * @param array $scopeoptions Return value of self::template_scope_options().
     * @return string The active option's label, or the "course" option's
     *     label if none is flagged active.
     */
    public static function active_scope_label(array $scopeoptions): string {
        foreach ($scopeoptions as $option) {
            if (!empty($option['active'])) {
                return $option['label'];
            }
        }
        return get_string('template_activity_scope_course', 'local_coursegen');
    }

    /**
     * The badge text for a space: "Space · Required" / "Space · Optional".
     *
     * @param bool $required
     * @return string
     */
    public static function space_badge_label(bool $required): string {
        $requirement = get_string('template_space_optional', 'local_coursegen');
        if ($required) {
            $requirement = get_string('template_space_required', 'local_coursegen');
        }
        return get_string('template_space_badge', 'local_coursegen', $requirement);
    }

    /**
     * Resolve a virtual row icon through Moodle's image URL helper.
     *
     * @param string|null $modname Null for older rows.
     * @return string
     */
    public static function instance_icon_url(?string $modname): string {
        return template_virtual_row_options::instance_icon_url($modname);
    }

}
