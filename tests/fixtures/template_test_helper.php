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

/**
 * Helpers shared by the template tests: a course with known activities and a quick save.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait template_test_helper {
    /**
     * Create a course with a page, a hidden quiz and a label in its first section.
     *
     * @return array The course and the ids of its activities.
     */
    private function make_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Welcome page', 'section' => 1]);
        $quizoptions = ['course' => $course->id, 'name' => 'Hidden quiz', 'section' => 1, 'visible' => 0];
        $quiz = $generator->create_module('quiz', $quizoptions);
        $label = $generator->create_module('label', ['course' => $course->id, 'intro' => 'A label', 'section' => 2]);

        return [$course, (int) $page->cmid, (int) $quiz->cmid, (int) $label->cmid];
    }

    /**
     * Save a template for a course with the given items.
     *
     * @param \stdClass $course Course of the template.
     * @param array $items Entries with cmid, action and instruction.
     * @param int $templateid Template to replace, or 0.
     * @return int Template id.
     */
    private function save_items(\stdClass $course, array $items, int $templateid = 0): int {
        return $this->service->save($templateid, (int) $course->id, 'Marketing', 'Base course', $items, 2);
    }
}
