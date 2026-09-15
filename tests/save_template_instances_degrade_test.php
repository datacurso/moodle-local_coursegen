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

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait sits in tests/ root, outside the tests/classes
// autoload scope, so it must be required explicitly.
require_once(__DIR__ . '/sections_config_fixture_trait.php');

use local_coursegen\output\sections_config;

/**
 * A rendered instance never dereferences its source cmid — its name,
 * sourcename and typelabel are snapshots taken at creation time — so
 * rendering cannot break in any of the three ways a source could stop
 * being valid: the source activity is deleted, the source activity still
 * exists but is no longer marked "Use as template", or the instance's own
 * positional anchor no longer matches any real cmid in its section.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 * @covers     \local_coursegen\local\service\template_instance_layout
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_instances_degrade_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * An instance whose source activity has since been deleted from the
     * base course still renders correctly.
     */
    public function test_instance_renders_when_source_activity_no_longer_exists(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);
        $deletedcmid = (int) $page->cmid + 99999;

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [$this->instance_payload($deletedcmid, 'Vanished template', 'Orphan lesson', 0, 0)]
        );

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $this->assertStringContainsString('value="Orphan lesson"', $html);
        $this->assertStringContainsString('Vanished template', $html);
    }

    /**
     * An instance whose source cmid is a real, still-existing activity that
     * is simply no longer marked "Use as template" in this same save still
     * renders correctly.
     */
    public function test_instance_renders_when_source_no_longer_marked_as_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            // The source row is saved as "keep", not "template" — the
            // instance below still references it as its origin.
            [['cmid' => (int) $page->cmid, 'action' => 'keep', 'useasreference' => true, 'prompt' => '']],
            [$this->instance_payload((int) $page->cmid, 'Page template', 'Still here', 0, 0)]
        );

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $this->assertStringContainsString('value="Still here"', $html);
        $badge = get_string('template_instance_badge', 'local_coursegen', 'Page template');
        $this->assertStringContainsString($badge, $html);
    }

    /**
     * An instance anchored to a real cmid that no longer exists in this
     * section (its base-course activity was removed) is appended at the
     * end of the section instead of crashing the render.
     */
    public function test_instance_with_stale_anchor_still_renders_at_section_end(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);
        $staleanchor = (int) $page->cmid + 99999;

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'keep', 'useasreference' => true, 'prompt' => '']],
            [$this->instance_payload((int) $page->cmid, 'Page template', 'Trailing', $staleanchor, 0)]
        );

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $pagepos = strpos($html, 'data-id="' . $page->cmid . '"');
        $instancepos = strpos($html, 'value="Trailing"');
        $this->assertNotFalse($instancepos);
        $this->assertLessThan($instancepos, $pagepos);
    }
}
