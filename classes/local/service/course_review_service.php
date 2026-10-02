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
 * What the review step before course creation reads and sends.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\service;

use core_course_category;

/**
 * The pieces of the course review step that every way of creating a course shares.
 *
 * Creating a course with or without a template ends with the same form, where the
 * teacher reviews the proposed name, short name and category. What the form lists
 * and how its answer becomes the overrides of the creation live here once, so the
 * two modes cannot drift apart.
 */
class course_review_service {
    /**
     * The categories the teacher can place the new course in, with their full path.
     *
     * @return array[] Each entry holds 'id' and 'pathname'.
     */
    public static function available_categories(): array {
        $categories = [];
        $list = core_course_category::make_categories_list('moodle/category:manage');
        foreach ($list as $id => $pathname) {
            $categories[] = [
                'id' => (int) $id,
                'pathname' => $pathname,
            ];
        }
        return $categories;
    }

    /**
     * The overrides to create a course with, from what the review form sent.
     *
     * Only what the teacher filled in is an override; the rest stays as proposed.
     *
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @return array Keys fullname, shortname and category, each only when given.
     */
    public static function overrides(string $fullname, string $shortname, int $category): array {
        $overrides = [];
        $fullname = trim($fullname);
        if ($fullname !== '') {
            $overrides['fullname'] = $fullname;
        }
        $shortname = trim($shortname);
        if ($shortname !== '') {
            $overrides['shortname'] = $shortname;
        }
        if ($category > 0) {
            $overrides['category'] = $category;
        }
        return $overrides;
    }
}
