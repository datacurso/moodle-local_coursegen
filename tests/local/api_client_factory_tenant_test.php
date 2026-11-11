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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\service\ai_course_api_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Tests that the API client factory resolves service URLs per tenant.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\api_client_factory
 */
final class api_client_factory_tenant_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    /**
     * Removes the injected test double after each test.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

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
     * Injects a mock client so no real HTTP client is built.
     */
    private function inject_test_client(): void {
        api_client_factory::set_test_client($this->createMock(ai_course_api::class));
    }

    /**
     * The URLs of a tenant reach the client for its users only; config_plugins values are never used.
     */
    public function test_tenant_override_urls_reach_client(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('datacurso_service_url', 'https://site-us.example.com/api/v1', 'local_coursegen');
        [$tenanta, $usera] = $this->create_tenant_with_user();
        [, $userb] = $this->create_tenant_with_user();
        tenant_config::set('datacurso_service_url', 'https://tenant-us.example.com/api/v1', $tenanta);
        tenant_config::set('datacurso_service_url_eu', 'https://tenant-eu.example.com/api/v1', $tenanta);
        $this->inject_test_client();

        $this->setUser($usera);
        api_client_factory::ai_course_api_for_current_tenant();
        $this->assertSame([
            'baseurl' => 'https://tenant-us.example.com/api/v1',
            'baseurleu' => 'https://tenant-eu.example.com/api/v1',
        ], api_client_factory::get_last_urls());

        $this->setUser($userb);
        api_client_factory::ai_course_api_for_current_tenant();
        $this->assertSame(['baseurl' => null, 'baseurleu' => null], api_client_factory::get_last_urls());
    }

    /**
     * Empty or missing URLs are passed as null so the client falls back to its defaults.
     */
    public function test_empty_urls_are_passed_as_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        tenant_config::set('datacurso_service_url_eu', '', tenancy::get_tenant_id());
        $this->inject_test_client();

        api_client_factory::ai_course_api_for_current_tenant();

        $this->assertSame(['baseurl' => null, 'baseurleu' => null], api_client_factory::get_last_urls());
    }

    /**
     * The course API service builds its default client through the tenant-aware factory method.
     */
    public function test_ai_course_api_service_uses_tenant_urls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$tenanta, $usera] = $this->create_tenant_with_user();
        tenant_config::set('datacurso_service_url', 'https://tenant-us.example.com/api/v1', $tenanta);
        $this->inject_test_client();
        $this->setUser($usera);

        new ai_course_api_service();

        $this->assertSame('https://tenant-us.example.com/api/v1', api_client_factory::get_last_urls()['baseurl']);
    }
}
