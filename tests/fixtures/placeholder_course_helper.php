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

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\local\placeholder\course_template_creator;
use local_coursegen\local\placeholder\template_creation_exception;

/**
 * What the tests that make a template from a course need: courses with placeholders and readers of what was saved.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait placeholder_course_helper {
    /**
     * A page body with one placeholder.
     *
     * @return string
     */
    private static function marker_text(): string {
        return '<p>[[coursegen:aiprompt: write the intro]]</p>';
    }

    /**
     * A course with pages.
     *
     * @param array $pages Page bodies by name, e.g. ['Guide' => '<p>[[coursegen:aiprompt: x]]</p>'].
     * @param array $courseoptions Course fields to set.
     * @return \stdClass The course.
     */
    private function course_with_pages(array $pages, array $courseoptions = []): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course($courseoptions);
        foreach ($pages as $name => $content) {
            $record = ['course' => $course->id, 'name' => $name, 'content' => $content];
            $generator->create_module('page', $record);
        }
        return $course;
    }

    /**
     * A course with one page, Guide, that carries a placeholder.
     *
     * @param array $courseoptions Course fields to set.
     * @return \stdClass The course.
     */
    private function course_with_one_guide(array $courseoptions = []): \stdClass {
        $marker = self::marker_text();
        return $this->course_with_pages(['Guide' => $marker], $courseoptions);
    }

    /**
     * A course with nothing in it.
     *
     * @return \stdClass The course.
     */
    private function empty_course(): \stdClass {
        $generator = $this->getDataGenerator();
        return $generator->create_course();
    }

    /**
     * A user without any role.
     *
     * @return \stdClass The user.
     */
    private function plain_user(): \stdClass {
        $generator = $this->getDataGenerator();
        return $generator->create_user();
    }

    /**
     * How many rows of a table match some conditions.
     *
     * @param string $table The table, without prefix.
     * @param array $conditions Field values, e.g. ['templateid' => 3].
     * @return int
     */
    private function row_count(string $table, array $conditions = []): int {
        global $DB;

        return $DB->count_records($table, $conditions);
    }

    /**
     * The ids of the activities of a course by name.
     *
     * @param \stdClass $course The course.
     * @return int[] Course module id by activity name.
     */
    private function cmids_by_name(\stdClass $course): array {
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        $ids = [];
        foreach ($cms as $cm) {
            $ids[$cm->name] = (int) $cm->id;
        }
        return $ids;
    }

    /**
     * The action saved for each activity of a template.
     *
     * @param int $templateid The template.
     * @return string[] Action by course module id.
     */
    private function saved_actions(int $templateid): array {
        $records = template_activity::get_records(['templateid' => $templateid]);
        $actions = [];
        foreach ($records as $record) {
            $cmid = (int) $record->get('cmid');
            $actions[$cmid] = $record->get('action');
        }
        return $actions;
    }

    /**
     * The behavior saved for each section of a template.
     *
     * @param int $templateid The template.
     * @return string[] Behavior by section number.
     */
    private function saved_behaviors(int $templateid): array {
        $records = template_section::get_records(['templateid' => $templateid]);
        $behaviors = [];
        foreach ($records as $record) {
            $number = (int) $record->get('sectionnum');
            $behaviors[$number] = $record->get('behavior');
        }
        return $behaviors;
    }

    /**
     * The activities the saved instances were made from, in order.
     *
     * @param int $templateid The template.
     * @return int[] Course module ids.
     */
    private function saved_instance_sources(int $templateid): array {
        $records = template_instance::get_records(['templateid' => $templateid]);
        $sources = [];
        foreach ($records as $record) {
            $sources[] = (int) $record->get('sourcecmid');
        }
        sort($sources);
        return $sources;
    }

    /**
     * Assert that making the template of a course is refused for a reason.
     *
     * @param int $courseid The course.
     * @param int $reason The expected reason, one of the constants of template_creation_exception.
     */
    private function assert_refused(int $courseid, int $reason): void {
        $message = 'The course ' . $courseid . ' should have been refused.';
        try {
            course_template_creator::create($courseid);
            $this->fail($message);
        } catch (template_creation_exception $error) {
            $found = $error->reason();
            $this->assertSame($reason, $found);
        }
    }
}
