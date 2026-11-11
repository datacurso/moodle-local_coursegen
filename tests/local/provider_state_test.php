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

namespace local_coursegen\local;

/**
 * Tests for the plugin state read from the Datacurso AI provider on every page.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\provider_state
 */
final class provider_state_test extends \advanced_testcase {
    /**
     * Skip the test when the Datacurso AI provider that keeps the plugin state is not installed.
     *
     * The CI pipeline does not install declared dependencies.
     */
    private function require_datacurso_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * A provider too old to keep the plugin state leaves the plugin disabled instead of failing.
     */
    public function test_missing_provider_class_reports_the_plugin_disabled(): void {
        $this->assertFalse(provider_state::is_enabled('\\aiprovider_datacurso\\local\\missing_plugin_settings'));
    }

    /**
     * The plugin is enabled while the provider keeps it enabled.
     */
    public function test_follows_the_provider_when_enabled(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', true);

        $this->assertTrue(provider_state::is_enabled());
    }

    /**
     * The plugin is disabled while the provider disables it.
     */
    public function test_follows_the_provider_when_disabled(): void {
        $this->require_datacurso_provider();
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', false);

        $this->assertFalse(provider_state::is_enabled());
    }

    /**
     * The callbacks that run on every page, or from the cron, never call the provider class directly.
     *
     * A direct call throws "Class not found" on a site whose provider predates the class, which
     * breaks every page, the login page included, before the upgrade can be reached.
     *
     * @dataProvider every_page_callbacks_provider
     * @param string $file Path of the file, relative to the plugin root.
     */
    public function test_every_page_callbacks_do_not_call_the_provider_class(string $file): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/coursegen/' . $file);

        $this->assertStringNotContainsString('plugin_settings::', $source);
        $this->assertStringContainsString('provider_state::is_enabled()', $source);
    }

    /**
     * Files whose callbacks run on every page or from the cron.
     *
     * @return array
     */
    public static function every_page_callbacks_provider(): array {
        return [
            'footer hook' => ['classes/hook/chat_hook.php'],
            'header hook' => ['classes/hook/mycourses_header_hook.php'],
            'file serving and Workplace launcher' => ['lib.php'],
        ];
    }
}
