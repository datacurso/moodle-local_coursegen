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

namespace local_coursegen\local\space;

use local_coursegen\local\models\template_activity;

/**
 * Tests for space_rules.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_rules
 */
final class space_rules_test extends \advanced_testcase {
    /**
     * Only a file resource can be a space.
     */
    public function test_allows_only_the_file_resource(): void {
        $this->assertTrue(space_rules::allows('resource'));
        $this->assertFalse(space_rules::allows('page'));
        $this->assertFalse(space_rules::allows('forum'));
        $this->assertFalse(space_rules::allows(''));
    }

    /**
     * A space on a resource stays a space.
     */
    public function test_space_on_a_resource_is_kept(): void {
        $action = space_rules::effective_action(template_activity::ACTION_SPACE, 'resource');

        $this->assertSame(template_activity::ACTION_SPACE, $action);
    }

    /**
     * A space saved on any other type is read as exclude.
     */
    public function test_space_on_another_type_is_read_as_exclude(): void {
        $action = space_rules::effective_action(template_activity::ACTION_SPACE, 'forum');

        $this->assertSame(template_activity::ACTION_EXCLUDE, $action);
    }

    /**
     * Every other action is returned as it is, whatever the type.
     */
    public function test_other_actions_are_returned_untouched(): void {
        foreach (['keep', 'reference', 'template', 'exclude', 'instance'] as $saved) {
            $this->assertSame($saved, space_rules::effective_action($saved, 'page'));
            $this->assertSame($saved, space_rules::effective_action($saved, 'resource'));
        }
    }
}
