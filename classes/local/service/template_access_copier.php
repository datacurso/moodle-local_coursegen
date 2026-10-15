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
 * Gives each activity of the new course the access its template activity has.
 *
 * An activity written by the AI is created from its description, and a kept one is copied, so neither carries
 * on its own whether the template shows it on the course page or what it asks to be opened. Once every
 * activity exists, each one that stands for an activity of the template takes from it whether it is visible,
 * whether it is shown on the course page and its access restrictions. A restriction on the completion of
 * another activity is pointed at that activity's counterpart in the new course; a restriction that cannot
 * be pointed at anything in it (a completion with no counterpart, a grade, a group) is left out rather than
 * left addressing the template's course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_access_copier {
    /** @var string[] The kinds of restriction that name something of the template's course. */
    private const COURSE_BOUND = ['grade', 'group', 'grouping'];

    /**
     * Copy the access of the template activities onto their counterparts of a course built from a template.
     *
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function copy_into(int $courseid, array $payloadactivities, array $generatedcms, array $keptcms): void {
        global $DB;

        $counterparts = kept_link_rewriter::counterparts($payloadactivities, $generatedcms, $keptcms);
        $created = self::created_by_source($payloadactivities, $generatedcms, $keptcms);
        if ($created === []) {
            return;
        }
        $columns = 'id, visible, visibleoncoursepage, availability';
        $sources = $DB->get_records_list('course_modules', 'id', array_keys($created), '', $columns);
        foreach ($created as $sourcecmid => $cmids) {
            if (!isset($sources[$sourcecmid])) {
                continue;
            }
            $source = $sources[$sourcecmid];
            $availability = self::remap_availability($source->availability, $counterparts);
            foreach ($cmids as $cmid) {
                self::apply((int) $cmid, $source, $availability);
            }
        }
        course_modinfo::clear_instance_cache($courseid);
        rebuild_course_cache($courseid, true);
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
     * Give one activity the visibility and the restrictions of its template activity.
     *
     * @param int $cmid The activity of the new course.
     * @param \stdClass $source The template activity's record.
     * @param string|null $availability Its restrictions, already pointed at the new course.
     */
    private static function apply(int $cmid, \stdClass $source, ?string $availability): void {
        global $DB;

        set_coursemodule_visible($cmid, (int) $source->visible, (int) $source->visibleoncoursepage);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
    }

    /**
     * The restrictions of a template activity, pointed at the activities of the new course.
     *
     * @param string|null $availability The restrictions as Moodle stores them (JSON), or empty for none.
     * @param array<int,int> $counterparts Template cmid => new course cmid.
     * @return string|null The restrictions of the new activity, or null when none is left.
     */
    public static function remap_availability(?string $availability, array $counterparts): ?string {
        $tree = json_decode((string) $availability, true);
        if (!is_array($tree) || empty($tree['c'])) {
            return null;
        }
        $kept = self::remap_set($tree, $counterparts);
        if ($kept === null) {
            return null;
        }
        return json_encode($kept);
    }

    /**
     * One set of conditions, with the ones that cannot be pointed at the new course left out.
     *
     * @param array $set A set: its operator, what it shows and its conditions or nested sets.
     * @param array<int,int> $counterparts
     * @return array|null The set, or null when none of its conditions is left.
     */
    private static function remap_set(array $set, array $counterparts): ?array {
        $children = [];
        $shown = [];
        foreach ($set['c'] as $index => $condition) {
            $mapped = self::remap_condition($condition, $counterparts);
            if ($mapped === null) {
                continue;
            }
            $children[] = $mapped;
            if (isset($set['showc'][$index])) {
                $shown[] = $set['showc'][$index];
            }
        }
        if ($children === []) {
            return null;
        }
        $set['c'] = $children;
        if (isset($set['showc'])) {
            $set['showc'] = $shown;
        }
        return $set;
    }

    /**
     * One condition, or one nested set, pointed at the new course.
     *
     * @param array $condition
     * @param array<int,int> $counterparts
     * @return array|null Null when it cannot be.
     */
    private static function remap_condition(array $condition, array $counterparts): ?array {
        if (isset($condition['c'])) {
            return self::remap_set($condition, $counterparts);
        }
        $type = $condition['type'] ?? '';
        if (in_array($type, self::COURSE_BOUND, true)) {
            return null;
        }
        if ($type === 'completion') {
            $target = $counterparts[(int) ($condition['cm'] ?? 0)] ?? null;
            if ($target === null) {
                return null;
            }
            $condition['cm'] = (int) $target;
        }
        return $condition;
    }
}
