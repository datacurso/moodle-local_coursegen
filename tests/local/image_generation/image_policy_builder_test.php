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

namespace local_coursegen\local\image_generation;

use local_coursegen\local\tenant_config;

/**
 * Tests for the per-tenant resolution of the image generation policy.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\image_generation\image_policy_builder
 */
final class image_policy_builder_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Creates a tenant and a user allocated to it, then returns [tenantid, user].
     *
     * @return array{0:int,1:\stdClass}
     */
    private function create_tenant_with_user(): array {
        $this->require_tool_tenant();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $tenantid = (int) $generator->create_tenant()->id;
        $user = $this->getDataGenerator()->create_user();
        $generator->allocate_user($user->id, $tenantid);
        return [$tenantid, $user];
    }

    /**
     * Returns the policy of one part of one activity.
     *
     * @param array $policy Built policy.
     * @param string $activityid Activity id.
     * @param string $partid Part id.
     * @return array
     */
    private function part_of(array $policy, string $activityid, string $partid): array {
        foreach ($policy['activities'] as $activity) {
            if ($activity['id'] !== $activityid) {
                continue;
            }
            foreach ($activity['parts'] as $part) {
                if ($part['id'] === $partid) {
                    return $part;
                }
            }
        }
        $this->fail("Part {$activityid}/{$partid} not found in the policy.");
    }

    /**
     * The policy of a user reflects the settings of their tenant only; another tenant gets its own values.
     */
    public function test_uses_tenant_generationmode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta, $usera] = $this->create_tenant_with_user();
        [$tenantb, $userb] = $this->create_tenant_with_user();
        tenant_config::set('generationmode', activities::MODE_AUTO, $tenanta);
        tenant_config::set('enableimgassign', 1, $tenanta);
        tenant_config::set('enableimgassign_intro', 1, $tenanta);
        tenant_config::set('maximgassign_intro', 1, $tenanta);
        tenant_config::set('generationmode', activities::MODE_MANUAL, $tenantb);
        tenant_config::set('overridecourse', 1, $tenantb);
        tenant_config::set('maximgassign_intro', 3, $tenantb);

        $this->setUser($usera);
        $policya = image_policy_builder::build();
        $this->assertSame(activities::MODE_AUTO, $policya['mode']);
        $this->assertFalse($policya['overridecourse']);
        $this->assertSame(1, $this->part_of($policya, 'assign', 'intro')['maximages']);
        $this->assertTrue($this->part_of($policya, 'assign', 'intro')['enabled']);

        $this->setUser($userb);
        $policyb = image_policy_builder::build();
        $this->assertSame(activities::MODE_MANUAL, $policyb['mode']);
        $this->assertTrue($policyb['overridecourse']);
        $this->assertSame(3, $this->part_of($policyb, 'assign', 'intro')['maximages']);
        $this->assertFalse($this->part_of($policyb, 'assign', 'intro')['enabled']);
    }

    /**
     * Without any configuration the policy is disabled with every activity off.
     */
    public function test_defaults_to_disabled_policy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $policy = image_policy_builder::build();

        $this->assertSame(activities::MODE_DISABLED, $policy['mode']);
        $this->assertFalse($policy['overridecourse']);
        $this->assertFalse($policy['overrideactivity']);
        $this->assertCount(count(activities::get_definitions()), $policy['activities']);
        foreach ($policy['activities'] as $activity) {
            $this->assertFalse($activity['enabled']);
        }
    }
}
