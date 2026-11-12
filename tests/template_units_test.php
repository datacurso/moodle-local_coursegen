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

use local_coursegen\external\get_template_structure;
use local_coursegen\external\start_template_generation;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_unit_resizer;
use local_coursegen\local\service\template_units;

/**
 * The number of units the professor asks for, from the field of the form to the payload.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_units
 * @covers     \local_coursegen\external\start_template_generation
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class template_units_test extends \advanced_testcase {
    /**
     * A flexsections template: Intro, then units 2 and 5 with two children each, Closing.
     *
     * @return int The template id.
     */
    private function flexsections_template(): int {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $format = course_get_format($course);
        $tree = [
            1 => [0, 'Intro'],
            2 => [0, 'Unit 1'], 3 => [2, 'Resources 1'], 4 => [2, 'Activities 1'],
            5 => [0, 'Unit 2'], 6 => [5, 'Resources 2'], 7 => [5, 'Activities 2'],
            8 => [0, 'Closing'],
        ];
        foreach ($tree as $number => [$parent, $name]) {
            $section = course_create_section($course, $number);
            $format->update_section_format_options(['id' => $section->id, 'parent' => $parent]);
            course_update_section($course, $section, ['name' => $name]);
        }
        rebuild_course_cache($course->id, true);
        return $this->template_for($course->id);
    }

    /**
     * A template over a course of the given format with three plain sections.
     *
     * @param string $format
     * @return int The template id.
     */
    private function plain_template(string $format): int {
        $course = $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => 3]);
        return $this->template_for($course->id);
    }

    /**
     * Save a template record over a course.
     *
     * @param int $courseid
     * @return int
     */
    private function template_for(int $courseid): int {
        $template = new template(0, (object) ['name' => 'Units fixture', 'courseid' => $courseid]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * The course of a template.
     *
     * @param int $templateid
     * @return \stdClass
     */
    private function course_of(int $templateid): \stdClass {
        return get_course((int) template::get_record(['id' => $templateid])->get('courseid'));
    }

    /**
     * A payload without the random uid each section gets on every export.
     *
     * @param array $payload
     * @return string
     */
    private function stable(array $payload): string {
        foreach ($payload['sections_info'] as &$entry) {
            unset($entry['uid']);
        }
        return json_encode($payload);
    }

    /**
     * A flexsections template counts its units.
     */
    public function test_count_is_the_units_of_a_flexsections_course(): void {
        $this->resetAfterTest();
        $course = $this->course_of($this->flexsections_template());
        $this->assertSame(2, template_units::count(get_fast_modinfo($course)));
    }

    /**
     * A course of another format has no units, so nothing is counted.
     */
    public function test_count_is_null_for_another_format(): void {
        $this->resetAfterTest();
        $course = $this->course_of($this->plain_template('topics'));
        $this->assertNull(template_units::count(get_fast_modinfo($course)));
    }

    /**
     * A flexsections course whose sections have no children has no units either.
     */
    public function test_count_is_null_for_flexsections_without_units(): void {
        $this->resetAfterTest();
        $course = $this->course_of($this->plain_template('flexsections'));
        $this->assertNull(template_units::count(get_fast_modinfo($course)));
    }

    /**
     * What is asked is kept between 1 and the maximum, and nothing is asked when it is the template's own.
     *
     * @dataProvider requested_provider
     * @param int|null $asked
     * @param int|null $expected
     */
    public function test_requested_units(?int $asked, ?int $expected): void {
        $this->resetAfterTest();
        $course = $this->course_of($this->flexsections_template());
        $this->assertSame($expected, template_units::requested(get_fast_modinfo($course), $asked));
    }

    /**
     * Cases of test_requested_units: the template has 2 units.
     *
     * @return array
     */
    public static function requested_provider(): array {
        return [
            'nothing asked' => [null, null],
            'the same count' => [2, null],
            'more' => [5, 5],
            'fewer' => [1, 1],
            'zero is clamped up to one' => [0, 1],
            'negative is clamped up to one' => [-7, 1],
            'too many is clamped to the maximum' => [9999, template_unit_resizer::MAX_UNITS],
            'the maximum itself' => [template_unit_resizer::MAX_UNITS, template_unit_resizer::MAX_UNITS],
        ];
    }

    /**
     * Another format ignores whatever was asked.
     */
    public function test_requested_is_ignored_for_another_format(): void {
        $this->resetAfterTest();
        $course = $this->course_of($this->plain_template('topics'));
        $this->assertNull(template_units::requested(get_fast_modinfo($course), 5));
    }

    /**
     * The request has the optional units parameter, null by default.
     */
    public function test_start_parameters_declare_optional_units(): void {
        $units = start_template_generation::execute_parameters()->keys['units'];
        $this->assertSame(PARAM_INT, $units->type);
        $this->assertSame(VALUE_DEFAULT, $units->required);
        $this->assertNull($units->default);
    }

    /**
     * A bigger number of units reaches the payload resized.
     */
    public function test_payload_is_resized_when_the_units_differ(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->flexsections_template();
        $plain = start_template_generation::payload_for($templateid, '', '', false, null);
        $resized = start_template_generation::payload_for($templateid, '', '', false, 4);
        $this->assertGreaterThan(count($plain['sections_info']), count($resized['sections_info']));
        $this->assertSame(count($resized['sections_info']) - 1, $resized['course_configuration']['numsections']);
    }

    /**
     * The template's own count asks for nothing: the payload is the one built without units.
     */
    public function test_payload_is_identical_when_the_count_is_the_templates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->flexsections_template();
        $plain = start_template_generation::payload_for($templateid, '', '', false, null);
        $same = start_template_generation::payload_for($templateid, '', '', false, 2);
        $this->assertSame($this->stable($plain), $this->stable($same));
    }

    /**
     * An absurd number is clamped, never trusted.
     */
    public function test_payload_clamps_an_absurd_number_of_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->flexsections_template();
        $huge = start_template_generation::payload_for($templateid, '', '', false, 100000);
        $max = start_template_generation::payload_for($templateid, '', '', false, template_unit_resizer::MAX_UNITS);
        $this->assertSame(count($max['sections_info']), count($huge['sections_info']));
        $one = start_template_generation::payload_for($templateid, '', '', false, -3);
        $this->assertLessThan(count($huge['sections_info']), count($one['sections_info']));
    }

    /**
     * A template of another format is generated exactly as before, whatever number comes in.
     */
    public function test_payload_of_another_format_ignores_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $templateid = $this->plain_template('topics');
        $plain = start_template_generation::payload_for($templateid, '', '', false, null);
        $asked = start_template_generation::payload_for($templateid, '', '', false, 9);
        $this->assertSame($this->stable($plain), $this->stable($asked));
    }

    /**
     * The structure of a flexsections template tells how many units it has and how many may be asked.
     */
    public function test_structure_reports_unit_count_for_flexsections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = get_template_structure::execute($this->flexsections_template());
        $result = \core_external\external_api::clean_returnvalue(get_template_structure::execute_returns(), $result);
        $this->assertSame(2, $result['unitcount']);
        $this->assertSame(template_unit_resizer::MAX_UNITS, $result['maxunits']);
    }

    /**
     * The structure of any other template says nothing about units.
     */
    public function test_structure_has_no_unit_fields_for_another_format(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = get_template_structure::execute($this->plain_template('topics'));
        $result = \core_external\external_api::clean_returnvalue(get_template_structure::execute_returns(), $result);
        $this->assertArrayNotHasKey('unitcount', $result);
        $this->assertArrayNotHasKey('maxunits', $result);
    }
}
