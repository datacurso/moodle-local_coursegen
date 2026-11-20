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
     * The addresses of the site reach the client for the users of every tenant; tenant values are never read.
     */
    public function test_site_urls_reach_client_for_every_tenant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('datacurso_service_url', 'https://site-us.example.com/api/v1', 'local_coursegen');
        set_config('datacurso_service_url_eu', 'https://site-eu.example.com/api/v1', 'local_coursegen');
        [$tenanta, $usera] = $this->create_tenant_with_user();
        [, $userb] = $this->create_tenant_with_user();
        tenant_config::set('datacurso_service_url', 'https://tenant-us.example.com/api/v1', $tenanta);
        tenant_config::set('datacurso_service_url', 'https://default.example.com/api/v1', tenancy::get_default_tenant_id());
        $this->inject_test_client();
        $expected = ['baseurl' => 'https://site-us.example.com/api/v1', 'baseurleu' => 'https://site-eu.example.com/api/v1'];

        foreach ([$usera, $userb] as $user) {
            $this->setUser($user);
            api_client_factory::ai_course_api_for_current_tenant();
            $this->assertSame($expected, api_client_factory::get_last_urls());
        }
    }

    /**
     * Without site addresses the client gets null, so it uses the production service, even when a tenant (the
     * default one included) still holds an address from an earlier release.
     */
    public function test_empty_site_urls_are_passed_as_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('datacurso_service_url_eu', '', 'local_coursegen');
        tenant_config::set('datacurso_service_url', 'https://dev.example.com/api/v1', tenancy::get_default_tenant_id());
        [, $usera] = $this->create_tenant_with_user();
        $this->inject_test_client();
        $this->setUser($usera);

        api_client_factory::ai_course_api_for_current_tenant();

        $this->assertSame(['baseurl' => null, 'baseurleu' => null], api_client_factory::get_last_urls());
    }

    /**
     * The course API service builds its default client with the addresses of the site.
     */
    public function test_ai_course_api_service_uses_site_urls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('datacurso_service_url', 'https://site-us.example.com/api/v1', 'local_coursegen');
        [, $usera] = $this->create_tenant_with_user();
        $this->inject_test_client();
        $this->setUser($usera);

        new ai_course_api_service();

        $this->assertSame('https://site-us.example.com/api/v1', api_client_factory::get_last_urls()['baseurl']);
    }
}
