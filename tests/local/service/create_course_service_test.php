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

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * Tests for the default category resolution of create_course_service in a multi-tenant site.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_service_test extends \advanced_testcase {
    /**
     * Logs in as admin allocated to a new tenant and returns the tenant id.
     *
     * @param int|null $categoryid Category of the tenant, or null for none.
     * @return int
     */
    private function login_as_admin_in_tenant(?int $categoryid): int {
        global $USER;

        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_tenant');
        $record = $categoryid ? ['categoryid' => $categoryid] : [];
        $tenantid = (int) $generator->create_tenant($record)->id;
        $generator->allocate_user((int) $USER->id, $tenantid);
        return $tenantid;
    }

    /**
     * Creates a planning session for the current user.
     *
     * @return course_session
     */
    private function create_session(): course_session {
        global $USER;
        return course_session_service::create_from_form_data(
            (object) ['fullname' => 'Planned course'],
            (int) $USER->id,
            'thread-test-1'
        );
    }

    /**
     * Without an explicit category the course lands in the category of the user's tenant.
     */
    public function test_prefers_tenant_category(): void {
        global $DB;
        $this->resetAfterTest();

        $tenantcategory = $this->getDataGenerator()->create_category();
        $this->login_as_admin_in_tenant((int) $tenantcategory->id);
        $session = $this->create_session();

        $settings = create_course_service::get_course_settings($session, []);
        $this->assertSame((int) $tenantcategory->id, (int) $settings['category']);

        $result = create_course_service::create_course($session, [], []);

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertEquals($tenantcategory->id, $course->category);
    }

    /**
     * When the tenant has no category the site default category is used.
     */
    public function test_falls_back_to_site_default_when_tenant_has_no_category(): void {
        global $DB;
        $this->resetAfterTest();

        $this->login_as_admin_in_tenant(null);
        $session = $this->create_session();
        $defaultcategoryid = (int) \core_course_category::get_default()->id;

        $settings = create_course_service::get_course_settings($session, []);
        $this->assertSame($defaultcategoryid, (int) $settings['category']);

        $result = create_course_service::create_course($session, [], []);

        $this->assertTrue($result['success'], 'Creation must succeed: ' . ($result['message'] ?? ''));
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertEquals($defaultcategoryid, $course->category);
    }
}
