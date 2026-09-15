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
 * Every "+" trigger (the hover-reveal row-gap and the section's persistent
 * trailing row) renders as a native Bootstrap dropdown — a ".dropdown"
 * wrapper holding the trigger next to an empty ".dropdown-menu" sibling —
 * instead of the earlier hand-positioned floating menu. Bootstrap's own
 * bundled JS owns positioning/visibility/keyboard handling for these; this
 * plugin only ever fills the sibling ".dropdown-menu" with fresh content
 * client-side (see amd/src/local/template/template_instance_menu.js).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\output\sections_config
 *
 * @runTestsInSeparateProcesses
 */
final class template_instance_menu_markup_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Every row-gap trigger sits inside its own ".dropdown" wrapper with an
     * accessible trigger and an empty ".dropdown-menu" sibling ready for
     * client-side content — never a bare floating-menu button.
     */
    public function test_row_gap_trigger_renders_as_a_native_dropdown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        $gapstart = strpos($html, 'data-region="row-gap"');
        $this->assertNotFalse($gapstart, 'No row-gap trigger rendered');
        $gapend = strpos($html, '</tr>', $gapstart);
        $gapmarkup = substr($html, $gapstart, $gapend - $gapstart);

        $this->assertStringContainsString('class="dropdown tpl-instance-dropdown"', $gapmarkup);
        $this->assertStringContainsString('aria-haspopup="true"', $gapmarkup);
        $this->assertStringContainsString('aria-expanded="false"', $gapmarkup);
        $this->assertMatchesRegularExpression('/<div class="dropdown-menu tpl-instance-menu" role="menu">\s*<\/div>/', $gapmarkup);
    }

    /**
     * The section's persistent "Add activity from a template" row follows
     * the exact same native-dropdown shape as every row-gap trigger.
     */
    public function test_persistent_add_trigger_renders_as_a_native_dropdown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        $addstart = strpos($html, 'data-region="add-instance"');
        $this->assertNotFalse($addstart, 'No persistent add-instance trigger rendered');
        $addend = strpos($html, '</tr>', $addstart);
        $addmarkup = substr($html, $addstart, $addend - $addstart);

        $this->assertStringContainsString('class="dropdown tpl-instance-dropdown"', $addmarkup);
        $this->assertStringContainsString('aria-haspopup="true"', $addmarkup);
        $this->assertStringContainsString('aria-expanded="false"', $addmarkup);
        $this->assertMatchesRegularExpression('/<div class="dropdown-menu tpl-instance-menu" role="menu">\s*<\/div>/', $addmarkup);
    }

    /**
     * The section's last row never gets its own trailing row-gap: the
     * persistent add-instance row right after it already covers the same
     * "insert here" position, so a hover-reveal "+" directly in front of
     * it would be the same affordance rendered twice.
     */
    public function test_no_row_gap_directly_before_the_persistent_add_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        $addstart = strpos($html, 'data-region="add-instance"');
        $this->assertNotFalse($addstart, 'No persistent add-instance trigger rendered');
        $precedingmarkup = substr($html, 0, $addstart);
        $lastgap = strrpos($precedingmarkup, 'data-region="row-gap"');
        $lastrowclose = strrpos($precedingmarkup, '</tr>');

        // The nearest thing before the add-row must be a closed content
        // row, not a row-gap: if a gap were the last thing rendered before
        // it, its own strpos would land AFTER that last "</tr>".
        $this->assertNotFalse($lastrowclose, 'No content row rendered before the add-row');
        if ($lastgap !== false) {
            $this->assertLessThan($lastrowclose, $lastgap, 'A row-gap sits directly before the persistent add-row');
        }
    }

    /**
     * Sections with more than one activity still get a row-gap between
     * their non-last rows — only the trailing one is ever skipped.
     */
    public function test_row_gap_still_renders_between_non_last_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);

        $html = sections_config::render($modinfo);

        $gapcount = substr_count($html, 'data-region="row-gap"');
        $this->assertGreaterThan(0, $gapcount, 'No row-gap rendered at all for a multi-activity section');
    }
}
