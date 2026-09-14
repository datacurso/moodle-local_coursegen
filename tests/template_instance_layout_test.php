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
     * DB) with the given anchorcmid/sortorder, for pure ordering tests.
     *
     * @param int $anchorcmid
     * @param int $sortorder
     * @param string $label Identifies the instance in assertions (stored as its name).
     * @return template_instance
     */
    private function fake_instance(int $anchorcmid, int $sortorder, string $label): template_instance {
        $instance = new template_instance(0);
        $instance->set('templateid', 1);
        $instance->set('sectionid', 1);
        $instance->set('sourcecmid', 1);
        $instance->set('sourcename', 'Source');
        $instance->set('name', $label);
        $instance->set('typelabel', 'Page');
        $instance->set('anchorcmid', $anchorcmid);
        $instance->set('sortorder', $sortorder);
        return $instance;
    }

    /**
     * @param array $rows
     * @return array Each entry simplified to a short token: "real:<cmid>" or "inst:<name>".
     */
    private function tokens(array $rows): array {
        return array_map(function($row) {
            if ($row['type'] === 'real') {
                return 'real:' . $row['cmid'];
            }
            return 'inst:' . $row['record']->get('name');
        }, $rows);
    }

    /**
     * No instances at all: the real cmids pass through untouched, in order.
     */
    public function test_no_instances_returns_only_real_rows_in_order(): void {
        $rows = template_instance_layout::ordered_rows([10, 20, 30], []);
        $this->assertSame(['real:10', 'real:20', 'real:30'], $this->tokens($rows));
    }

    /**
     * An instance anchored at a real cmid renders immediately after it.
     */
    public function test_instance_renders_immediately_after_its_anchor(): void {
        $instance = $this->fake_instance(10, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $this->assertSame(['real:10', 'inst:A', 'real:20'], $this->tokens($rows));
    }

    /**
     * anchorcmid=0 means "the start of the section", before every real row.
     */
    public function test_anchor_zero_renders_before_the_first_real_row(): void {
        $instance = $this->fake_instance(0, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $this->assertSame(['inst:A', 'real:10', 'real:20'], $this->tokens($rows));
    }

    /**
     * Two instances sharing the same anchor keep their relative sortorder.
     */
    public function test_instances_sharing_an_anchor_order_by_sortorder(): void {
        $second = $this->fake_instance(10, 2, 'Second');
        $first = $this->fake_instance(10, 1, 'First');
        $rows = template_instance_layout::ordered_rows([10, 20], [$second, $first]);
        $this->assertSame(['real:10', 'inst:First', 'inst:Second', 'real:20'], $this->tokens($rows));
    }

    /**
     * An instance appended after the section's very last real row.
     */
    public function test_instance_anchored_at_the_last_real_row_renders_at_the_end(): void {
        $instance = $this->fake_instance(20, 0, 'A');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $this->assertSame(['real:10', 'real:20', 'inst:A'], $this->tokens($rows));
    }

    /**
     * An instance whose anchorcmid no longer matches any real cmid in this
     * section (its anchor activity was removed from the base course) is
     * appended at the very end instead of being dropped or crashing.
     */
    public function test_instance_with_an_invalid_anchor_is_appended_at_the_end(): void {
        $instance = $this->fake_instance(999, 0, 'Orphan');
        $rows = template_instance_layout::ordered_rows([10, 20], [$instance]);
        $this->assertSame(['real:10', 'real:20', 'inst:Orphan'], $this->tokens($rows));
    }

    /**
     * Multiple orphaned instances (from different, now-invalid anchors)
     * still order deterministically by their own sortorder among themselves.
     */
    public function test_multiple_orphaned_instances_order_by_sortorder(): void {
        $second = $this->fake_instance(888, 2, 'Second');
        $first = $this->fake_instance(999, 1, 'First');
        $rows = template_instance_layout::ordered_rows([10], [$second, $first]);
        $this->assertSame(['real:10', 'inst:First', 'inst:Second'], $this->tokens($rows));
    }

    /**
     * An entirely empty section (no real activities) with one instance
     * anchored at the start still renders that instance, not an error.
     */
    public function test_empty_section_with_one_instance_renders_just_that_instance(): void {
        $instance = $this->fake_instance(0, 0, 'Only');
        $rows = template_instance_layout::ordered_rows([], [$instance]);
        $this->assertSame(['inst:Only'], $this->tokens($rows));
    }

    /**
     * An entirely empty section with no instances returns an empty list.
     */
    public function test_empty_section_with_no_instances_returns_empty_array(): void {
        $this->assertSame([], template_instance_layout::ordered_rows([], []));
    }
}
