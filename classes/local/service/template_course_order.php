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
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_instance;

/**
 * Puts the activities of a course built from a template in the order the template shows them.
 *
 * The professor reads each section of the template as its real activities with
 * the generated ones placed right after the activity they are anchored to. The
 * course is built with the generated activities first and the copied kept ones
 * after them, so once both exist each section is laid out again following
 * the rows of the template (template_instance_layout). An activity of the
 * new course that no row accounts for stays after the ones that do, in the
 * order it already had.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_course_order {
    /**
     * Order the sections of a course built from a template result.
     *
     * @param int $templateid
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function apply(
        int $templateid,
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        global $DB;

        $template = template::get_record(['id' => $templateid]);
        if (!$template || !$DB->record_exists('course', ['id' => $template->get('courseid')])) {
            return;
        }
        $basemodinfo = get_fast_modinfo($template->get('courseid'));
        $createdbyuid = link_targets::build($payloadactivities, $generatedcms, $keptcms);
        $sectionnumbers = self::section_numbers($basemodinfo);
        $instances = self::instances_by_section($templateid, $sectionnumbers);

        course_modinfo::clear_instance_cache($courseid);
        $targetmodinfo = get_fast_modinfo($courseid);
        $neworders = self::new_orders($basemodinfo, $instances, $createdbyuid, $keptcms);
        self::store_orders($targetmodinfo, $neworders);
        rebuild_course_cache($courseid, true);
    }

    /**
     * The number of each section of the base course, by section id.
     *
     * @param course_modinfo $modinfo
     * @return array<int,int>
     */
    private static function section_numbers(course_modinfo $modinfo): array {
        $numbers = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $numbers[(int) $section->id] = (int) $section->section;
        }
        return $numbers;
    }

    /**
     * The saved virtual rows of a template, by the number of their section.
     *
     * @param int $templateid
     * @param array<int,int> $sectionnumbers
     * @return array<int,template_instance[]>
     */
    private static function instances_by_section(int $templateid, array $sectionnumbers): array {
        $bysection = [];
        foreach (template_instance::get_records(['templateid' => $templateid]) as $instance) {
            $number = $sectionnumbers[(int) $instance->get('sectionid')] ?? null;
            if ($number === null) {
                continue;
            }
            $bysection[$number][] = $instance;
        }
        return $bysection;
    }

    /**
     * The wanted order of every section, as the cmids of the new course.
     *
     * @param course_modinfo $basemodinfo
     * @param array<int,template_instance[]> $instances
     * @param array<string,int> $createdbyuid
     * @param array $keptcms
     * @return array<int,int[]> Section number => cmids in the wanted order.
     */
    private static function new_orders(course_modinfo $basemodinfo, array $instances, array $createdbyuid, array $keptcms): array {
        $orders = [];
        foreach ($basemodinfo->get_section_info_all() as $section) {
            $number = (int) $section->section;
            $realcmids = $basemodinfo->sections[$number] ?? [];
            $rows = template_instance_layout::ordered_rows($realcmids, $instances[$number] ?? []);
            $orders[$number] = self::created_cmids($rows, $createdbyuid, $keptcms);
        }
        return $orders;
    }

    /**
     * The cmids of the new course that the rows of a section stand for, in row order.
     *
     * A row with no counterpart (an excluded or template activity of the base course,
     * or a generated one that was not created) is left out.
     *
     * @param array $rows From template_instance_layout::ordered_rows().
     * @param array<string,int> $createdbyuid
     * @param array $keptcms
     * @return int[]
     */
    private static function created_cmids(array $rows, array $createdbyuid, array $keptcms): array {
        $cmids = [];
        foreach ($rows as $row) {
            $cmid = self::row_cmid($row, $createdbyuid, $keptcms);
            if ($cmid !== null) {
                $cmids[] = $cmid;
            }
        }
        return $cmids;
    }

    /**
     * The cmid of the new course that one row stands for, or null when there is none.
     *
     * @param array $row
     * @param array<string,int> $createdbyuid
     * @param array $keptcms
     * @return int|null
     */
    private static function row_cmid(array $row, array $createdbyuid, array $keptcms): ?int {
        if ($row['type'] === template_instance_layout::TYPE_INSTANCE) {
            $uid = (string) $row['record']->get('uid');
            return $createdbyuid[$uid] ?? null;
        }
        if ($row['type'] === template_instance_layout::TYPE_REAL) {
            return $keptcms[(int) $row['cmid']] ?? null;
        }
        return null;
    }

    /**
     * Store the wanted order of each section of the new course.
     *
     * @param course_modinfo $targetmodinfo
     * @param array<int,int[]> $orders Section number => cmids in the wanted order.
     */
    private static function store_orders(course_modinfo $targetmodinfo, array $orders): void {
        global $DB;

        foreach ($orders as $number => $wanted) {
            $section = $targetmodinfo->get_section_info($number);
            if ($section === null) {
                continue;
            }
            $existing = $targetmodinfo->sections[$number] ?? [];
            $sequence = self::sequence($wanted, $existing);
            $DB->set_field('course_sections', 'sequence', implode(',', $sequence), ['id' => $section->id]);
        }
    }

    /**
     * The wanted cmids that are in the section, then the other ones in the order they had.
     *
     * @param int[] $wanted
     * @param int[] $existing
     * @return int[]
     */
    private static function sequence(array $wanted, array $existing): array {
        $present = array_values(array_intersect(array_unique($wanted), $existing));
        $others = array_values(array_diff($existing, $present));
        return array_merge($present, $others);
    }
}
