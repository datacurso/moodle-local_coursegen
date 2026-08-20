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

namespace local_coursegen\mod_settings;

/**
 * Unit tests for workshop_settings — assessment criteria and initial phase handling.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\workshop_settings
 */
final class workshop_settings_test extends \advanced_testcase {
    /**
     * Create a workshop activity and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (workshop id).
     */
    private function make_workshop_cm(): object {
        // The workshop generator prepares draft file areas, which needs a real user.
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        return (object) ['coursemodule' => $workshop->cmid, 'instance' => $workshop->id];
    }

    /**
     * Get the current phase of a workshop straight from the DB.
     *
     * @param int $workshopid Workshop instance id.
     * @return int Phase code.
     */
    private function get_phase(int $workshopid): int {
        global $DB;
        return (int) $DB->get_field('workshop', 'phase', ['id' => $workshopid], MUST_EXIST);
    }

    /**
     * Accumulative criteria are inserted with description, grade and sort; blanks are skipped.
     */
    public function test_criteria_inserted_into_accumulative_form(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_workshop_cm();
        $modsettings = ['criteria' => [
            ['description' => 'Clarity', 'max_points' => 20],
            ['description' => '   ', 'max_points' => 5], // Blank -> skipped.
            ['description' => 'Originality'], // No max_points -> default 10.
        ]];

        (new workshop_settings($cm, $modsettings))->add_settings();

        $rows = $DB->get_records('workshopform_accumulative', ['workshopid' => $cm->instance], 'sort ASC');
        $this->assertCount(2, $rows);
        $rows = array_values($rows);
        $this->assertSame('Clarity', $rows[0]->description);
        $this->assertEquals(20, (int) $rows[0]->grade);
        $this->assertEquals(1, (int) $rows[0]->sort);
        $this->assertSame('Originality', $rows[1]->description);
        $this->assertEquals(10, (int) $rows[1]->grade);
        $this->assertEquals(2, (int) $rows[1]->sort);
    }

    /**
     * Valid non-setup phase tokens switch the workshop phase and fire phase_switched.
     *
     * @dataProvider valid_phase_provider
     * @param string $token Phase token from the AI payload.
     * @param int $expected Expected workshop phase code.
     */
    public function test_initial_phase_switches_workshop(string $token, int $expected): void {
        $this->resetAfterTest();

        $cm = $this->make_workshop_cm();
        $sink = $this->redirectEvents();

        (new workshop_settings($cm, ['initial_phase' => $token]))->add_settings();

        $this->assertSame($expected, $this->get_phase($cm->instance));
        $events = array_filter($sink->get_events(), static function ($event) {
            return $event instanceof \mod_workshop\event\phase_switched;
        });
        $sink->close();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals($cm->instance, $event->objectid);
    }

    /**
     * Valid non-setup phase tokens and their expected phase codes.
     *
     * @return array[] Token and expected phase pairs.
     */
    public static function valid_phase_provider(): array {
        return [
            'submission' => ['submission', 20],
            'assessment' => ['assessment', 30],
            'evaluation' => ['evaluation', 40],
        ];
    }

    /**
     * Setup, absent and null tokens leave the Moodle default phase without debugging.
     *
     * @dataProvider noop_phase_provider
     * @param array $modsettings The mod_settings payload.
     */
    public function test_initial_phase_setup_absent_or_null_keeps_default(array $modsettings): void {
        $this->resetAfterTest();

        $cm = $this->make_workshop_cm();

        (new workshop_settings($cm, $modsettings))->add_settings();

        $this->assertSame(10, $this->get_phase($cm->instance));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Payloads whose phase handling must be a silent no-op.
     *
     * @return array[] Payload variants.
     */
    public static function noop_phase_provider(): array {
        return [
            'setup token' => [['initial_phase' => 'setup']],
            'absent' => [[]],
            'null' => [['initial_phase' => null]],
        ];
    }

    /**
     * Unknown phase tokens (including unsupported "closed") keep setup and emit debugging.
     *
     * @dataProvider invalid_phase_provider
     * @param string $token Invalid phase token.
     */
    public function test_initial_phase_invalid_token_keeps_setup_and_debugs(string $token): void {
        $this->resetAfterTest();

        $cm = $this->make_workshop_cm();

        (new workshop_settings($cm, ['initial_phase' => $token]))->add_settings();

        $this->assertSame(10, $this->get_phase($cm->instance));
        $this->assertDebuggingCalled();
    }

    /**
     * Invalid phase tokens.
     *
     * @return array[] Token variants.
     */
    public static function invalid_phase_provider(): array {
        return [
            'closed unsupported' => ['closed'],
            'nonsense' => ['banana'],
        ];
    }

    /**
     * Phase handling never throws even when the phase switch fails at runtime:
     * a workshop whose instance record is gone triggers debugging, not an exception.
     */
    public function test_initial_phase_switch_failure_never_throws(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_workshop_cm();
        // Remove the workshop instance row while keeping the course module,
        // so the internal MUST_EXIST fetch fails during the phase switch.
        $DB->delete_records('workshop', ['id' => $cm->instance]);

        (new workshop_settings($cm, ['initial_phase' => 'submission']))->add_settings();

        $this->assertDebuggingCalled();
    }

    /**
     * Criteria and initial phase in the same payload are both applied.
     */
    public function test_criteria_and_phase_combined(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_workshop_cm();
        $modsettings = [
            'criteria' => [['description' => 'Depth', 'max_points' => 15]],
            'initial_phase' => 'submission',
        ];

        (new workshop_settings($cm, $modsettings))->add_settings();

        $this->assertSame(1, $DB->count_records('workshopform_accumulative', ['workshopid' => $cm->instance]));
        $this->assertSame(20, $this->get_phase($cm->instance));
    }
}
