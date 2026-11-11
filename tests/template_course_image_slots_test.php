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

use local_coursegen\local\service\template_course_image_slots;

/**
 * The pictures of a template's course that belong to no activity, and the instruction left for each.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_course_image_slots
 */
final class template_course_image_slots_test extends \advanced_testcase {
    /** @var string A one pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Store a picture in a file area of a course.
     *
     * @param \stdClass $course
     * @param string $component
     * @param string $area
     * @param int $itemid
     * @param string $name
     */
    private function picture(\stdClass $course, string $component, string $area, int $itemid, string $name): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($course->id)->id,
            'component' => $component,
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $name,
        ], base64_decode(self::PNG));
    }

    /**
     * A marker above a picture of a summary, one alone in a summary and one in the course summary are three slots.
     */
    public function test_each_marker_becomes_the_slot_of_its_place(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2, 'summary' => '[[coursegen:reference: cover]]']);
        $this->picture($course, 'course', 'overviewfiles', 0, 'cover.png');
        $first = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
        $second = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2]);
        $this->picture($course, 'course', 'section', (int) $first->id, 'banner one.png');
        $summary = '<p>[[coursegen:reference: banner]]</p><div><img src="@@PLUGINFILE@@/banner%20one.png"></div>';
        $DB->set_field('course_sections', 'summary', $summary, ['id' => $first->id]);
        $DB->set_field('course_sections', 'summary', '[[coursegen:reference: a picture for the unit]]', ['id' => $second->id]);

        $slots = template_course_image_slots::for_course(get_course($course->id));

        $keys = array_column($slots, null, 'key');
        $this->assertSame(['course:cover', 'section:1:summary:1', 'section:2:image'], array_keys($keys));
        $this->assertSame('cover.png', $keys['course:cover']['filename']);
        $this->assertSame('banner one.png', $keys['section:1:summary:1']['filename']);
        $this->assertSame('', $keys['section:2:image']['filename']);
        $this->assertSame('a picture for the unit', $keys['section:2:image']['instruction']);
    }

    /**
     * Two markers asking for the same picture are refused with a message that says where.
     */
    public function test_two_markers_for_the_same_picture_are_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '[[coursegen:reference: a]] [[coursegen:reference: b]]']);

        $this->expectException(\moodle_exception::class);
        template_course_image_slots::for_course(get_course($course->id));
    }

    /**
     * The markers are taken out of a text, with the paragraph they were alone in.
     */
    public function test_markers_are_taken_out_of_the_text(): void {
        $html = '<p>Hello</p><p>[[coursegen:reference: a picture]]</p><div>[[coursegen:reference: b]]<img src="x.png"></div>';

        $clean = template_course_image_slots::without_markers($html);

        $this->assertSame('<p>Hello</p><div><img src="x.png"></div>', $clean);
    }
}
