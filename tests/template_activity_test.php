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

use core\invalid_persistent_exception;
use local_coursegen\local\models\template_activity;

/**
 * The action of a saved template activity is one of the five the template
 * editor offers, and nothing else is accepted by the model.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\models\template_activity
 */
final class template_activity_test extends \advanced_testcase {
    /**
     * The model knows exactly the five actions of the template editor.
     */
    public function test_actions_are_exactly_the_five_of_the_editor(): void {
        $expected = ['keep', 'reference', 'exclude', 'template', 'space'];

        $this->assertEqualsCanonicalizing($expected, template_activity::ACTIONS);
    }

    /**
     * An activity saved without an action is kept as it is.
     */
    public function test_action_defaults_to_keep(): void {
        $this->resetAfterTest(true);

        $activity = new template_activity(0, (object) ['templateid' => 1, 'sectionid' => 1, 'cmid' => 1]);
        $activity->create();

        $this->assertSame('keep', $activity->get('action'));
    }

    /**
     * Every action of the editor can be saved and read back.
     *
     * @dataProvider valid_action_provider
     * @param string $action
     */
    public function test_every_editor_action_is_accepted(string $action): void {
        $this->resetAfterTest(true);

        $activity = new template_activity(0, (object) [
            'templateid' => 1,
            'sectionid' => 1,
            'cmid' => 1,
            'action' => $action,
        ]);
        $activity->create();

        $reloaded = template_activity::get_record(['id' => $activity->get('id')]);
        $this->assertSame($action, $reloaded->get('action'));
    }

    /**
     * The actions the editor offers.
     *
     * @return array
     */
    public static function valid_action_provider(): array {
        return [
            'keep' => ['keep'],
            'reference' => ['reference'],
            'exclude' => ['exclude'],
            'template' => ['template'],
            'space' => ['space'],
        ];
    }

    /**
     * The action that no longer exists, and any other unknown one, is
     * rejected by the model when it is saved.
     *
     * @dataProvider invalid_action_provider
     * @param string $action
     */
    public function test_unknown_action_is_rejected_on_create(string $action): void {
        $this->resetAfterTest(true);

        $activity = new template_activity(0, (object) [
            'templateid' => 1,
            'sectionid' => 1,
            'cmid' => 1,
            'action' => $action,
        ]);

        $this->expectException(invalid_persistent_exception::class);
        $activity->create();
    }

    /**
     * Changing a saved activity to an unknown action is rejected as well,
     * and the stored action stays as it was.
     *
     * @dataProvider invalid_action_provider
     * @param string $action
     */
    public function test_unknown_action_is_rejected_on_update(string $action): void {
        global $DB;
        $this->resetAfterTest(true);

        $activity = new template_activity(0, (object) ['templateid' => 1, 'sectionid' => 1, 'cmid' => 1]);
        $activity->create();
        $activity->set('action', $action);

        $rejected = false;
        try {
            $activity->update();
        } catch (invalid_persistent_exception $exception) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $stored = $DB->get_field(template_activity::TABLE, 'action', ['id' => $activity->get('id')]);
        $this->assertSame('keep', $stored);
    }

    /**
     * Values the model must refuse.
     *
     * @return array
     */
    public static function invalid_action_provider(): array {
        return [
            'removed action' => ['modify'],
            'unknown word' => ['unknown'],
            'wire action of instances' => ['instance'],
            'empty' => [''],
        ];
    }
}
