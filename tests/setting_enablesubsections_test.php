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

use local_coursegen\admin\setting_enablesubsections;
use local_coursegen\local\service\course_planning_service;

/**
 * Tests for the global "Enable subsections" setting and its server-side gate.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\admin\setting_enablesubsections
 * @covers     \local_coursegen\local\service\course_planning_service
 */
final class setting_enablesubsections_test extends \advanced_testcase {
    /**
     * Change the enabled state of the subsection activity module.
     *
     * @param int $visible 1 to enable the module, 0 to disable it.
     * @return void
     */
    private function set_subsection_module_visibility(int $visible): void {
        global $DB;

        $DB->set_field('modules', 'visible', $visible, ['name' => 'subsection']);
        \core_plugin_manager::reset_caches();
    }

    /**
     * Whether mod_subsection is installed on this site.
     *
     * @return bool
     */
    private function subsection_module_installed(): bool {
        return \core_plugin_manager::instance()->get_plugin_info('mod_subsection') !== null;
    }

    /**
     * Build the admin setting object exactly as settings.php registers it.
     *
     * @return setting_enablesubsections
     */
    private function build_setting(): setting_enablesubsections {
        return new setting_enablesubsections(
            'local_coursegen/enablesubsections',
            get_string('enablesubsections', 'local_coursegen'),
            get_string('enablesubsections_desc', 'local_coursegen'),
            0
        );
    }

    /**
     * MDL-INT-010: The setting is off by default.
     */
    public function test_setting_is_off_by_default(): void {
        $this->assertEmpty(get_config('local_coursegen', 'enablesubsections'));
    }

    /**
     * MDL-INT-010: Enabling the setting while the Subsection module is disabled
     * is rejected: the value returns to off and the message pointing at the
     * module is queued.
     */
    public function test_enabling_with_module_disabled_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->set_subsection_module_visibility(0);

        $result = $this->build_setting()->write_setting('1');

        $this->assertSame('', $result);
        $this->assertSame('0', (string)get_config('local_coursegen', 'enablesubsections'));

        $messages = array_map(
            static function (\core\output\notification $notification): string {
                return $notification->get_message();
            },
            \core\notification::fetch()
        );
        $this->assertContains(
            get_string('enablesubsections_error_moddisabled', 'local_coursegen'),
            $messages
        );
    }

    /**
     * MDL-INT-010: Enabling the setting with the Subsection module enabled is
     * saved correctly.
     */
    public function test_enabling_with_module_enabled_is_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!$this->subsection_module_installed()) {
            $this->markTestSkipped('mod_subsection no esta instalado en este sitio.');
        }
        $this->set_subsection_module_visibility(1);

        $result = $this->build_setting()->write_setting('1');

        $this->assertSame('', $result);
        $this->assertSame('1', (string)get_config('local_coursegen', 'enablesubsections'));
    }

    /**
     * MDL-INT-011: The site subsections availability requires both the global
     * setting and the Subsection module; either one missing disables it.
     */
    public function test_subsections_availability_requires_setting_and_module(): void {
        $this->resetAfterTest();

        // Global setting off: unavailable no matter the module state.
        unset_config('enablesubsections', 'local_coursegen');
        $this->assertFalse(course_planning_service::subsections_available());

        // Setting on but module disabled: still unavailable.
        set_config('enablesubsections', 1, 'local_coursegen');
        $this->set_subsection_module_visibility(0);
        $this->assertFalse(course_planning_service::subsections_available());

        // Setting on and module enabled: available.
        if (!$this->subsection_module_installed()) {
            $this->markTestSkipped('mod_subsection no esta instalado en este sitio.');
        }
        $this->set_subsection_module_visibility(1);
        $this->assertTrue(course_planning_service::subsections_available());
    }
}
