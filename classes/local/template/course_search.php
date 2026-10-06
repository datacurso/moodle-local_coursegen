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

namespace local_coursegen\local\template;

use context_course;

/**
 * Finds the courses an admin can choose as the base of a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_search {
    /** @var int Most courses one search returns. */
    public const MAX_RESULTS = 50;

    /** @var int Most rows read from the database before the capability filter. */
    private const MAX_CANDIDATES = 250;

    /**
     * Search courses by name, optionally inside a category and its subcategories.
     *
     * @param int $categoryid Category id, for example 3, or 0 for every category.
     * @param string $query Text the full name, short name or id number contains, for example "marketing".
     * @return array[] Entries with id, fullname and shortname, only of courses the user can see.
     */
    public static function find(int $categoryid, string $query): array {
        global $DB;

        $params = [];
        $conditions = ['c.id <> :siteid'];
        $params['siteid'] = SITEID;
        $categorycondition = self::category_condition($categoryid, $params);
        if ($categorycondition === null) {
            return [];
        }
        if ($categorycondition !== '') {
            $conditions[] = $categorycondition;
        }
        $textcondition = self::text_condition($query, $params);
        if ($textcondition !== '') {
            $conditions[] = $textcondition;
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT c.id, c.fullname, c.shortname
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE $where
              ORDER BY c.fullname ASC, c.id ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, self::MAX_CANDIDATES);

        return self::visible_entries($rows);
    }

    /**
     * The condition that limits the search to a category and its subcategories.
     *
     * @param int $categoryid Category id, or 0 for every category.
     * @param array $params Query parameters, which receive the ones of the condition.
     * @return string|null The condition, an empty string for no limit, or null when the category does not exist.
     */
    private static function category_condition(int $categoryid, array &$params): ?string {
        global $DB;

        if ($categoryid === 0) {
            return '';
        }

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false) {
            return null;
        }

        $escaped = $DB->sql_like_escape($path);
        $params['categoryid'] = $categoryid;
        $params['categorypath'] = $escaped . '/%';
        $likepath = $DB->sql_like('cc.path', ':categorypath');

        return '(cc.id = :categoryid OR ' . $likepath . ')';
    }

    /**
     * The condition that limits the search to the courses whose names contain a text.
     *
     * @param string $query Text to look for.
     * @param array $params Query parameters, which receive the ones of the condition.
     * @return string The condition, or an empty string when there is no text.
     */
    private static function text_condition(string $query, array &$params): string {
        global $DB;

        $trimmed = trim($query);
        if ($trimmed === '') {
            return '';
        }

        $escaped = $DB->sql_like_escape($trimmed);
        $params['textfull'] = '%' . $escaped . '%';
        $params['textshort'] = '%' . $escaped . '%';
        $params['textid'] = '%' . $escaped . '%';
        $fullname = $DB->sql_like('c.fullname', ':textfull', false);
        $shortname = $DB->sql_like('c.shortname', ':textshort', false);
        $idnumber = $DB->sql_like('c.idnumber', ':textid', false);

        return '(' . $fullname . ' OR ' . $shortname . ' OR ' . $idnumber . ')';
    }

    /**
     * Keep the courses the user can see, up to the limit of one search.
     *
     * @param \stdClass[] $rows Candidate courses.
     * @return array[]
     */
    private static function visible_entries(array $rows): array {
        $entries = [];
        foreach ($rows as $row) {
            $context = context_course::instance($row->id);
            if (!has_capability('moodle/course:view', $context)) {
                continue;
            }
            $options = ['context' => $context];
            $fullname = format_string($row->fullname, true, $options);
            $shortname = format_string($row->shortname, true, $options);
            $entries[] = [
                'id' => (int) $row->id,
                'fullname' => $fullname,
                'shortname' => $shortname,
            ];
            if (count($entries) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $entries;
    }
}
