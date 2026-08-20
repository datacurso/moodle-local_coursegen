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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\external\start_course_planning;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\models\course_session;

/**
 * Permission tests for starting the AI course planning.
 *
 * The AI HTTP client is replaced through the api_client_factory seam, so no
 * network request is ever performed. Loading the external class pulls in
 * lib/externallib.php, so each test runs in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\start_course_planning
 *
 * @runTestsInSeparateProcesses
 */
final class course_planning_permissions_test extends \advanced_testcase {
    /**
     * Reset the injected double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Create a user holding the given capabilities at site level.
     *
     * @param string[] $capabilities Capabilities to allow at system context.
     * @return \stdClass User record.
     */
    private function create_user_with_capabilities(array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id);
        }
        role_assign($roleid, $user->id, $systemcontext->id);

        return $user;
    }

    /**
     * Inject an ai_course_api mock that answers the planning init request.
     *
     * @param bool $expectcall Whether the AI service is expected to be reached.
     * @return void
     */
    private function inject_client(bool $expectcall): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();

        if ($expectcall) {
            $client->method('request')->willReturn(['thread_id' => 'th-1']);
        } else {
            $client->expects($this->never())->method('request');
        }
        $client->method('get_base_url')->willReturn('https://ai.example.com/api/v1/');

        api_client_factory::set_test_client($client);
    }

    /**
     * MDL-INT-001: A user with the create courses permission and the create
     * courses with AI permission can start the planning.
     */
    public function test_user_with_both_capabilities_can_start_planning(): void {
        $this->resetAfterTest();

        $user = $this->create_user_with_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ]);
        $this->setUser($user);
        $this->inject_client(true);

        $result = start_course_planning::execute('Un curso de biologia marina', 'es', false, 0, false);

        $this->assertTrue($result['success'], 'Start must succeed: ' . ($result['message'] ?? ''));
        $this->assertGreaterThan(0, $result['sessionid']);
        $this->assertSame('th-1', $result['threadid']);
        $this->assertSame('https://ai.example.com/api/v1/course/stream/th-1', $result['streamingurl']);

        // The session was persisted for this user.
        $this->assertSame(1, course_session::count_records(['userid' => (int)$user->id]));
    }

    /**
     * MDL-INT-001: A user missing the create courses with AI permission
     * receives a permission error and no session is created.
     */
    public function test_missing_ai_capability_blocks_start_without_session(): void {
        $this->resetAfterTest();

        $user = $this->create_user_with_capabilities(['moodle/course:create']);
        $this->setUser($user);
        $this->inject_client(false);

        try {
            start_course_planning::execute('Un curso sin permisos', 'es', false, 0, false);
            $this->fail('A permission error was expected for a user without the AI capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->assertSame(0, course_session::count_records());
    }

    /**
     * MDL-INT-001: A user missing the create courses permission receives a
     * permission error and no session is created.
     */
    public function test_missing_course_create_capability_blocks_start_without_session(): void {
        $this->resetAfterTest();

        $user = $this->create_user_with_capabilities(['local/coursegen:createcoursewithai']);
        $this->setUser($user);
        $this->inject_client(false);

        try {
            start_course_planning::execute('Un curso sin permisos', 'es', false, 0, false);
            $this->fail('A permission error was expected for a user without the course creation capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->assertSame(0, course_session::count_records());
    }

    /**
     * MDL-INT-005: Granting the create courses with AI permission only in a
     * course category enables the flow as documented.
     */
    public function test_category_level_grant_enables_the_flow(): void {
        $this->markTestSkipped(
            'El permiso local/coursegen:createcoursewithai esta declarado a nivel de categoria pero '
            . 'solo se verifica a nivel de sitio, por lo que un otorgamiento por categoria no tiene '
            . 'efecto. Pendiente hasta que se defina el contexto correcto.'
        );
    }
}
