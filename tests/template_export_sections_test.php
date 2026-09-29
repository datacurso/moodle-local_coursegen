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

use local_coursegen\local\service\template_export_sections;

/**
 * Unit tests for template_export_sections::sections_info().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_sections
 *
 * @runTestsInSeparateProcesses
 */
final class template_export_sections_test extends \advanced_testcase {
    /**
     * Every section's own entry carries a random UUID uid, not the old
     * "section-{templateid}-{id}" derived key.
     */
    public function test_section_entries_carry_a_random_uuid_uid(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $modinfo = get_fast_modinfo($course);

        $sections = template_export_sections::sections_info($course, $modinfo, []);

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        foreach ($sections as $section) {
            $matched = preg_match($pattern, $section['uid']);
            $this->assertSame(1, $matched);
        }
    }

    /**
     * Two sections of the same course never share a uid.
     */
    public function test_section_uids_are_unique(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $modinfo = get_fast_modinfo($course);

        $sections = template_export_sections::sections_info($course, $modinfo, []);
        $uids = array_column($sections, 'uid');

        $this->assertCount(count($sections), array_unique($uids));
    }

    /**
     * A section with no saved behavior defaults to "aimodify".
     */
    public function test_section_with_no_saved_behavior_defaults_to_aimodify(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $modinfo = get_fast_modinfo($course);

        $sections = template_export_sections::sections_info($course, $modinfo, []);

        foreach ($sections as $section) {
            $this->assertSame('aimodify', $section['template_behavior']['behavior']);
        }
    }

    /**
     * A section's saved behavior is used instead of the default.
     */
    public function test_saved_behavior_overrides_the_default(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $modinfo = get_fast_modinfo($course);
        $sectioninfos = $modinfo->get_section_info_all();
        $firstsection = reset($sectioninfos);

        $sections = template_export_sections::sections_info(
            $course,
            $modinfo,
            [(int) $firstsection->id => 'keep']
        );

        $found = null;
        foreach ($sections as $section) {
            if ($section['section'] === (int) $firstsection->section) {
                $found = $section;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('keep', $found['template_behavior']['behavior']);
    }
}
