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
 * What the new course has that stands for each thing the template's course owns that a setting refers to.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_counterparts {
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
    public static function grade_items(int $basecourseid, int $courseid, array $created): array {
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
     * The groups or the groupings of the template that are used, created in the new course with the same name.
     *
     * @param string $table 'groups' or 'groupings'.
     * @param int $basecourseid
     * @param int $courseid
     * @param int[] $ids The ids of the template's course that are used.
     * @return array<int,int> Template id => new id.
     */
    public static function groups(string $table, int $basecourseid, int $courseid, array $ids): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $map = [];
        foreach (array_unique($ids) as $id) {
            $source = $DB->get_record($table, ['id' => $id, 'courseid' => $basecourseid]);
            if (!$source) {
                continue;
            }
            $data = (object) [
                'courseid' => $courseid,
                'name' => $source->name,
                'description' => $source->description,
                'descriptionformat' => $source->descriptionformat,
            ];
            $map[(int) $id] = $table === 'groups' ? groups_create_group($data) : groups_create_grouping($data);
        }
        return $map;
    }

    /**
     * The ids the new course owns, for the tables a restriction may refer to.
     *
     * @param int $courseid
     * @return array<string,array<int,bool>>
     */
    public static function existing_ids(int $courseid): array {
        global $DB;

        $columns = ['course_modules' => 'course', 'grade_items' => 'courseid', 'groups' => 'courseid',
            'groupings' => 'courseid'];
        $ids = [];
        foreach ($columns as $table => $column) {
            $ids[$table] = array_fill_keys(array_keys($DB->get_records($table, [$column => $courseid], '', 'id')), true);
        }
        return $ids;
    }
}
