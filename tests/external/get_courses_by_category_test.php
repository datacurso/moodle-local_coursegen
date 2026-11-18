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

namespace local_coursegen;

use local_coursegen\external\get_courses_by_category;

/**
 * get_courses_by_category's "recursive" flag: the base-course picker's
 * course field (amd/src/local/template/form_course_selector.js) now always
 * passes recursive=false, so selecting a category only searches its own
 * courses, not every course under its subcategories — reproduces and locks
 * the fix for the bug where a category showing "(courses: 1)" still listed
 * courses that actually lived in a subcategory.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_courses_by_category
 *
 * @runTestsInSeparateProcesses
 */
final class get_courses_by_category_test extends \advanced_testcase {
    /**
     * A parent category with one course of its own and a subcategory with
     * two more: recursive=false must return only the parent's own course.
     */
    public function test_non_recursive_returns_only_direct_category_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Parent category']);
        $child = $generator->create_category(['name' => 'Child category', 'parent' => $parent->id]);
        $direct = $generator->create_course(['fullname' => 'Direct course', 'category' => $parent->id]);
        $generator->create_course(['fullname' => 'Nested course A', 'category' => $child->id]);
        $generator->create_course(['fullname' => 'Nested course B', 'category' => $child->id]);

        $result = get_courses_by_category::execute($parent->id, false, '');

        $this->assertCount(1, $result);
        $this->assertSame((int) $direct->id, $result[0]['id']);
    }

    /**
     * The same fixture with recursive=true still reaches the subcategory's
     * courses too — the parameter stays available for callers that
     * genuinely want the wider search.
     */
    public function test_recursive_returns_subcategory_courses_too(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Parent category']);
        $child = $generator->create_category(['name' => 'Child category', 'parent' => $parent->id]);
        $generator->create_course(['fullname' => 'Direct course', 'category' => $parent->id]);
        $generator->create_course(['fullname' => 'Nested course A', 'category' => $child->id]);
        $generator->create_course(['fullname' => 'Nested course B', 'category' => $child->id]);

        $result = get_courses_by_category::execute($parent->id, true, '');

        $this->assertCount(3, $result);
    }

    /**
     * A category with no courses of its own, but with a populated
     * subcategory, returns nothing under recursive=false — this is exactly
     * the "Course templates v2 (courses: 1)" scenario the admin hit: the
     * category badge already counts only direct courses, so the course list
     * must match that same scope.
     */
    public function test_non_recursive_returns_empty_when_category_has_no_direct_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Empty parent']);
        $child = $generator->create_category(['name' => 'Populated child', 'parent' => $parent->id]);
        $generator->create_course(['category' => $child->id]);

        $result = get_courses_by_category::execute($parent->id, false, '');

        $this->assertSame([], $result);
    }

    /**
     * The free-text query filters case-insensitively against fullname or
     * shortname, scoped to the searched category only.
     */
    public function test_query_filters_by_fullname_or_shortname(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $generator->create_course([
            'fullname' => 'Redaccion para medios',
            'shortname' => 'RED-MED-1',
            'category' => $category->id,
        ]);
        $generator->create_course([
            'fullname' => 'Otro curso',
            'shortname' => 'OTR-1',
            'category' => $category->id,
        ]);

        $byfullname = get_courses_by_category::execute($category->id, false, 'redaccion');
        $this->assertCount(1, $byfullname);
        $this->assertSame('RED-MED-1', $byfullname[0]['shortname']);

        $byshortname = get_courses_by_category::execute($category->id, false, 'OTR-1');
        $this->assertCount(1, $byshortname);
        $this->assertSame('Otro curso', $byshortname[0]['fullname']);

        $nomatch = get_courses_by_category::execute($category->id, false, 'nothing-matches-this');
        $this->assertSame([], $nomatch);
    }

    /**
     * A non-existent category id under recursive=true must not error: the
     * category lookup is IGNORE_MISSING, so the child list collapses to
     * none and the search degrades to that (non-existent) id alone.
     */
    public function test_recursive_with_nonexistent_category_does_not_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_courses_by_category::execute(999999, true, '');

        $this->assertSame([], $result);
    }

    /**
     * A user without local/coursegen:managetemplates on the system context
     * is refused, regardless of the recursive flag.
     */
    public function test_requires_managetemplates_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $generator->create_course(['category' => $category->id]);

        $user = $generator->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_courses_by_category::execute($category->id, false, '');
    }
}
