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

use local_coursegen\local\reference\reference_leftover_guard;

/**
 * No temporary reference is left in a built course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_leftover_guard
 */
final class reference_leftover_guard_test extends \advanced_testcase {
    /**
     * A course whose page holds the given content.
     *
     * @param string $content
     * @return array{0: \stdClass, 1: \stdClass} The course and the page.
     */
    private function course_with_page(string $content): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Unit one',
            'content' => $content,
        ]);
        return [$course, $page];
    }

    /**
     * A page that holds its own copy of the file passes.
     */
    public function test_a_page_with_its_own_file_passes(): void {
        $this->resetAfterTest(true);
        [$course, $page] = $this->course_with_page('<iframe src="@@PLUGINFILE@@/guide.pdf"></iframe>');

        reference_leftover_guard::ensure_none_left((int) $course->id, [(int) $page->cmid]);

        $this->addToAssertionCount(1);
    }

    /**
     * A page that still holds a token is refused, naming the activity.
     */
    public function test_a_page_with_a_token_is_refused(): void {
        $this->resetAfterTest(true);
        [$course, $page] = $this->course_with_page('<iframe src="$@COURSEGENFILE*uid.1@$"></iframe>');

        try {
            reference_leftover_guard::ensure_none_left((int) $course->id, [(int) $page->cmid]);
            $this->fail('A token was left in the course.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referenceleftover', $exception->errorcode);
            $this->assertStringContainsString('Unit one', $exception->getMessage());
        }
    }

    /**
     * A page that still points at the temporary file is refused.
     */
    public function test_a_page_pointing_at_the_temporary_file_is_refused(): void {
        $this->resetAfterTest(true);
        $content = '<iframe src="https://example.com/pluginfile.php/5/local_coursegen/referencefile/55/12.1/guide.pdf"></iframe>';
        [$course, $page] = $this->course_with_page($content);

        $this->expectException(\moodle_exception::class);

        reference_leftover_guard::ensure_none_left((int) $course->id, [(int) $page->cmid]);
    }

    /**
     * Only the activities the run wrote are read.
     */
    public function test_activities_the_run_did_not_write_are_not_read(): void {
        $this->resetAfterTest(true);
        [$course, $page] = $this->course_with_page('<iframe src="$@COURSEGENFILE*uid.1@$"></iframe>');

        reference_leftover_guard::ensure_none_left((int) $course->id, []);

        $this->addToAssertionCount(1);
    }

    /**
     * A module that is not searched is skipped.
     */
    public function test_a_module_that_is_not_searched_is_skipped(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        reference_leftover_guard::ensure_none_left((int) $course->id, [(int) $forum->cmid]);

        $this->addToAssertionCount(1);
    }
}
