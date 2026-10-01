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

use local_coursegen\local\ai_activity_types;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_space;

/**
 * Builds the per-row select options for sections_config.
 */
class template_row_options {
    /**
     * Build the per-section behavior select options (custom/keep/exclude).
     *
     * Same action values and lang keys as the previous 3-dot menu items;
     * "custom" stays the default (preselected) behavior for a new template.
     *
     * @param int $sectionid Base course section id.
     * @param string $behavior The behavior to preselect (a saved one when
     *     editing an existing template, "custom" otherwise).
     * @return array Select option contexts.
     */
    public static function section_actions(int $sectionid, string $behavior = template_section::BEHAVIOR_AI_MODIFY): array {
        $valid = template_section::BEHAVIORS;
        if (!in_array($behavior, $valid, true)) {
            $behavior = template_section::BEHAVIOR_AI_MODIFY;
        }

        // "exclude" is not offered in the UI any more: it only renders (and
        // preselects) when an existing template already saved it, so
        // edit-mode hydration never lies about the stored state. The backend
        // keeps accepting and processing it untouched.
        $keys = [template_section::BEHAVIOR_AI_MODIFY, template_section::BEHAVIOR_KEEP];
        if ($behavior === template_section::BEHAVIOR_EXCLUDE) {
            $keys = $valid;
        }

        $items = [];
        foreach ($keys as $key) {
            $label = get_string('template_section_' . $key, 'local_coursegen');
            $tip = get_string('template_section_' . $key . '_tip', 'local_coursegen');
            $items[] = [
                'value' => $key,
                'sectionid' => $sectionid,
                'label' => $label,
                'tip' => $tip,
                'active' => $key === $behavior,
            ];
        }
        return $items;
    }

    /**
     * Build the per-activity action select options.
     *
     * "Space" (the professor provides this activity instead) is offered for
     * every type: it asks nothing of the AI service.
     *
     * Template is the only other action gated to a module type in
     * ai_activity_types::MODNAMES — the real AI service's full content
     * contract, never a constant scoped to whichever implementation currently
     * satisfies it. Anything NOT in that contract
     * only offers Keep / Reference / Exclude. Every row's default is Keep.
     *
     * When editing an existing template, the activity's SAVED action wins
     * over the default — unless it is no longer offered for this row (a
     * saved "template" on a type the generator cannot handle degrades to
     * "keep", the same rule a legacy saved "modify" from before that action
     * existed already follows).
     *
     * @param int $cmid Course module id.
     * @param string $modname Module type name.
     * @param string|null $savedaction The template's saved action for this
     *     cmid, null when there is none (new template, or a new activity).
     * @return array Select option contexts.
     */
    public static function activity_actions(int $cmid, string $modname, ?string $savedaction = null): array {
        $keys = [
            template_activity::ACTION_TEMPLATE,
            template_activity::ACTION_KEEP,
            template_activity::ACTION_REFERENCE,
            template_activity::ACTION_EXCLUDE,
        ];
        $cansupporttemplate = in_array($modname, ai_activity_types::MODNAMES, true);
        if (!$cansupporttemplate) {
            $keys = [
                template_activity::ACTION_KEEP,
                template_activity::ACTION_REFERENCE,
                template_activity::ACTION_EXCLUDE,
            ];
        }
        $keys[] = template_activity::ACTION_SPACE;
        $default = template_activity::ACTION_KEEP;
        if ($savedaction !== null && in_array($savedaction, $keys, true)) {
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
     * Build the render context for one virtual instance row.
     *
     * @param template_instance $instance
     * @return array
     */
    public static function instance_row_context(template_instance $instance): array {
        $name = $instance->get('name');
        $typelabel = $instance->get('typelabel');
        $sourcename = $instance->get('sourcename');
        $modname = $instance->get('modname');
        $iconurl = self::instance_icon_url($modname);
        return [
            'instanceid' => (int) $instance->get('id'),
            'name' => $name,
            'typelabel' => $typelabel,
            'prompt' => (string) $instance->get('prompt'),
            'sourcecmid' => (int) $instance->get('sourcecmid'),
            'sourcename' => $sourcename,
            'modname' => (string) $modname,
            'iconurl' => $iconurl,
        ];
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
     * The display name of a module type, from the site's own language pack.
     *
     * @param string $modname
     * @return string The type's name, or the bare module name when that
     *     module is no longer installed.
     */
    public static function module_type_name(string $modname): string {
        if (\core_component::get_plugin_directory('mod', $modname) === null) {
            return $modname;
        }
        return get_string('modulename', 'mod_' . $modname);
    }

    /**
     * Build the render context for one virtual space row.
     *
     * @param template_space $space
     * @return array
     */
    public static function space_row_context(template_space $space): array {
        $modname = (string) $space->get('modname');
        $required = (bool) $space->get('required');
        $instruction = (string) $space->get('instruction');
        $typename = self::module_type_name($modname);
        $iconurl = self::instance_icon_url($modname);
        $badge = self::space_badge_label($required);
        return [
            'spaceid' => (int) $space->get('id'),
            'name' => $typename,
            'typelabel' => $typename,
            'modname' => $modname,
            'iconurl' => $iconurl,
            'required' => $required,
            'requiredvalue' => (int) $required,
            'badge' => $badge,
            'instruction' => $instruction,
            'hasinstruction' => $instruction !== '',
        ];
    }

    /**
     * Resolve a virtual instance row's icon the exact same way a real
     * activity's is (cm_info::get_icon_url()'s own generic fallback) —
     * from the source template's own snapshotted module type, never a
     * stored URL that could go stale across a theme change.
     *
     * Public because the professor-facing structure endpoint
     * (external\get_template_structure) resolves its instance-row icons
     * through this exact same rule.
     *
     * @param string|null $modname Null for a row saved before this field
     *     existed.
     * @return string Empty when $modname is unknown, so the row falls back
     *     to a generic icon instead of a broken image.
     */
    public static function instance_icon_url(?string $modname): string {
        global $OUTPUT;
        if ($modname === null || $modname === '') {
            return '';
        }
        $icon = $OUTPUT->image_url('monologo', $modname);
        if (\core_component::has_monologo_icon('mod', $modname)) {
            $icon->param('filtericon', 1);
        }
        return $icon->out(false);
    }
}
