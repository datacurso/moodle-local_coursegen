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
use local_coursegen\local\template\template_repository;

/**
 * Puts the activities of a course built from a template in the order the template shows them.
 *
 * The course is built with the generated activities first and the copied kept ones
 * after them, so once both exist each section is laid out again following the
 * activities of the template's course. An activity of the new course that no
 * activity of the template accounts for stays after the ones that do, in the
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
     * @param array $generatedcms Base course cmid => created cmid, for the activities the AI wrote.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function apply(int $templateid, int $courseid, array $generatedcms, array $keptcms): void {
        global $DB;

        $repository = new template_repository();
        $template = $repository->find($templateid);
        if ($template === null || !$DB->record_exists('course', ['id' => $template->courseid])) {
            return;
        }
        $basemodinfo = get_fast_modinfo($template->courseid);

        course_modinfo::clear_instance_cache($courseid);
        $targetmodinfo = get_fast_modinfo($courseid);
        $neworders = self::new_orders($basemodinfo, $generatedcms, $keptcms);
        self::store_orders($targetmodinfo, $neworders);
        rebuild_course_cache($courseid, true);
    }

    /**
     * The wanted order of every section, as the cmids of the new course.
     *
     * @param course_modinfo $basemodinfo
     * @param array $generatedcms Base course cmid => created cmid, for the activities the AI wrote.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     * @return array<int,int[]> Section number => cmids in the wanted order.
     */
    private static function new_orders(course_modinfo $basemodinfo, array $generatedcms, array $keptcms): array {
        $orders = [];
        foreach ($basemodinfo->get_section_info_all() as $section) {
            $number = (int) $section->section;
            $basecmids = $basemodinfo->sections[$number] ?? [];
            $orders[$number] = self::created_cmids($basecmids, $generatedcms, $keptcms);
        }
        return $orders;
    }

    /**
     * The cmids of the new course that the activities of a template section stand for, in the template's order.
     *
     * An activity with no counterpart, such as one the AI did not write, is left out.
     *
     * @param int[] $basecmids Course modules of the template's section, in order.
     * @param array $generatedcms Base course cmid => created cmid, for the activities the AI wrote.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     * @return int[]
     */
    private static function created_cmids(array $basecmids, array $generatedcms, array $keptcms): array {
        $cmids = [];
        foreach ($basecmids as $basecmid) {
            $created = $keptcms[$basecmid] ?? null;
            if ($created === null) {
                $created = $generatedcms[$basecmid] ?? null;
            }
            if ($created !== null) {
                $cmids[] = (int) $created;
            }
        }
        return $cmids;
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
