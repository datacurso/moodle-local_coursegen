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

use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_space;
use local_coursegen\local\service\template_instance_layout;

/**
 * Pure ordering logic for interleaving a section's real activity cmids with
 * its saved virtual instances: anchor + sortorder placement, ties, section
 * start, and the "anchor no longer exists" degrade path.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_instance_layout
 */
final class template_instance_layout_test extends \advanced_testcase {
    /**
     * Build an in-memory template_instance persistent (never saved to the
     * DB) with the given aftercmid/sortorder, for pure ordering tests.
     *
     * @param int $aftercmid
     * @param int $sortorder
     * @param string $label Identifies the instance in assertions (stored as its name).
     * @return template_instance
     */
    private function fake_instance(int $aftercmid, int $sortorder, string $label): template_instance {
        $instance = new template_instance(0);
        $instance->set('templateid', 1);
        $instance->set('sectionid', 1);
        $instance->set('sourcecmid', 1);
        $instance->set('sourcename', 'Source');
        $instance->set('name', $label);
        $instance->set('typelabel', 'Page');
        $instance->set('aftercmid', $aftercmid);
        $instance->set('sortorder', $sortorder);
        return $instance;
    }

    /**
     * Build an in-memory template_space persistent (never saved to the DB)
     * with the given aftercmid/sortorder, for pure ordering tests.
     *
     * @param int $aftercmid
     * @param int $sortorder
     * @param string $label Identifies the space in assertions (stored as its instruction).
     * @return template_space
     */
    private function fake_space(int $aftercmid, int $sortorder, string $label): template_space {
        $space = new template_space(0);
        $space->set('templateid', 1);
        $space->set('sectionid', 1);
        $space->set('modname', 'resource');
        $space->set('instruction', $label);
        $space->set('aftercmid', $aftercmid);
        $space->set('sortorder', $sortorder);
        return $space;
    }

    /**
     * Reduce ordered rows to short tokens, so assertions read as a list.
     *
     * @param array $rows
     * @return array Each entry simplified to a short token: "real:<cmid>",
     *     "inst:<name>" or "space:<instruction>".
     */
    private function tokens(array $rows): array {
        $tokens = [];
        foreach ($rows as $key => $row) {
            $tokens[$key] = $this->token_of($row);
        }
        return $tokens;
    }

    /**
     * Reduce one ordered row to its short token.
     *
     * @param array $row A row entry of template_instance_layout::ordered_rows().
     * @return string "real:<cmid>", "inst:<name>" or "space:<instruction>".
     */
    private function token_of(array $row): string {
        if ($row['type'] === 'real') {
            return 'real:' . $row['cmid'];
        }
        if ($row['type'] === 'space') {
            $instruction = $row['record']->get('instruction');
            return 'space:' . $instruction;
        }
        $name = $row['record']->get('name');
        return 'inst:' . $name;
    }

    /**
     * No instances at all: the real cmids pass through untouched, in order.
     */
    public function test_no_instances_returns_only_real_rows_in_order(): void {
        $rows = template_instance_layout::ordered_rows([10, 20, 30], []);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'real:20', 'real:30'], $tokens);
    }

    /**
     * An instance anchored at a real cmid renders immediately after it.
     */
    public function test_instance_renders_immediately_after_its_anchor(): void {
        $instance = $this->fake_instance(10, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'inst:A', 'real:20'], $tokens);
    }

    /**
     * aftercmid=0 means "the start of the section", before every real row.
     */
    public function test_anchor_zero_renders_before_the_first_real_row(): void {
        $instance = $this->fake_instance(0, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['inst:A', 'real:10', 'real:20'], $tokens);
    }

    /**
     * Two instances sharing the same anchor keep their relative sortorder.
     */
    public function test_instances_sharing_an_anchor_order_by_sortorder(): void {
        $second = $this->fake_instance(10, 2, 'Second');
        $first = $this->fake_instance(10, 1, 'First');
        $rows = template_instance_layout::ordered_rows([10, 20], [$second, $first]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'inst:First', 'inst:Second', 'real:20'], $tokens);
    }

    /**
     * An instance appended after the section's very last real row.
     */
    public function test_instance_anchored_at_the_last_real_row_renders_at_the_end(): void {
        $instance = $this->fake_instance(20, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'real:20', 'inst:A'], $tokens);
    }

    /**
     * An instance whose aftercmid no longer matches any real cmid in this
     * section (its anchor activity was removed from the base course) is
     * appended at the very end instead of being dropped or crashing.
     */
    public function test_instance_with_an_invalid_anchor_is_appended_at_the_end(): void {
        $instance = $this->fake_instance(999, 0, 'Orphan');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'real:20', 'inst:Orphan'], $tokens);
    }

    /**
     * Multiple orphaned instances (from different, now-invalid anchors)
     * still order deterministically by their own sortorder among themselves.
     */
    public function test_multiple_orphaned_instances_order_by_sortorder(): void {
        $second = $this->fake_instance(888, 2, 'Second');
        $first = $this->fake_instance(999, 1, 'First');
        $rows = template_instance_layout::ordered_rows([10], [$second, $first]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'inst:First', 'inst:Second'], $tokens);
    }

    /**
     * An entirely empty section (no real activities) with one instance
     * anchored at the start still renders that instance, not an error.
     */
    public function test_empty_section_with_one_instance_renders_just_that_instance(): void {
        $instance = $this->fake_instance(0, 0, 'Only');
        $rows = template_instance_layout::ordered_rows([], [$instance]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['inst:Only'], $tokens);
    }

    /**
     * An entirely empty section with no instances returns an empty list.
     */
    public function test_empty_section_with_no_instances_returns_empty_array(): void {
        $rows = template_instance_layout::ordered_rows([], []);
        $this->assertSame([], $rows);
    }

    /**
     * A space is emitted as its own kind of row, placed by the same anchor
     * rule as an instance.
     */
    public function test_space_renders_after_its_anchor_as_a_space_row(): void {
        $space = $this->fake_space(10, 0, 'Upload the guide');
        $rows = template_instance_layout::ordered_rows([10, 20], [$space]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'space:Upload the guide', 'real:20'], $tokens);
    }

    /**
     * Spaces and instances that share an anchor order by their shared
     * sortorder, whichever kind they are.
     */
    public function test_space_and_instance_sharing_an_anchor_order_by_sortorder(): void {
        $instance = $this->fake_instance(10, 1, 'Second');
        $space = $this->fake_space(10, 0, 'First');
        $rows = template_instance_layout::ordered_rows([10], [$instance, $space]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'space:First', 'inst:Second'], $tokens);
    }

    /**
     * A space whose anchor no longer exists is appended at the end instead
     * of being dropped.
     */
    public function test_space_with_a_missing_anchor_is_appended_at_the_end(): void {
        $space = $this->fake_space(999, 0, 'Orphan');
        $rows = template_instance_layout::ordered_rows([10], [$space]);
        $tokens = $this->tokens($rows);
        $this->assertSame(['real:10', 'space:Orphan'], $tokens);
    }
}
