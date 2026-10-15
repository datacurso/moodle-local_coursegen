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

use core_availability\tree;
use course_modinfo;

/**
 * Gives each activity and section of the new course the access its template counterpart has.
 *
 * An activity written by the AI is created from its description and a kept one is copied, so neither carries on
 * its own whether the template hides it, shows it on the course page or restricts its access, and the sections
 * of the new course are created empty. Once everything exists, each activity and section that stands for one of
 * the template takes from it its visibility and its whole tree of access restrictions.
 *
 * The restrictions are handled through Moodle's own availability tree, so every kind of condition (and every
 * one a plugin adds) is carried as Moodle carries it when a course is restored: the ids a condition holds are
 * pointed at the counterpart in the new course through the condition's own update_dependency_id. The ids the
 * template's course owns are given a counterpart: an activity maps to its copy, a grade item to the one of the
 * copy, and a group or grouping a restriction uses is created in the new course with the same name. A reference
 * that still has no counterpart takes the value Moodle's restore gives it (a module or grade item 0, a group
 * or grouping -1), so the restriction is shown as broken and nobody is let in by mistake.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_access_copier {
    /** @var int Added to an id while it changes, so a new id never meets an old one that is still waiting. */
    private const TEMPORARY = 1000000000;

    /** @var string[] The tables a condition may refer to, which the template's course owns. */
    private const TABLES = ['course_modules', 'grade_items', 'groups', 'groupings'];

    /**
     * Copy the access of the template onto its counterparts in a course built from a template.
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

        $sources = [];
        if ($created !== []) {
            $columns = 'id, visible, visibleoncoursepage, availability';
            $sources = $DB->get_records_list('course_modules', 'id', array_keys($created), '', $columns);
        }
        $availabilities = [];
        foreach ($sources as $source) {
            $availabilities[] = $source->availability;
        }
        foreach ($sections as $pair) {
            $availabilities[] = $pair['source']->availability;
        }
        $maps = ['course_modules' => $counterparts];
        $maps['grade_items'] = self::grade_item_counterparts($basecourseid, $courseid, $created);
        [$maps['groups'], $maps['groupings']] = self::group_counterparts($basecourseid, $courseid, $availabilities);
        $existing = self::existing_ids($courseid);

        // Sections first: hiding one hides its activities, and each activity then takes its own state.
        foreach ($sections as $pair) {
            self::apply_section($courseid, $pair, $maps, $existing);
        }
        foreach ($created as $sourcecmid => $cmids) {
            if (!isset($sources[$sourcecmid])) {
                continue;
            }
            $availability = self::carry_availability($sources[$sourcecmid]->availability, $maps, $existing);
            foreach ($cmids as $cmid) {
                self::apply_activity((int) $cmid, $sources[$sourcecmid], $availability);
            }
        }
        course_modinfo::clear_instance_cache($courseid);
        rebuild_course_cache($courseid, true);
    }

    /**
     * The restrictions of a template item, pointed at the new course.
     *
     * @param string|null $availability The restrictions as Moodle stores them (JSON), or empty for none.
     * @param array<string,array<int,int>> $maps For each table a condition may refer to: template id => new id.
     * @param array<string,array<int,bool>> $existing For each of those tables, the ids the new course owns.
     * @return string|null The restrictions of the new item, or null when there is none.
     */
    public static function carry_availability(?string $availability, array $maps, array $existing): ?string {
        $structure = json_decode((string) $availability);
        if (!is_object($structure) || empty($structure->c)) {
            return null;
        }
        try {
            $tree = new tree($structure);
        } catch (\Throwable $error) {
            // A condition of a plugin this site no longer has: Moodle keeps it as it is, and so does this.
            return $availability;
        }
        foreach (self::TABLES as $table) {
            self::point_at_new_ids($tree, $table, $maps[$table] ?? []);
        }
        $saved = json_decode(json_encode($tree->save()));
        self::mark_broken($saved, $existing);
        return json_encode($saved);
    }

    /**
     * Change every id of a table in a tree to its counterpart, through a temporary value.
     *
     * @param tree $tree
     * @param string $table
     * @param array<int,int> $map Template id => new id.
     */
    private static function point_at_new_ids(tree $tree, string $table, array $map): void {
        foreach ($map as $old => $new) {
            $tree->update_dependency_id($table, (int) $old, -(self::TEMPORARY + (int) $new));
        }
        foreach ($map as $new) {
            $tree->update_dependency_id($table, -(self::TEMPORARY + (int) $new), (int) $new);
        }
    }

    /**
     * Give what still names something of the template's course the value Moodle's restore gives it.
     *
     * @param \stdClass $node A set of conditions, or one condition, read from the saved tree.
     * @param array<string,array<int,bool>> $existing
     */
    private static function mark_broken(\stdClass $node, array $existing): void {
        foreach ($node->c ?? [] as $child) {
            self::mark_broken($child, $existing);
        }
        $type = $node->type ?? '';
        if ($type === 'completion' && ($node->cm ?? 0) > 0 && !isset($existing['course_modules'][(int) $node->cm])) {
            $node->cm = 0;
        }
        if ($type === 'grade' && ($node->id ?? 0) > 0 && !isset($existing['grade_items'][(int) $node->id])) {
            $node->id = 0;
        }
        if ($type === 'group' && ($node->id ?? 0) > 0 && !isset($existing['groups'][(int) $node->id])) {
            $node->id = -1;
        }
        if ($type === 'grouping' && ($node->id ?? 0) > 0 && !isset($existing['groupings'][(int) $node->id])) {
            $node->id = -1;
        }
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
     * The grade items of the new course that stand for those of the template.
     *
     * An activity's own items map one to one by their number, and the course total to the course total.
     *
     * @param int $basecourseid
     * @param int $courseid
     * @param array<int,int[]> $created Template cmid => created cmids.
     * @return array<int,int> Template grade item id => new grade item id.
     */
    private static function grade_item_counterparts(int $basecourseid, int $courseid, array $created): array {
        global $DB;

        $map = [];
        $sourcetotal = $DB->get_field('grade_items', 'id', ['courseid' => $basecourseid, 'itemtype' => 'course']);
        $targettotal = $DB->get_field('grade_items', 'id', ['courseid' => $courseid, 'itemtype' => 'course']);
        if ($sourcetotal && $targettotal) {
            $map[(int) $sourcetotal] = (int) $targettotal;
        }
        $basemodinfo = get_fast_modinfo($basecourseid, -1);
        $targetmodinfo = get_fast_modinfo($courseid, -1);
        foreach ($created as $sourcecmid => $cmids) {
            $source = $basemodinfo->cms[$sourcecmid] ?? null;
            $target = $targetmodinfo->cms[reset($cmids)] ?? null;
            if ($source === null || $target === null || $source->modname !== $target->modname) {
                continue;
            }
            $where = [
                'courseid' => $basecourseid,
                'itemtype' => 'mod',
                'itemmodule' => $source->modname,
                'iteminstance' => $source->instance,
            ];
            foreach ($DB->get_records('grade_items', $where, '', 'id, itemnumber') as $item) {
                $newid = $DB->get_field('grade_items', 'id', [
                    'courseid' => $courseid,
                    'itemtype' => 'mod',
                    'itemmodule' => $target->modname,
                    'iteminstance' => $target->instance,
                    'itemnumber' => $item->itemnumber,
                ]);
                if ($newid) {
                    $map[(int) $item->id] = (int) $newid;
                }
            }
        }
        return $map;
    }

    /**
     * The groups and groupings a restriction uses, created in the new course with the same name.
     *
     * @param int $basecourseid
     * @param int $courseid
     * @param array<int,string|null> $availabilities Every restriction that is going to be copied.
     * @return array{0: array<int,int>, 1: array<int,int>} Template group ids => new ones, and the same for groupings.
     */
    private static function group_counterparts(int $basecourseid, int $courseid, array $availabilities): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $used = ['group' => [], 'grouping' => []];
        foreach ($availabilities as $availability) {
            self::collect_group_ids(json_decode((string) $availability), $used);
        }
        $groups = [];
        foreach (array_keys($used['group']) as $id) {
            $source = $DB->get_record('groups', ['id' => $id, 'courseid' => $basecourseid]);
            if ($source) {
                $groups[$id] = groups_create_group((object) [
                    'courseid' => $courseid,
                    'name' => $source->name,
                    'description' => $source->description,
                    'descriptionformat' => $source->descriptionformat,
                ]);
            }
        }
        $groupings = [];
        foreach (array_keys($used['grouping']) as $id) {
            $source = $DB->get_record('groupings', ['id' => $id, 'courseid' => $basecourseid]);
            if ($source) {
                $groupings[$id] = groups_create_grouping((object) [
                    'courseid' => $courseid,
                    'name' => $source->name,
                    'description' => $source->description,
                    'descriptionformat' => $source->descriptionformat,
                ]);
            }
        }
        return [$groups, $groupings];
    }

    /**
     * Add to $used the ids of the groups and groupings a tree of conditions names.
     *
     * @param mixed $node
     * @param array $used
     */
    private static function collect_group_ids($node, array &$used): void {
        if (!is_object($node)) {
            return;
        }
        foreach ($node->c ?? [] as $child) {
            self::collect_group_ids($child, $used);
        }
        $type = $node->type ?? '';
        if (($type === 'group' || $type === 'grouping') && ($node->id ?? 0) > 0) {
            $used[$type][(int) $node->id] = true;
        }
    }

    /**
     * The ids the new course owns, for the tables a restriction may refer to.
     *
     * @param int $courseid
     * @return array<string,array<int,bool>>
     */
    private static function existing_ids(int $courseid): array {
        global $DB;

        $columns = ['course_modules' => 'course', 'grade_items' => 'courseid', 'groups' => 'courseid',
            'groupings' => 'courseid'];
        $ids = [];
        foreach ($columns as $table => $column) {
            $ids[$table] = array_fill_keys(array_keys($DB->get_records($table, [$column => $courseid], '', 'id')), true);
        }
        return $ids;
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

        $source = $pair['source'];
        $target = $pair['target'];
        $availability = self::carry_availability($source->availability, $maps, $existing);
        $DB->set_field('course_sections', 'availability', $availability, ['id' => $target->id]);
        if ((int) $source->visible !== (int) $target->visible) {
            set_section_visible($courseid, (int) $target->section, (int) $source->visible);
        }
    }

    /**
     * Give one activity the visibility and the restrictions of its template activity.
     *
     * @param int $cmid The activity of the new course.
     * @param \stdClass $source The template activity's record.
     * @param string|null $availability Its restrictions, already pointed at the new course.
     */
    private static function apply_activity(int $cmid, \stdClass $source, ?string $availability): void {
        global $DB;

        set_coursemodule_visible($cmid, (int) $source->visible, (int) $source->visibleoncoursepage);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
    }
}
