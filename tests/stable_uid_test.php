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

use local_coursegen\local\service\template_export_uids;

/**
 * Unit tests for template_export_uids::stable_uid().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_uids
 */
final class stable_uid_test extends \basic_testcase {
    /**
     * The same templateid/kind/id always produces the same uid: two
     * separate requests naming the same element have to agree on it.
     */
    public function test_same_inputs_produce_the_same_uid(): void {
        $first = template_export_uids::stable_uid(12, 'cm', 45);
        $second = template_export_uids::stable_uid(12, 'cm', 45);

        $this->assertSame($first, $second);
    }

    /**
     * A different templateid changes the uid, so the same cmid never
     * collides across two different templates.
     */
    public function test_different_templateid_changes_the_uid(): void {
        $first = template_export_uids::stable_uid(12, 'cm', 45);
        $second = template_export_uids::stable_uid(13, 'cm', 45);

        $this->assertNotSame($first, $second);
    }

    /**
     * A cm and a section can share the same numeric id without colliding,
     * because kind is part of the uid.
     */
    public function test_different_kind_changes_the_uid(): void {
        $cmuid = template_export_uids::stable_uid(12, 'cm', 45);
        $sectionuid = template_export_uids::stable_uid(12, 'section', 45);

        $this->assertNotSame($cmuid, $sectionuid);
    }

    /**
     * A different id changes the uid.
     */
    public function test_different_id_changes_the_uid(): void {
        $first = template_export_uids::stable_uid(12, 'cm', 45);
        $second = template_export_uids::stable_uid(12, 'cm', 46);

        $this->assertNotSame($first, $second);
    }

    /**
     * The result only ever contains what PARAM_ALPHANUMEXT accepts -
     * letters, digits, underscore and hyphen - since every caller reads it
     * through that param type.
     */
    public function test_uid_is_param_alphanumext_safe(): void {
        $uid = template_export_uids::stable_uid(12, 'cm', 45);

        $matched = preg_match('/^[A-Za-z0-9_-]+$/', $uid);
        $this->assertSame(1, $matched);
    }

    /**
     * The uid is readable, not an opaque hash: it is exactly
     * "{kind}-{templateid}-{id}", so it can be read back in logs or the
     * database without decoding anything.
     */
    public function test_uid_is_the_plain_joined_key(): void {
        $uid = template_export_uids::stable_uid(12, 'cm', 45);

        $this->assertSame('cm-12-45', $uid);
    }
}
