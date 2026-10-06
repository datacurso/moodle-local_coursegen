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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\tenancy;
use local_coursegen\local\tenant_config;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Tenant boundary of the system instruction used to start a course planning.
 *
 * The AI client is mocked, so no network request is ever performed. The
 * external class loads lib/externallib.php, which requires each test to run
 * in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_course_planning
 * @runTestsInSeparateProcesses
 */
final class start_course_planning_tenant_test extends \advanced_testcase {
    /**
     * Any accidental real API call must fail fast instead of reaching the network.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setAdminUser();
        tenant_config::set('datacurso_service_url', 'https://invalid.invalid', tenancy::get_tenant_id());
    }

    /**
     * Reset the injected client between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Injects a client whose planning request succeeds and records the payload it received.
     *
     * @param array|null $captured Filled with the payload sent to /course/init.
     * @return void
     */
    private function inject_successful_client(?array &$captured): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();
        $client->method('get_base_url')->willReturn('https://ai.example.com/api/v1');
        $client->method('request')->willReturnCallback(function (string $method, string $path, array $payload) use (&$captured) {
            $captured = $payload;
            return ['thread_id' => 'thread-1'];
        });
        api_client_factory::set_test_client($client);
    }

    /**
     * An instruction of another tenant is rejected before any planning request.
     */
    public function test_other_tenant_instruction_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $othertenantid = (int) $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_tenant()->id;
        $other = system_instruction_service::create(['name' => 'Private', 'content' => 'Secret.'], $othertenantid);

        $captured = null;
        $this->inject_successful_client($captured);

        try {
            start_course_planning::execute('Create a course about volcanoes', 'en', false, (int) $other->get('id'));
            $this->fail('An instruction of another tenant must be rejected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        }
        $this->assertNull($captured, 'No planning request must be sent when the instruction is rejected.');
    }

    /**
     * A legacy instruction without a real tenant (tenant 0) is not shared with anyone and is rejected.
     */
    public function test_tenantless_instruction_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $legacyid = (int) $DB->insert_record('local_coursegen_system_instruction', (object) [
            'name' => 'Legacy', 'content' => 'Legacy rules.', 'deleted' => 0, 'tenantid' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);

        $captured = null;
        $this->inject_successful_client($captured);

        $this->expectException(\invalid_parameter_exception::class);
        start_course_planning::execute('Create a course about volcanoes', 'en', false, $legacyid);
    }

    /**
     * An instruction of the user's own tenant is accepted.
     */
    public function test_own_tenant_instruction_accepted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $own = system_instruction_service::create(['name' => 'Own', 'content' => 'Own rules.'], tenancy::get_tenant_id());

        $captured = null;
        $this->inject_successful_client($captured);

        $result = start_course_planning::execute('Create a course about volcanoes', 'en', false, (int) $own->get('id'));

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('Own rules.', $captured['instructions']);
    }
}
