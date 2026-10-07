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
 * External API for the professor-facing template guided form: the template's
 * section/activity structure (with the admin-defined lock state applied), the
 * file resources the professor brings a file for included.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use local_coursegen\local\template\template_actions;

/**
 * Per-activity-row building for get_template_structure, kept apart from the
 * webservice contract (execute/execute_parameters/execute_returns) only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait get_template_structure_rows {
    /**
     * Every section of the course with its visible activities.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param \stdClass[] $items Saved items keyed by course module id.
     * @param \renderer_base $output
     * @return array
     */
    private static function sections($course, $modinfo, array $items, $output): array {
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $activities = self::section_activities($modinfo, $section, $items, $output);
            $modified = self::has_modified_activity($activities);
            $behavior = 'keep';
            if ($modified) {
                $behavior = 'aimodify';
            }
            $sections[] = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'behavior'   => $behavior,
                'locked'     => !$modified,
                'activities' => $activities,
            ];
        }
        return $sections;
    }

    /**
     * Whether any of the rows is an activity the AI modifies.
     *
     * @param array $activities
     * @return bool
     */
    private static function has_modified_activity(array $activities): bool {
        foreach ($activities as $activity) {
            if ($activity['aigenerated']) {
                return true;
            }
        }
        return false;
    }

    /**
     * One section's own activity rows.
     *
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @param \stdClass[] $items Saved items keyed by course module id.
     * @param \renderer_base $output
     * @return array
     */
    private static function section_activities($modinfo, $section, array $items, $output): array {
        $cmids = $modinfo->sections[$section->section] ?? [];
        $activities = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->cms[$cmid];
            if ($cm->uservisible) {
                $activities[] = self::activity_row($cm, $items[$cmid] ?? null, $output);
            }
        }
        return $activities;
    }

    /**
     * One activity's own row.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $item The saved item of the activity, or null when nothing was saved for it.
     * @param \renderer_base $output
     * @return array
     */
    private static function activity_row(\cm_info $cm, ?\stdClass $item, $output): array {
        $modified = ($item !== null && $item->action === template_actions::AI);
        $action = 'keep';
        $generationuid = '';
        $generationcmid = 0;
        if ($modified) {
            $action = 'modify';
            $generationuid = (string) $item->uid;
            $generationcmid = (int) $cm->id;
        }
        $icon = $output->image_icon('monologo', $cm->modname, 'mod_' . $cm->modname, ['class' => 'icon activityicon']);
        return [
            'id'      => (string) $cm->id,
            'name'    => format_string($cm->name),
            'modname' => $cm->modname,
            'purpose' => self::get_purpose($cm->modname),
            'typelabel' => '',
            'iconhtml' => $icon,
            'locked'  => !$modified,
            'action'  => $action,
            'isinstance' => false,
            'aigenerated' => $modified,
            'generationuid' => $generationuid,
            'generationcmid' => $generationcmid,
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
