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

/**
 * Permission contract of the image generation management web service.
 *
 * The admin page is gated by local/coursegen:manageimagegeneration, so the
 * save endpoint must accept exactly the same holders: gating the save on
 * moodle/site:config produced a visible-but-rejected page for managers.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\manage_image_generation
 * @runTestsInSeparateProcesses
 */
final class manage_image_generation_permissions_test extends \advanced_testcase {
    /**
     * A minimal valid payload for the service.
     *
     * @return array
     */
    private function minimal_payload(): array {
        return [
            'generationmode' => 'manual',
            'overridecourse' => 0,
            'overrideactivity' => 0,
            'activities' => [],
        ];
    }

    /**
     * A user holding the plugin management capability can save the policy.
     */
    public function test_holder_of_management_capability_can_save(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'imgmanager']);
        assign_capability('local/coursegen:manageimagegeneration', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        $payload = $this->minimal_payload();
        $result = manage_image_generation::execute(
            $payload['overridecourse'],
            $payload['overrideactivity'],
            $payload['generationmode'],
            $payload['activities']
        );

        $this->assertTrue($result['success']);
        $this->assertSame('manual', get_config('local_coursegen', 'generationmode'));
    }

    /**
     * A user without the capability is rejected.
     */
    public function test_user_without_capability_is_rejected(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $payload = $this->minimal_payload();
        manage_image_generation::execute(
            $payload['overridecourse'],
            $payload['overrideactivity'],
            $payload['generationmode'],
            $payload['activities']
        );
    }
}
