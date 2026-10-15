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

use course_modinfo;

/**
 * Gives each activity and section of the new course the settings its template counterpart has.
 *
 * An activity written by the AI is created from its description and a kept one is copied, so neither carries on
 * its own what the template sets around the activity, and the sections of the new course are created empty. Once
 * everything exists, each activity and section that stands for one of the template takes from it:
 *
 * - its visibility, whether it is shown on the course page, and its access restrictions;
 * - how its completion is tracked, the rules of its module that do it, and its group mode and grouping;
 * - its tags and the competencies it is linked to.
 *
 * The template's dates are never copied: not the one it must be completed by, nor the dates of a restriction.
 * What the course sets as a whole around its activities (the criteria that complete it) is copied by
 * {@see template_course_completion_copier}.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_settings_copier {
    /** @var string[] The columns of an activity's record that are copied as they are, when the site has them. */
    private const ACTIVITY_COLUMNS = [
        'completion', 'completionview', 'completiongradeitemnumber', 'completionpassgrade', 'groupmode',
        'showdescription', 'downloadcontent', 'lang',
    ];

    /**
     * Copy the settings of the template onto its counterparts in a course built from a template.
     *
     * @param int $templateid The template.
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function copy_into(
        int $templateid,
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        global $DB;

        $basecourseid = (int) $DB->get_field('local_coursegen_template', 'courseid', ['id' => $templateid]);
        if ($basecourseid <= 0) {
            return;
        }
        $counterparts = kept_link_rewriter::counterparts($payloadactivities, $generatedcms, $keptcms);
        $created = self::created_by_source($payloadactivities, $generatedcms, $keptcms);
        $sections = self::sections_by_number($basecourseid, $courseid);
        $sources = $created === [] ? [] : $DB->get_records_list('course_modules', 'id', array_keys($created));

        $maps = self::maps($basecourseid, $courseid, $counterparts, $created, $sources, $sections);
        $existing = template_counterparts::existing_ids($courseid);
        foreach ($sections as $pair) {
            self::apply_section($courseid, $pair, $maps, $existing);
        }
        foreach ($created as $sourcecmid => $cmids) {
            foreach ($cmids as $cmid) {
                self::apply_activity((int) $cmid, $sources[$sourcecmid] ?? null, $maps, $existing);
            }
        }
        template_course_completion_copier::copy($basecourseid, $courseid, $counterparts);
        course_modinfo::clear_instance_cache($courseid);
        rebuild_course_cache($courseid, true);
    }

    /**
     * What each table a setting refers to maps to in the new course.
     *
     * @param int $basecourseid
     * @param int $courseid
     * @param array<int,int> $counterparts Template cmid => new cmid.
     * @param array<int,int[]> $created Template cmid => created cmids.
     * @param \stdClass[] $sources The records of the template activities.
     * @param array $sections The pairs of sections.
     * @return array<string,array<int,int>>
     */
    private static function maps(
        int $basecourseid,
        int $courseid,
        array $counterparts,
        array $created,
        array $sources,
        array $sections
    ): array {
        $groups = [];
        $groupings = [];
        $restrictions = array_merge(
            array_map(static fn($source) => $source->availability, $sources),
            array_map(static fn($pair) => $pair['source']->availability, $sections)
        );
        foreach ($restrictions as $availability) {
            $used = template_availability_tree::group_ids($availability);
            $groups = array_merge($groups, $used['group']);
            $groupings = array_merge($groupings, $used['grouping']);
        }
        foreach ($sources as $source) {
            $groupings[] = (int) $source->groupingid;
        }
        return [
            'course_modules' => $counterparts,
            'grade_items' => template_counterparts::grade_items($basecourseid, $courseid, $created),
            'groups' => template_counterparts::groups('groups', $basecourseid, $courseid, array_filter($groups)),
            'groupings' => template_counterparts::groups('groupings', $basecourseid, $courseid, array_filter($groupings)),
        ];
    }

    /**
     * The activities created for each template activity.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @param array $keptcms
     * @return array<int,int[]> Template cmid => created cmids.
     */
    private static function created_by_source(array $payloadactivities, array $generatedcms, array $keptcms): array {
        $created = [];
        foreach ($keptcms as $sourcecmid => $cmid) {
            $created[(int) $sourcecmid][] = (int) $cmid;
        }
        foreach ($payloadactivities as $entry) {
            $behavior = $entry['template_behavior'] ?? [];
            $sourcecmid = (int) ($behavior['template_source_cmid'] ?? 0);
            $cmid = (int) ($generatedcms[(int) ($entry['cmid'] ?? 0)] ?? 0);
            if (($behavior['action'] ?? '') === 'instance' && $sourcecmid > 0 && $cmid > 0) {
                $created[$sourcecmid][] = $cmid;
            }
        }
        return $created;
    }

    /**
     * The sections of the template that the new course has too, by section number.
     *
     * @param int $basecourseid
     * @param int $courseid
     * @return array<int,array{source: \stdClass, target: \stdClass}>
     */
    private static function sections_by_number(int $basecourseid, int $courseid): array {
        global $DB;

        $columns = 'id, section, visible, availability';
        $bynumber = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid], '', $columns) as $target) {
            $bynumber[(int) $target->section] = $target;
        }
        $pairs = [];
        foreach ($DB->get_records('course_sections', ['course' => $basecourseid], 'section', $columns) as $source) {
            $number = (int) $source->section;
            if (isset($bynumber[$number])) {
                $pairs[$number] = ['source' => $source, 'target' => $bynumber[$number]];
            }
        }
        return $pairs;
    }

    /**
     * Give one section of the new course the visibility and the restrictions of its template section.
     *
     * @param int $courseid
     * @param array{source: \stdClass, target: \stdClass} $pair
     * @param array<string,array<int,int>> $maps
     * @param array<string,array<int,bool>> $existing
     */
    private static function apply_section(int $courseid, array $pair, array $maps, array $existing): void {
        global $DB;

        $availability = template_availability_tree::carry($pair['source']->availability, $maps, $existing);
        $DB->set_field('course_sections', 'availability', $availability, ['id' => $pair['target']->id]);
        if ((int) $pair['source']->visible !== (int) $pair['target']->visible) {
            set_section_visible($courseid, (int) $pair['target']->section, (int) $pair['source']->visible);
        }
    }

    /**
     * Give one activity the settings of its template activity.
     *
     * @param int $cmid The activity of the new course.
     * @param \stdClass|null $source The template activity's record.
     * @param array<string,array<int,int>> $maps
     * @param array<string,array<int,bool>> $existing
     */
    private static function apply_activity(int $cmid, ?\stdClass $source, array $maps, array $existing): void {
        global $DB;

        $target = $DB->get_record('course_modules', ['id' => $cmid]);
        if ($source === null || !$target || (int) $source->module !== (int) $target->module) {
            return;
        }
        set_coursemodule_visible($cmid, (int) $source->visible, (int) $source->visibleoncoursepage);
        $update = (object) ['id' => $cmid];
        $update->availability = template_availability_tree::carry($source->availability, $maps, $existing);
        $update->groupingid = $maps['groupings'][(int) $source->groupingid] ?? 0;
        foreach (self::ACTIVITY_COLUMNS as $column) {
            if (property_exists($source, $column)) {
                $update->{$column} = $source->{$column};
            }
        }
        $DB->update_record('course_modules', $update);
        self::copy_module_completion($source, $target);
        self::copy_tags((int) $source->id, $cmid);
    }

    /**
     * The completion rules of the module itself (the ones its table names completion...), as the template has them.
     *
     * @param \stdClass $source The template activity's record.
     * @param \stdClass $target The new activity's record.
     */
    private static function copy_module_completion(\stdClass $source, \stdClass $target): void {
        global $DB;

        $modname = $DB->get_field('modules', 'name', ['id' => $target->module]);
        $from = $DB->get_record($modname, ['id' => $source->instance]);
        if (!$from) {
            return;
        }
        $update = (object) ['id' => $target->instance];
        foreach (array_keys($DB->get_columns($modname)) as $column) {
            if (strpos($column, 'completion') === 0 && $column !== 'completionexpected' && property_exists($from, $column)) {
                $update->{$column} = $from->{$column};
            }
        }
        if (count((array) $update) > 1) {
            $DB->update_record($modname, $update);
        }
    }

    /**
     * The tags of the template activity are the ones of its counterpart.
     *
     * @param int $sourcecmid
     * @param int $cmid
     */
    private static function copy_tags(int $sourcecmid, int $cmid): void {
        $tags = \core_tag_tag::get_item_tags_array('core', 'course_modules', $sourcecmid);
        if ($tags) {
            \core_tag_tag::set_item_tags('core', 'course_modules', $cmid, \context_module::instance($cmid), array_values($tags));
        }
    }
}
