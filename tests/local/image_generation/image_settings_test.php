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
 * Tests for the per-tenant image generation settings.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\image_generation\image_settings
 */
final class image_settings_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Creates a tenant and returns its id.
     *
     * @return int
     */
    private function create_tenant(): int {
        $this->require_tool_tenant();
        return (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
    }

    /**
     * A payload enabling the assignment activity with one part, as the web service receives it.
     *
     * @param int $maximages Max images submitted for the intro part.
     * @return array
     */
    private function assign_payload(int $maximages = 2): array {
        return [
            'generationmode' => activities::MODE_MANUAL,
            'overridecourse' => 1,
            'overrideactivity' => 0,
            'activities' => [
                [
                    'id' => 'assign',
                    'enabled' => 1,
                    'parts' => [
                        ['id' => 'intro', 'enabled' => 1, 'maximages' => $maximages],
                    ],
                ],
            ],
        ];
    }

    /**
     * Without any stored value every key resolves to its default.
     */
    public function test_defaults_when_nothing_is_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        $settings = image_settings::get_settings($tenantid);

        $this->assertSame(activities::MODE_DISABLED, $settings['generationmode']);
        $this->assertSame(0, $settings['overridecourse']);
        $this->assertSame(0, $settings['overrideactivity']);
        $this->assertSame(0, $settings['enableimgassign']);
        $this->assertSame(0, $settings['enableimgassign_intro']);
        $this->assertSame(0, $settings['maximgassign_intro']);
        // Every key of every definition is present.
        foreach (activities::get_definitions() as $definition) {
            $this->assertArrayHasKey($definition['configenable'], $settings);
            foreach ($definition['parts'] ?? [] as $part) {
                $this->assertArrayHasKey($part['configenable'], $settings);
                $this->assertArrayHasKey($part['configmaximages'], $settings);
            }
        }
    }

    /**
     * Each tenant has its own values: another tenant and config_plugins never leak in.
     */
    public function test_tenants_are_independent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenanta = $this->create_tenant();
        $tenantb = $this->create_tenant();
        set_config('generationmode', activities::MODE_AUTO, 'local_coursegen');

        image_settings::save_settings($this->assign_payload(3), $tenanta);

        $settingsa = image_settings::get_settings($tenanta);
        $settingsb = image_settings::get_settings($tenantb);

        $this->assertSame(activities::MODE_MANUAL, $settingsa['generationmode']);
        $this->assertSame(3, $settingsa['maximgassign_intro']);
        $this->assertSame(activities::MODE_DISABLED, $settingsb['generationmode']);
        $this->assertSame(0, $settingsb['maximgassign_intro']);
    }

    /**
     * Saving stores every value for the tenant only, switching off unsubmitted parts, and leaves config_plugins untouched.
     */
    public function test_save_stores_tenant_values_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        image_settings::save_settings($this->assign_payload(2), $tenantid);

        $this->assertFalse(get_config('local_coursegen', 'generationmode'));
        $this->assertSame(activities::MODE_MANUAL, tenant_config::get_raw('generationmode', $tenantid));
        $this->assertSame('1', tenant_config::get_raw('overridecourse', $tenantid));
        $this->assertSame('0', tenant_config::get_raw('overrideactivity', $tenantid));
        $this->assertSame('1', tenant_config::get_raw('enableimgassign', $tenantid));
        $this->assertSame('1', tenant_config::get_raw('enableimgassign_intro', $tenantid));
        $this->assertSame('2', tenant_config::get_raw('maximgassign_intro', $tenantid));
        // A part of the submitted activity that was not submitted is switched off.
        $this->assertSame('0', tenant_config::get_raw('enableimgassign_instructions', $tenantid));
        $this->assertSame('0', tenant_config::get_raw('maximgassign_instructions', $tenantid));
        $this->assertSame(activities::MODE_MANUAL, image_settings::get_settings($tenantid)['generationmode']);
    }

    /**
     * The maximum number of images per part is capped at 5 and negative values become 0.
     */
    public function test_maximages_is_capped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        image_settings::save_settings($this->assign_payload(9), $tenantid);
        $this->assertSame(5, image_settings::get_settings($tenantid)['maximgassign_intro']);

        image_settings::save_settings($this->assign_payload(-4), $tenantid);
        $this->assertSame(0, image_settings::get_settings($tenantid)['maximgassign_intro']);
    }

    /**
     * Activities that are not part of the definitions, and activities not submitted, are ignored.
     */
    public function test_unknown_and_missing_activities_are_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $tenantid = $this->create_tenant();

        tenant_config::set('enableimgbook', 1, $tenantid);
        $payload = $this->assign_payload();
        $payload['activities'][] = ['id' => 'notamodule', 'enabled' => 1, 'parts' => []];

        image_settings::save_settings($payload, $tenantid);

        $this->assertNull(tenant_config::get_raw('enableimgnotamodule', $tenantid));
        $this->assertSame('1', tenant_config::get_raw('enableimgbook', $tenantid));
    }

    /**
     * An unknown generation mode is rejected.
     */
    public function test_invalid_generation_mode_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = $this->assign_payload();
        $payload['generationmode'] = 'sometimes';

        $this->expectException(\invalid_parameter_exception::class);
        image_settings::save_settings($payload, $this->create_tenant());
    }
}
