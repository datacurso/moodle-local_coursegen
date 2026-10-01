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
use local_coursegen\local\image_generation\image_policy_builder;

/**
 * Tests for the image generation policy sent to the AI service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\image_generation\image_policy_builder
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\image_generation\image_policy_builder::class)]
final class image_policy_builder_test extends \advanced_testcase {
    /**
     * Build the policy and index its activities (and their parts) by id.
     *
     * @return array Policy with 'activities' keyed by activity id and each 'parts' keyed by part id.
     */
    private function build_indexed(): array {
        $policy = image_policy_builder::build();
        $activities = [];
        foreach ($policy['activities'] as $activity) {
            $activity['parts'] = array_column($activity['parts'], null, 'id');
            $activities[$activity['id']] = $activity;
        }
        $policy['activities'] = $activities;

        return $policy;
    }

    /**
     * Without configuration the policy is disabled and nothing is enabled.
     */
    public function test_unconfigured_policy_is_disabled(): void {
        $this->resetAfterTest();

        $policy = image_policy_builder::build();

        $this->assertSame(activities::MODE_DISABLED, $policy['mode']);
        $this->assertFalse($policy['overridecourse']);
        $this->assertFalse($policy['overrideactivity']);

        $definitions = activities::get_definitions();
        $this->assertCount(count($definitions), $policy['activities']);
        $this->assertSame(array_column($definitions, 'id'), array_column($policy['activities'], 'id'));

        foreach ($policy['activities'] as $index => $activity) {
            $this->assertFalse($activity['enabled'], $activity['id'] . ' must be disabled by default.');
            $this->assertSame(
                array_column($definitions[$index]['parts'], 'id'),
                array_column($activity['parts'], 'id')
            );
            foreach ($activity['parts'] as $part) {
                $this->assertFalse($part['enabled']);
                $this->assertSame(0, $part['maximages']);
            }
        }
    }

    /**
     * The global mode and the override flags come from the plugin settings.
     *
     * @param string $mode Configured generation mode.
     * @param string $overridecourse Configured course override flag.
     * @param string $overrideactivity Configured activity override flag.
     * @param bool $expectedcourse Expected course override.
     * @param bool $expectedactivity Expected activity override.
     * @dataProvider global_settings_provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('global_settings_provider')]
    public function test_global_mode_and_overrides(
        string $mode,
        string $overridecourse,
        string $overrideactivity,
        bool $expectedcourse,
        bool $expectedactivity
    ): void {
        $this->resetAfterTest();

        set_config('generationmode', $mode, 'local_coursegen');
        set_config('overridecourse', $overridecourse, 'local_coursegen');
        set_config('overrideactivity', $overrideactivity, 'local_coursegen');

        $policy = image_policy_builder::build();

        $this->assertSame($mode, $policy['mode']);
        $this->assertSame($expectedcourse, $policy['overridecourse']);
        $this->assertSame($expectedactivity, $policy['overrideactivity']);
    }

    /**
     * Global settings combinations.
     *
     * @return array<string, array{0:string,1:string,2:string,3:bool,4:bool}>
     */
    public static function global_settings_provider(): array {
        return [
            'auto with both overrides' => [activities::MODE_AUTO, '1', '1', true, true],
            'manual with course override' => [activities::MODE_MANUAL, '1', '0', true, false],
            'manual with activity override' => [activities::MODE_MANUAL, '0', '1', false, true],
            'disabled without overrides' => [activities::MODE_DISABLED, '0', '0', false, false],
        ];
    }

    /**
     * Per-activity and per-part flags and limits are read from their own settings.
     */
    public function test_activity_and_part_settings(): void {
        $this->resetAfterTest();

        set_config('generationmode', activities::MODE_MANUAL, 'local_coursegen');
        set_config('enableimgassign', 1, 'local_coursegen');
        set_config('enableimgassign_intro', 1, 'local_coursegen');
        set_config('maximgassign_intro', 3, 'local_coursegen');
        // A limit stored for a disabled part is still reported; a non-positive limit means none.
        set_config('maximgassign_instructions', 2, 'local_coursegen');
        set_config('enableimgpage_page', 1, 'local_coursegen');
        set_config('maximgpage_page', -4, 'local_coursegen');

        $policy = $this->build_indexed();

        $assign = $policy['activities']['assign'];
        $this->assertTrue($assign['enabled']);
        $this->assertTrue($assign['parts']['intro']['enabled']);
        $this->assertSame(3, $assign['parts']['intro']['maximages']);
        $this->assertFalse($assign['parts']['instructions']['enabled']);
        $this->assertSame(2, $assign['parts']['instructions']['maximages']);

        // A part can be enabled while its activity stays disabled; the policy reports both as stored.
        $page = $policy['activities']['page'];
        $this->assertFalse($page['enabled']);
        $this->assertTrue($page['parts']['page']['enabled']);
        $this->assertSame(0, $page['parts']['page']['maximages']);
        $this->assertFalse($page['parts']['intro']['enabled']);

        // Untouched activities stay disabled.
        $this->assertFalse($policy['activities']['book']['enabled']);
        $this->assertFalse($policy['activities']['book']['parts']['chapter']['enabled']);
    }

    /**
     * Settings saved through the management web service are reflected in the policy,
     * including the per-part image limit cap and the reset of omitted parts.
     */
    public function test_policy_reflects_settings_saved_by_management_service(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('enableimgpage_intro', 1, 'local_coursegen');
        set_config('maximgpage_intro', 2, 'local_coursegen');

        manage_image_generation::execute(1, 0, activities::MODE_AUTO, [
            [
                'id' => 'page',
                'enabled' => 1,
                'prompt' => '',
                'parts' => [
                    ['id' => 'page', 'enabled' => 1, 'maximages' => 9],
                ],
            ],
            [
                'id' => 'quiz',
                'enabled' => 0,
                'prompt' => '',
                'parts' => [
                    ['id' => 'questions', 'enabled' => 1, 'maximages' => 0],
                ],
            ],
        ]);

        $policy = $this->build_indexed();

        $this->assertSame(activities::MODE_AUTO, $policy['mode']);
        $this->assertTrue($policy['overridecourse']);
        $this->assertFalse($policy['overrideactivity']);

        $page = $policy['activities']['page'];
        $this->assertTrue($page['enabled']);
        $this->assertTrue($page['parts']['page']['enabled']);
        $this->assertSame(5, $page['parts']['page']['maximages'], 'The per-part limit is capped at 5 images.');
        // The intro part was not submitted: it is reset.
        $this->assertFalse($page['parts']['intro']['enabled']);
        $this->assertSame(0, $page['parts']['intro']['maximages']);

        $quiz = $policy['activities']['quiz'];
        $this->assertFalse($quiz['enabled']);
        $this->assertTrue($quiz['parts']['questions']['enabled']);
        $this->assertSame(0, $quiz['parts']['questions']['maximages']);
        $this->assertFalse($quiz['parts']['intro']['enabled']);

        // Activities not submitted are left untouched (still unconfigured).
        $this->assertFalse($policy['activities']['assign']['enabled']);
    }
}
