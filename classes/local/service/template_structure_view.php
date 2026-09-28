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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\output\template_row_options;

/**
 * Builds the professor-facing template guided-form structure: every section
 * with its activities (real ones and virtual instances interleaved in the
 * plan's own display order, with the admin-defined lock state applied), and
 * the catalog of activity types the professor may add.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_structure_view {
    /**
     * The full guided-form structure for one template.
     *
     * @param template $template
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param \renderer_base $output
     * @return array
     */
    public static function build(template $template, $course, $modinfo, $output): array {
        $sectionsettings = self::section_settings($template);
        $activitysettings = self::activity_settings($template);
        $instancesbysection = self::instances_by_section($template);

        $sections = self::sections($course, $modinfo, $sectionsettings, $activitysettings, $instancesbysection, $output);

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = $template->get('maxsections') ?? 0;
        $maxsections = (int) $maxsections;
        // The stored value already IS the extra allowance - how many sections
        // the professor may add ON TOP of the template's own - so the
        // template's sections never get subtracted from it.
        if ($nolimit) {
            $remaining = 0;
        } else {
            $remaining = max(0, $maxsections);
        }

        $allowedtypes = self::allowed_types($template);
        $allowedactivities = self::allowed_activities($allowedtypes, $output);

        return [
            'nolimit' => $nolimit,
            'maxsections' => $maxsections,
            'remainingsections' => $remaining,
            'sections' => $sections,
            'allowedactivities' => $allowedactivities,
        ];
    }

    /**
     * sectionid => saved behavior, for this template.
     *
     * @param template $template
     * @return array
     */
    private static function section_settings(template $template): array {
        $sectionsettings = [];
        foreach (template_section::get_records(['templateid' => $template->get('id')]) as $s) {
            $sectionid = $s->get('sectionid');
            $behavior = $s->get('behavior');
            $sectionsettings[$sectionid] = $behavior;
        }
        return $sectionsettings;
    }

    /**
     * cmid => saved action, for this template.
     *
     * @param template $template
     * @return array
     */
    private static function activity_settings(template $template): array {
        $activitysettings = [];
        foreach (template_activity::get_records(['templateid' => $template->get('id')]) as $a) {
            $cmid = $a->get('cmid');
            $action = $a->get('action');
            $activitysettings[$cmid] = $action;
        }
        return $activitysettings;
    }

    /**
     * sectionid => the virtual instances anchored there.
     *
     * @param template $template
     * @return array
     */
    private static function instances_by_section(template $template): array {
        $instancesbysection = [];
        foreach (template_instance::get_records(['templateid' => $template->get('id')]) as $instance) {
            $sectionid = $instance->get('sectionid');
            $instancesbysection[$sectionid][] = $instance;
        }
        return $instancesbysection;
    }

    /**
     * Every section's own row, with its activities.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param array $sectionsettings sectionid => behavior.
     * @param array $activitysettings cmid => action.
     * @param array $instancesbysection sectionid => instances.
     * @param \renderer_base $output
     * @return array
     */
    private static function sections(
        $course,
        $modinfo,
        array $sectionsettings,
        array $activitysettings,
        array $instancesbysection,
        $output
    ): array {
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $behavior = $sectionsettings[$section->id] ?? 'aimodify';
            if ($behavior === 'exclude') {
                continue;
            }
            $activities = self::section_activities($modinfo, $section, $activitysettings, $instancesbysection, $output);
            $sections[] = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'behavior'   => $behavior,
                'locked'     => ($behavior === 'keep'),
                'activities' => $activities,
            ];
        }
        return $sections;
    }

    /**
     * One section's own activity rows, interleaving real activities and
     * virtual instances in the plan's own display order.
     *
     * Interleave over ALL real cmids - including rows filtered out below
     * (hidden actions, not uservisible) - so an instance anchored to a
     * filtered-out row keeps its anchor's slot: once the hidden anchor is
     * dropped, the instance renders exactly where that anchor would have
     * been (immediately after the nearest preceding visible row, or at the
     * section start when there is none). Orphan anchors append at the
     * section end, the same rule the admin review follows.
     *
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @param array $activitysettings
     * @param array $instancesbysection
     * @param \renderer_base $output
     * @return array
     */
    private static function section_activities(
        $modinfo,
        $section,
        array $activitysettings,
        array $instancesbysection,
        $output
    ): array {
        $sectioncms = $modinfo->sections[$section->section] ?? [];
        $sectioninstances = $instancesbysection[$section->id] ?? [];
        $rows = template_instance_layout::ordered_rows($sectioncms, $sectioninstances);

        $activities = [];
        foreach ($rows as $row) {
            $activity = self::section_activity_row($row, $modinfo, $activitysettings, $output);
            if ($activity !== null) {
                $activities[] = $activity;
            }
        }
        return $activities;
    }

    /**
     * One row of a section's plan, as an activity entry - or null when it
     * does not reach the professor at all.
     *
     * @param array $row
     * @param \course_modinfo $modinfo
     * @param array $activitysettings
     * @param \renderer_base $output
     * @return array|null
     */
    private static function section_activity_row(array $row, $modinfo, array $activitysettings, $output): ?array {
        if ($row['type'] === 'instance') {
            return self::instance_row($row['record']);
        }
        $cm = $modinfo->cms[$row['cmid']];
        if (!$cm->uservisible) {
            return null;
        }
        // Mirror the admin's action mapping: keep (with unset defaulting to
        // keep, and a legacy saved "modify" normalising to keep) stays
        // visible and locked; reference, template (mold) and exclude rows
        // never reach the professor at all.
        $action = $activitysettings[$cm->id] ?? 'keep';
        if ($action === 'modify') {
            $action = 'keep';
        }
        if ($action !== 'keep') {
            return null;
        }
        $purpose = self::get_purpose($cm->modname);
        $name = format_string($cm->name);
        $iconhtml = $output->image_icon('monologo', $cm->modname, 'mod_' . $cm->modname, ['class' => 'icon activityicon']);
        return [
            'id'      => (int) $cm->id,
            'name'    => $name,
            'modname' => $cm->modname,
            'purpose' => $purpose,
            'typelabel' => '',
            'iconhtml' => $iconhtml,
            'locked'  => true,
            'action'  => $action,
            'isinstance' => false,
            'aigenerated' => false,
            'generationcmid' => 0,
        ];
    }

    /**
     * Build one virtual-instance activity row ("generate an activity here,
     * molded on one of the template's model activities").
     *
     * Id scheme: the row id is the NEGATIVE of the tpl_instance record id.
     * Every other id in this response is a cmid (always positive), so a
     * negative id can never collide with one, stays stable across reloads,
     * and remains a usable client-side key; the client re-seeds its own
     * placeholder-id counter below the smallest received id so
     * professor-added rows cannot collide either (see state.js).
     *
     * Everything renders from the row's own snapshots (name, typelabel,
     * modname) - sourcecmid is never dereferenced. The icon resolves from
     * the snapshot modname through the exact same monologo rule as the
     * admin review (template_row_options::instance_icon_url()), and an
     * empty snapshot yields an empty iconhtml, not a broken image.
     *
     * @param template_instance $instance
     * @return array
     */
    private static function instance_row(template_instance $instance): array {
        $modname = (string) $instance->get('modname');
        $iconurl = template_row_options::instance_icon_url($instance->get('modname'));
        $iconhtml = '';
        if ($iconurl !== '') {
            $iconhtml = \html_writer::empty_tag('img', ['src' => $iconurl, 'class' => 'icon activityicon', 'alt' => '']);
        }
        if ($modname === '') {
            $purpose = MOD_PURPOSE_OTHER;
        } else {
            $purpose = self::get_purpose($modname);
        }
        $instanceid = (int) $instance->get('id');
        $name = format_string($instance->get('name'));
        $typelabel = format_string($instance->get('typelabel'));
        $generationcmid = template_export_service::instance_cmid($instanceid);
        return [
            'id'      => -$instanceid,
            'name'    => $name,
            'modname' => $modname,
            'purpose' => $purpose,
            'typelabel' => $typelabel,
            'iconhtml' => $iconhtml,
            'locked'  => true,
            'action'  => '',
            'isinstance' => true,
            'aigenerated' => true,
            // The id this row will answer to in the generation's progress
            // events, so the live view can mark THIS activity when its own
            // content lands. Same value template_export_service sends.
            'generationcmid' => $generationcmid,
        ];
    }

    /**
     * The template's own allowed-type list, decoded from storage.
     *
     * @param template $template
     * @return array
     */
    private static function allowed_types(template $template): array {
        $raw = $template->get('allowedtypes');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * The allowed-type catalog, each with its display name, purpose and
     * icon, sorted by display name.
     *
     * @param array $allowedtypes
     * @param \renderer_base $output
     * @return array
     */
    private static function allowed_activities(array $allowedtypes, $output): array {
        $allowedactivities = [];
        foreach ($allowedtypes as $modname) {
            $activity = self::allowed_activity($modname, $output);
            if ($activity !== null) {
                $allowedactivities[] = $activity;
            }
        }
        usort($allowedactivities, fn($a, $b) => strcasecmp($a['displayname'], $b['displayname']));
        return $allowedactivities;
    }

    /**
     * One allowed type's own catalog entry, or null when it names no real
     * installed module.
     *
     * @param string $modname
     * @param \renderer_base $output
     * @return array|null
     */
    private static function allowed_activity(string $modname, $output): ?array {
        if (!\core_component::is_valid_plugin_name('mod', $modname)) {
            return null;
        }
        $displayname = get_string('pluginname', 'mod_' . $modname);
        $purpose = self::get_purpose($modname);
        $iconhtml = $output->image_icon('monologo', $modname, 'mod_' . $modname, ['class' => 'icon activityicon']);
        return [
            'modname'     => $modname,
            'displayname' => $displayname,
            'purpose'     => $purpose,
            'iconhtml'    => $iconhtml,
        ];
    }

    /**
     * Resolve a module's Moodle "purpose" (content, assessment, collaboration...).
     *
     * @param string $modname Module name.
     * @return string
     */
    private static function get_purpose(string $modname): string {
        return plugin_supports('mod', $modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER);
    }
}
