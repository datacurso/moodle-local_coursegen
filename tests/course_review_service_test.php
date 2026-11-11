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

use local_coursegen\local\service\course_review_service;

/**
 * Tests for the pieces of the course review step shared by both ways of creating a course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_review_service
 */
final class course_review_service_test extends \advanced_testcase {
    /**
     * Only what the teacher filled in becomes an override.
     *
     * @dataProvider overrides_provider
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @param array $expected
     */
    public function test_overrides_keep_only_what_was_filled_in(
        string $fullname,
        string $shortname,
        int $category,
        array $expected
    ): void {
        $this->assertSame($expected, course_review_service::overrides($fullname, $shortname, $category));
    }

    /**
     * What the review form can send.
     *
     * @return array
     */
    public static function overrides_provider(): array {
        return [
            'everything' => ['Algebra', 'ALG', 4, ['fullname' => 'Algebra', 'shortname' => 'ALG', 'category' => 4]],
            'nothing' => ['', '', 0, []],
            'whitespace only' => ['   ', "\t", 0, []],
            'trimmed' => ['  Algebra ', ' ALG ', 0, ['fullname' => 'Algebra', 'shortname' => 'ALG']],
            'only the category' => ['', '', 7, ['category' => 7]],
            'negative category' => ['Algebra', '', -1, ['fullname' => 'Algebra']],
        ];
    }

    /**
     * Every category the teacher can manage is listed with its full path.
     */
    public function test_available_categories_lists_the_manageable_ones_with_their_path(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->getDataGenerator()->create_category(['name' => 'Sciences']);
        $child = $this->getDataGenerator()->create_category(['name' => 'Maths', 'parent' => $parent->id]);

        $categories = course_review_service::available_categories();

        $byid = array_column($categories, 'pathname', 'id');
        $this->assertSame('Sciences', $byid[$parent->id]);
        $this->assertSame('Sciences / Maths', $byid[$child->id]);
        foreach ($categories as $category) {
            $this->assertIsInt($category['id']);
        }
    }

    /**
     * A user who manages no category is offered none.
     */
    public function test_available_categories_is_empty_for_a_user_who_manages_none(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], course_review_service::available_categories());
    }
}
