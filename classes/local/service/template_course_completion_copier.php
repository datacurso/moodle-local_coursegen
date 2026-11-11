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

/**
 * What the template's course sets for the course as a whole around its activities, carried onto the new course.
 *
 * - The criteria that complete the course and how they are added up. A criterion on an activity points at the
 *   counterpart of that activity, and one without a counterpart is left out. The criterion on a date is left out.
 * - The competencies of the course and the ones each activity is linked to.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_course_completion_copier {
    /** @var int The criterion that completes the course on a date (completion_criteria::COMPLETION_CRITERIA_TYPE_DATE). */
    private const TYPE_DATE = 2;

    /** @var int The criterion on an activity (COMPLETION_CRITERIA_TYPE_ACTIVITY). */
    private const TYPE_ACTIVITY = 4;

    /**
     * Copy the completion criteria, their aggregation and the competencies onto the new course.
     *
     * @param int $basecourseid The template's course.
     * @param int $courseid The new course.
     * @param array<int,int> $counterparts Template cmid => new course cmid.
     */
    public static function copy(int $basecourseid, int $courseid, array $counterparts): void {
        self::copy_criteria($basecourseid, $courseid, $counterparts);
        self::copy_aggregation($basecourseid, $courseid);
        self::copy_course_competencies($basecourseid, $courseid);
        self::copy_module_competencies($counterparts);
    }

    /**
     * The criteria of the template, except the one on a date, replace the ones of the new course.
     *
     * @param int $basecourseid
     * @param int $courseid
     * @param array<int,int> $counterparts
     */
    private static function copy_criteria(int $basecourseid, int $courseid, array $counterparts): void {
        global $DB;

        $rows = $DB->get_records('course_completion_criteria', ['course' => $basecourseid], 'id');
        if ($rows === []) {
            return;
        }
        $DB->delete_records_select('course_completion_criteria', 'course = ? AND criteriatype <> ?', [$courseid, self::TYPE_DATE]);
        foreach ($rows as $row) {
            if ((int) $row->criteriatype === self::TYPE_DATE) {
                continue;
            }
            if ((int) $row->criteriatype === self::TYPE_ACTIVITY) {
                $row->moduleinstance = $counterparts[(int) $row->moduleinstance] ?? 0;
                if ($row->moduleinstance === 0) {
                    continue;
                }
            }
            unset($row->id);
            $row->course = $courseid;
            $DB->insert_record('course_completion_criteria', $row);
        }
    }

    /**
     * How the criteria are added up, as in the template.
     *
     * @param int $basecourseid
     * @param int $courseid
     */
    private static function copy_aggregation(int $basecourseid, int $courseid): void {
        global $DB;

        $rows = $DB->get_records('course_completion_aggr_methd', ['course' => $basecourseid]);
        if ($rows === []) {
            return;
        }
        $DB->delete_records('course_completion_aggr_methd', ['course' => $courseid]);
        foreach ($rows as $row) {
            unset($row->id);
            $row->course = $courseid;
            $DB->insert_record('course_completion_aggr_methd', $row);
        }
    }

    /**
     * The competencies of the template's course are the ones of the new course.
     *
     * @param int $basecourseid
     * @param int $courseid
     */
    private static function copy_course_competencies(int $basecourseid, int $courseid): void {
        global $DB;

        foreach ($DB->get_records('competency_coursecomp', ['courseid' => $basecourseid], 'sortorder') as $row) {
            $where = ['courseid' => $courseid, 'competencyid' => $row->competencyid];
            if ($DB->record_exists('competency_coursecomp', $where)) {
                continue;
            }
            unset($row->id);
            $row->courseid = $courseid;
            $DB->insert_record('competency_coursecomp', $row);
        }
    }

    /**
     * The competencies each template activity is linked to are linked to its counterpart.
     *
     * @param array<int,int> $counterparts
     */
    private static function copy_module_competencies(array $counterparts): void {
        global $DB;

        foreach ($counterparts as $sourcecmid => $cmid) {
            foreach ($DB->get_records('competency_modulecomp', ['cmid' => $sourcecmid], 'sortorder') as $row) {
                if ($DB->record_exists('competency_modulecomp', ['cmid' => $cmid, 'competencyid' => $row->competencyid])) {
                    continue;
                }
                unset($row->id);
                $row->cmid = $cmid;
                $DB->insert_record('competency_modulecomp', $row);
            }
        }
    }
}
