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

use local_coursegen\local\template\course_search;

/**
 * Tests for the search of the courses that can be the base of a template.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\course_search
 */
final class template_course_search_test extends \advanced_testcase {
    /**
     * Start with a clean site and an admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The ids of the courses found.
     *
     * @param int $categoryid Category to search in.
     * @param string $query Text to look for.
     * @return int[]
     */
    private function find_ids(int $categoryid, string $query): array {
        $found = course_search::find($categoryid, $query);

        return array_column($found, 'id');
    }

    /**
     * Searching everything lists the courses and never the front page.
     */
    public function test_lists_courses_and_never_the_front_page(): void {
        $course = $this->getDataGenerator()->create_course();

        $ids = $this->find_ids(0, '');

        $this->assertSame([(int) $course->id], $ids);
        $this->assertNotContains(SITEID, $ids);
    }

    /**
     * The text matches the full name, the short name and the id number without caring about case.
     */
    public function test_text_matches_names_and_id_number(): void {
        $generator = $this->getDataGenerator();
        $byname = $generator->create_course(['fullname' => 'Digital Marketing', 'shortname' => 'AAA']);
        $byshort = $generator->create_course(['fullname' => 'Other', 'shortname' => 'MARKETING-2']);
        $byid = $generator->create_course(['fullname' => 'Third', 'shortname' => 'BBB', 'idnumber' => 'mkt-77']);
        $generator->create_course(['fullname' => 'Cooking', 'shortname' => 'CCC']);

        $names = $this->find_ids(0, 'marketing');
        $idnumber = $this->find_ids(0, 'MKT-77');

        $this->assertEqualsCanonicalizing([(int) $byname->id, (int) $byshort->id], $names);
        $this->assertSame([(int) $byid->id], $idnumber);
    }

    /**
     * Characters that mean something in a search pattern are matched literally.
     */
    public function test_special_characters_are_matched_literally(): void {
        $generator = $this->getDataGenerator();
        $percent = $generator->create_course(['fullname' => '100% Online', 'shortname' => 'P1']);
        $generator->create_course(['fullname' => 'Plain course', 'shortname' => 'P2']);

        $percentids = $this->find_ids(0, '%');
        $underscoreids = $this->find_ids(0, '_');
        $quoteids = $this->find_ids(0, "'; DROP TABLE x; --");

        $this->assertSame([(int) $percent->id], $percentids);
        $this->assertSame([], $underscoreids);
        $this->assertSame([], $quoteids);
    }

    /**
     * A category limits the search to itself and its subcategories.
     */
    public function test_category_includes_subcategories(): void {
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $sibling = $generator->create_category();
        $inparent = $generator->create_course(['category' => $parent->id]);
        $inchild = $generator->create_course(['category' => $child->id]);
        $generator->create_course(['category' => $sibling->id]);

        $ids = $this->find_ids((int) $parent->id, '');

        $this->assertEqualsCanonicalizing([(int) $inparent->id, (int) $inchild->id], $ids);
    }

    /**
     * A category that does not exist finds nothing.
     */
    public function test_unknown_category_finds_nothing(): void {
        $this->getDataGenerator()->create_course();

        $value = $this->find_ids(99999, '');
        $this->assertSame([], $value);
    }

    /**
     * A search returns at most the limit of results, in name order.
     */
    public function test_results_are_limited_and_ordered(): void {
        $generator = $this->getDataGenerator();
        $total = course_search::MAX_RESULTS + 5;
        for ($index = 0; $index < $total; $index++) {
            $value2 = sprintf('Course %03d', $index);
            $value = ['fullname' => $value2, 'shortname' => 'C' . $index];
            $generator->create_course($value);
        }

        $found = course_search::find(0, '');

        $this->assertCount(course_search::MAX_RESULTS, $found);
        $this->assertSame('Course 000', $found[0]['fullname']);
        $this->assertSame('Course 001', $found[1]['fullname']);
    }
}
