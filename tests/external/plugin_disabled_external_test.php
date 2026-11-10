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

namespace local_coursegen\external;

/**
 * Web services of local_coursegen when the plugin is switched off in the Datacurso AI provider.
 *
 * Every web service declared in db/services.php, except the one behind the plugin's own
 * administration page, refuses with plugindisabled before doing anything else while the plugin
 * is disabled, and reaches its own checks as before while it is enabled. Each function is called
 * as a user without capabilities with placeholder arguments, so the enabled calls stop at the
 * function's own validation and never reach the network.
 *
 * Several external classes still load lib/externallib.php, which requires process isolation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\activity_feedback
 * @covers     \local_coursegen\external\activity_file_upload
 * @covers     \local_coursegen\external\activity_filepicker_init
 * @covers     \local_coursegen\external\course_planning_feedback
 * @covers     \local_coursegen\external\courseai_filepicker_init
 * @covers     \local_coursegen\external\courseai_syllabus_upload
 * @covers     \local_coursegen\external\create_course
 * @covers     \local_coursegen\external\create_mod
 * @covers     \local_coursegen\external\create_mod_stream
 * @covers     \local_coursegen\external\get_course_session_state
 * @covers     \local_coursegen\external\get_course_settings
 * @covers     \local_coursegen\external\start_course_planning
 * @runTestsInSeparateProcesses
 */
final class plugin_disabled_external_test extends \advanced_testcase {
    /**
     * Skip the test when the Datacurso AI provider, whose enable gate the tested code calls, is not installed.
     *
     * The CI pipeline does not install declared dependencies.
     */
    private function require_datacurso_provider(): void {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /** @var string[] Web services behind the plugin's own administration pages, available while disabled. */
    private const ADMIN_FUNCTIONS = ['local_coursegen_manage_image_generation'];

    /**
     * Log in a user without capabilities, point the service URL nowhere and load the legacy external API.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/externallib.php');
        $this->setUser($this->getDataGenerator()->create_user());
        // The service URL is a per-tenant setting on Workplace.
        \local_coursegen\local\tenant_config::set(
            'datacurso_service_url',
            'https://invalid.invalid',
            \local_coursegen\local\tenancy::get_tenant_id()
        );
    }

    /**
     * External classes of every web service declared in db/services.php, keyed by function name.
     *
     * @param bool $includeadmin Whether to include the administration web services.
     * @return array<string, string>
     */
    private function declared_classes(bool $includeadmin): array {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/local/coursegen/db/services.php');
        $classes = [];
        foreach ($functions as $name => $definition) {
            if (!$includeadmin && in_array($name, self::ADMIN_FUNCTIONS, true)) {
                continue;
            }
            $classes[$name] = ltrim($definition['classname'], '\\');
        }
        return $classes;
    }

    /**
     * Call execute() of an external class with placeholder arguments and report how it ended.
     *
     * Placeholders are the declared defaults, otherwise null for nullable parameters, otherwise an
     * empty value of the declared type.
     *
     * @param string $classname External function class.
     * @return string|null The moodle_exception error code, the class of any other failure, or null on success.
     */
    private function failure_of(string $classname): ?string {
        $method = new \ReflectionMethod($classname, 'execute');
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typename = $type instanceof \ReflectionNamedType ? $type->getName() : 'int';
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else if ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
            } else {
                $arguments[] = match ($typename) {
                    'string' => '',
                    'bool' => false,
                    'array' => [],
                    'float' => 0.0,
                    default => 0,
                };
            }
        }
        try {
            $classname::execute(...$arguments);
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        } catch (\Throwable $e) {
            return get_class($e);
        }
        return null;
    }

    /**
     * Disabled: every feature web service refuses with plugindisabled.
     *
     * @return void
     */
    public function test_feature_web_services_refuse_when_disabled(): void {
        $this->require_datacurso_provider();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', false);

        $classes = $this->declared_classes(false);
        $this->assertCount(12, $classes);
        foreach ($classes as $name => $classname) {
            $this->assertSame('plugindisabled', $this->failure_of($classname), $name);
        }
    }

    /**
     * Enabled: every web service reaches its own checks instead of the plugindisabled refusal.
     *
     * @return void
     */
    public function test_web_services_are_not_refused_when_enabled(): void {
        $this->require_datacurso_provider();
        foreach ($this->declared_classes(true) as $name => $classname) {
            $this->assertNotSame('plugindisabled', $this->failure_of($classname), $name);
        }
        // The functions' own validation reports the placeholder arguments through debugging().
        $this->resetDebugging();
    }

    /**
     * Disabled: the administration web service stays available, like the plugin's administration pages.
     *
     * @return void
     */
    public function test_admin_web_service_is_not_refused_when_disabled(): void {
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursegen', false);

        $this->assertNotSame('plugindisabled', $this->failure_of(manage_image_generation::class));
    }
}
