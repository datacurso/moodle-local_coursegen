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
 * Render the "Course sections" review: per-section collapsible cards with a
 * report-style activity table and per-row action menus.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\output;

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_section;
use local_coursegen\local\service\template_content_generator;

/**
 * Build the sections review straight from modinfo.
 *
 * This used to re-render the base course through its real course-format
 * renderer and then DOM-surgically strip every native editing affordance
 * while injecting custom dropdowns and prompt textareas. That whole approach
 * is gone: the review is now a clean structured render of its own mustache
 * template (templates/template_course_sections.mustache) — one collapsible
 * card per section, each holding a table of its activities where every row
 * carries a selection checkbox plus an action select preselected with its
 * type's default, and every section table a select-all checkbox and a bulk
 * "apply to selected" select. local/template/sections_events.js binds them
 * all after every render.
 */
class sections_config {
    /**
     * Render the course sections review for a base course.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param int $templateid Existing template whose saved per-section/
     *     per-activity configuration should be preselected (0: type defaults).
     * @return string Rendered HTML.
     */
    public static function render(\course_modinfo $modinfo, int $templateid = 0): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template(
            'local_coursegen/template_course_sections',
            self::export_for_template($modinfo, $templateid)
        );
    }

    /**
     * Build the template context from modinfo.
     *
     * When editing an existing template ($templateid > 0), its saved
     * per-section behaviors and per-activity actions preselect the rendered
     * controls — the server render stays the single source of truth for row
     * defaults (sections_events.js seeds its state FROM the rendered
     * selects). Saved rows whose cmid/sectionid no longer exists in the base
     * course are simply never looked up, and activities added since the
     * template was saved fall back to their type default.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param int $templateid Existing template id, 0 for a new template.
     * @return array Template context.
     */
    public static function export_for_template(\course_modinfo $modinfo, int $templateid = 0): array {
        $course = $modinfo->get_course();

        $savedbehaviors = [];
        $savedactions = [];
        if ($templateid > 0) {
            foreach (template_section::get_records(['templateid' => $templateid]) as $record) {
                $savedbehaviors[(int) $record->get('sectionid')] = $record->get('behavior');
            }
            foreach (template_activity::get_records(['templateid' => $templateid]) as $record) {
                $savedactions[(int) $record->get('cmid')] = $record->get('action');
            }
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $activities = [];
            if (!empty($modinfo->sections[$sectioninfo->section])) {
                foreach ($modinfo->sections[$sectioninfo->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if ($cm->deletioninprogress) {
                        continue;
                    }
                    $activities[] = [
                        'cmid' => (int) $cm->id,
                        'name' => $cm->get_formatted_name(),
                        // Link to the real activity in the base course; null
                        // for modules with no view page of their own (label),
                        // whose names stay plain text.
                        'viewurl' => $cm->url ? $cm->url->out(false) : null,
                        'modname' => $cm->modname,
                        'typelabel' => $cm->get_module_type_name(),
                        'iconurl' => $cm->get_icon_url()->out(false),
                        'actions' => self::build_activity_actions(
                            (int) $cm->id,
                            $cm->modname,
                            $savedactions[(int) $cm->id] ?? null
                        ),
                    ];
                }
            }

            $sectionid = (int) $sectioninfo->id;
            $sections[] = [
                'id' => $sectionid,
                'num' => (int) $sectioninfo->section,
                'name' => get_section_name($course, $sectioninfo),
                'activitycount' => count($activities),
                'hasactivities' => !empty($activities),
                'activities' => $activities,
                'actions' => self::build_section_actions($sectionid, $savedbehaviors[$sectionid] ?? 'custom'),
            ];
        }

        return [
            // The collapse ids are built from course id + section id: they
            // must be deterministic AND valid CSS identifiers ({{uniqid}}
            // output can start with a digit, which silently breaks the
            // Bootstrap 4 data-target="#..." selector).
            'courseid' => (int) $course->id,
            'sections' => $sections,
            'hassections' => !empty($sections),
        ];
    }

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
    private static function build_section_actions(int $sectionid, string $behavior = 'custom'): array {
        $valid = ['custom', 'keep', 'exclude'];
        if (!in_array($behavior, $valid, true)) {
            $behavior = 'custom';
        }

        // "exclude" is not offered in the UI any more: it only renders (and
        // preselects) when an existing template already saved it, so
        // edit-mode hydration never lies about the stored state. The backend
        // keeps accepting and processing it untouched.
        $keys = $behavior === 'exclude' ? $valid : ['custom', 'keep'];

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
     * Only ever offers "Modify" for a module type in
     * template_content_generator::AI_SUPPORTED_TYPES — the real AI service's
     * full content contract, never a constant scoped to whichever
     * implementation currently satisfies it. Anything NOT in that contract
     * only offers Keep / Reference / Exclude, and defaults to Keep instead
     * of Modify.
     *
     * When editing an existing template, the activity's SAVED action wins
     * over the type default — unless it is no longer offered for this row
     * (a saved "modify" on a type the generator cannot handle degrades to
     * "keep", the same rule the defaults follow).
     *
     * @param int $cmid Course module id.
     * @param string $modname Module type name.
     * @param string|null $savedaction The template's saved action for this
     *     cmid, null when there is none (new template, or a new activity).
     * @return array Select option contexts.
     */
    private static function build_activity_actions(int $cmid, string $modname, ?string $savedaction = null): array {
        $keys = ['modify', 'keep', 'reference', 'exclude'];
        $cansupportmodify = in_array($modname, template_content_generator::AI_SUPPORTED_TYPES, true);
        if (!$cansupportmodify) {
            $keys = ['keep', 'reference', 'exclude'];
        }
        $default = $cansupportmodify ? 'modify' : 'keep';
        if ($savedaction !== null && in_array($savedaction, $keys, true)) {
            $default = $savedaction;
        }

        $items = [];
        foreach ($keys as $key) {
            $items[] = [
                'value' => $key,
                'cmid' => $cmid,
                'label' => get_string('template_activity_' . $key, 'local_coursegen'),
                'tip' => get_string('template_activity_' . $key . '_tip', 'local_coursegen'),
                'active' => $key === $default,
            ];
        }
        return $items;
    }
}
