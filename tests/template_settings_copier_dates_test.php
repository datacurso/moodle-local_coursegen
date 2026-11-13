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

use local_coursegen\local\service\template_settings_copier;

/**
 * The dates of the restrictions follow the new course's start date when the settings are copied.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_settings_copier
 */
final class template_settings_copier_dates_test extends \advanced_testcase {
    /** @var int Date condition of the template, as the template course sees it. */
    private const FROM = 1794895740;

    /** @var int The end of the window; 568800 seconds after the start. */
    private const UNTIL = 1795464540;

    /**
     * A template course with a section and a page that are restricted by a window of dates.
     *
     * @param int $startdate Start date of the template course.
     * @return array{0: \stdClass, 1: int, 2: \stdClass, 3: \stdClass} Course, template id, page, section.
     */
    private function create_template(int $startdate): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['startdate' => $startdate, 'numsections' => 1]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 1]);
        $availability = json_encode(['op' => '&', 'showc' => [true, false], 'c' => [
            ['type' => 'date', 'd' => '>=', 't' => self::FROM],
            ['type' => 'date', 'd' => '<', 't' => self::UNTIL],
        ]]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $page->cmid]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'availability', $availability, ['id' => $section->id]);
        $templateid = $DB->insert_record('local_coursegen_template', (object) [
            'name' => 'Base', 'courseid' => $course->id, 'nolimit' => 1, 'namingstart' => 1, 'visible' => 1,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => get_admin()->id,
        ]);
        return [$course, (int) $templateid, $page, $section];
    }

    /**
     * Run the copy for a new course with one generated page in section 1.
     *
     * @param int $templateid
     * @param int $newstart Start date of the new course.
     * @param \stdClass $page The template page.
     * @return array{0: array, 1: array} Availability of the new section and of the new page, as arrays.
     */
    private function copy(int $templateid, int $newstart, \stdClass $page): array {
        global $DB;

        $new = $this->getDataGenerator()->create_course(['startdate' => $newstart, 'numsections' => 1]);
        $newpage = $this->getDataGenerator()->create_module('page', ['course' => $new->id], ['section' => 1]);
        $payload = [[
            'cmid' => -1,
            'template_behavior' => ['action' => 'instance', 'template_source_cmid' => (int) $page->cmid],
        ]];

        template_settings_copier::copy_into($templateid, (int) $new->id, $payload, [-1 => (int) $newpage->cmid], []);

        $section = $DB->get_field('course_sections', 'availability', ['course' => $new->id, 'section' => 1]);
        $module = $DB->get_field('course_modules', 'availability', ['id' => $newpage->cmid]);
        return [json_decode((string) $section, true), json_decode((string) $module, true)];
    }

    /**
     * Sections and activities get the dates moved by the distance between the two start dates, spacing intact.
     */
    public function test_dates_of_sections_and_activities_move_with_the_start_date(): void {
        $this->resetAfterTest();
        $base = 1790000000;
        $delta = 86400 * 90;
        [, $templateid, $page] = $this->create_template($base);

        [$section, $module] = $this->copy($templateid, $base + $delta, $page);

        foreach ([$section, $module] as $availability) {
            $this->assertSame(self::FROM + $delta, $availability['c'][0]['t']);
            $this->assertSame(self::UNTIL + $delta, $availability['c'][1]['t']);
            $this->assertSame('>=', $availability['c'][0]['d']);
            $this->assertSame('<', $availability['c'][1]['d']);
            $this->assertSame([true, false], $availability['showc']);
            $this->assertSame(568800, $availability['c'][1]['t'] - $availability['c'][0]['t']);
        }
    }

    /**
     * A new course that starts earlier than the template moves the dates earlier.
     */
    public function test_a_new_course_starting_earlier_moves_the_dates_earlier(): void {
        $this->resetAfterTest();
        $base = 1790000000;
        [, $templateid, $page] = $this->create_template($base);

        [$section] = $this->copy($templateid, $base - 86400 * 7, $page);

        $this->assertSame(self::FROM - 604800, $section['c'][0]['t']);
    }

    /**
     * The same start date keeps the dates untouched.
     */
    public function test_equal_start_dates_keep_the_dates(): void {
        $this->resetAfterTest();
        $base = 1790000000;
        [, $templateid, $page] = $this->create_template($base);

        [$section, $module] = $this->copy($templateid, $base, $page);

        $this->assertSame(self::FROM, $section['c'][0]['t']);
        $this->assertSame(self::FROM, $module['c'][0]['t']);
    }

    /**
     * A new course without a start date has nothing to measure from, so the dates are left out as before.
     */
    public function test_an_unset_new_start_date_drops_the_dates(): void {
        $this->resetAfterTest();
        [, $templateid, $page] = $this->create_template(1790000000);

        [$section, $module] = $this->copy($templateid, 0, $page);

        $this->assertNull($section);
        $this->assertNull($module);
    }

    /**
     * A template without a start date cannot be measured from either: the dates are kept as the template has them.
     */
    public function test_an_unset_template_start_date_keeps_the_dates(): void {
        $this->resetAfterTest();
        [, $templateid, $page] = $this->create_template(0);

        [$section] = $this->copy($templateid, 1790000000, $page);

        $this->assertSame(self::FROM, $section['c'][0]['t']);
    }
}
