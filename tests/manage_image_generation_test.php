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

use local_coursegen\external\manage_image_generation;
use local_coursegen\local\image_generation\activities;

/**
 * Tests for saving the image generation policy.
 *
 * Loading the external class pulls in lib/externallib.php, so each test runs
 * in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\manage_image_generation
 *
 * @runTestsInSeparateProcesses
 */
final class manage_image_generation_test extends \advanced_testcase {
    /**
     * MDL-INT-012: Saving the policy requires the image generation management
     * permission and rejects other users without touching the settings.
     */
    public function test_saving_requires_management_capability(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            manage_image_generation::execute(1, 1, activities::MODE_AUTO, []);
            $this->fail('A permission error was expected for a user without the management capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->assertFalse(get_config('local_coursegen', 'generationmode'));
    }

    /**
     * MDL-INT-012: The maximum images per part is clamped to 5 on the server
     * even if the screen accepts larger values.
     */
    public function test_part_max_images_clamped_to_five(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manage_image_generation::execute(0, 0, activities::MODE_MANUAL, [
            [
                'id' => 'assign',
                'enabled' => 1,
                'prompt' => '',
                'parts' => [
                    ['id' => 'intro', 'enabled' => 1, 'maximages' => 9],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('5', (string)get_config('local_coursegen', 'maximgassign_intro'));
    }

    /**
     * MDL-INT-012: The global mode, the per-activity switches and the per-part
     * maximums are persisted as submitted within the server limits.
     */
    public function test_policy_persisted_as_submitted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = manage_image_generation::execute(1, 0, activities::MODE_MANUAL, [
            [
                'id' => 'assign',
                'enabled' => 1,
                'prompt' => '',
                'parts' => [
                    ['id' => 'intro', 'enabled' => 1, 'maximages' => 3],
                    ['id' => 'instructions', 'enabled' => 0, 'maximages' => 0],
                ],
            ],
            [
                'id' => 'book',
                'enabled' => 0,
                'prompt' => '',
                'parts' => [],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(activities::MODE_MANUAL, get_config('local_coursegen', 'generationmode'));
        $this->assertSame('1', (string)get_config('local_coursegen', 'overridecourse'));
        $this->assertSame('0', (string)get_config('local_coursegen', 'overrideactivity'));
        $this->assertSame('1', (string)get_config('local_coursegen', 'enableimgassign'));
        $this->assertSame('1', (string)get_config('local_coursegen', 'enableimgassign_intro'));
        $this->assertSame('3', (string)get_config('local_coursegen', 'maximgassign_intro'));
        $this->assertSame('0', (string)get_config('local_coursegen', 'enableimgassign_instructions'));
        $this->assertSame('0', (string)get_config('local_coursegen', 'maximgassign_instructions'));
        $this->assertSame('0', (string)get_config('local_coursegen', 'enableimgbook'));
    }

    /**
     * MDL-INT-012: The numeric field of the page limits the maximum to 5
     * visually before submitting.
     */
    public function test_screen_max_field_validates_limit_visually(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $OUTPUT->render_from_template('local_coursegen/manage_image_generation', [
            'currentmode' => activities::MODE_MANUAL,
            'overridecourse' => true,
            'overrideactivity' => false,
            'ismoddisabled' => false,
            'ismodeauto' => false,
            'ismodemanual' => true,
            'partmaximageshelp' => '',
            'activities' => [
                [
                    'id' => 'assign',
                    'name' => 'Assignment',
                    'iconurl' => '',
                    'tooltip' => '',
                    'enabled' => true,
                    'show' => true,
                    'parts' => [
                        [
                            'id' => 'intro',
                            'partuniqueid' => 'assign-intro',
                            'label' => 'Intro',
                            'enabled' => true,
                            'maximages' => 3,
                        ],
                    ],
                ],
            ],
        ]);

        // The numeric field visually enforces the same 0..5 range the server
        // clamps to, so the admin sees the limit before submitting.
        $this->assertMatchesRegularExpression('/<input type="number"[^>]*min="0"[^>]*max="5"/', $html);
    }

    /**
     * MDL-INT-013: Whoever can open the image generation administration page
     * can also save it.
     */
    public function test_page_open_and_save_require_same_permission(): void {
        $this->resetAfterTest();

        // The capability that opens the administration page (see settings.php)
        // must be enough to save the policy from it.
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('local/coursegen:manageimagegeneration', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $user->id, $systemcontext->id);
        $this->setUser($user);

        $result = manage_image_generation::execute(0, 0, activities::MODE_MANUAL, []);

        $this->assertTrue($result['success']);
        $this->assertSame(activities::MODE_MANUAL, get_config('local_coursegen', 'generationmode'));
    }
}
