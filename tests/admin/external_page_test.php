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

namespace local_coursegen\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for the factory of the plugin admin external pages.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\admin\external_page
 */
final class external_page_test extends \advanced_testcase {
    use \local_coursegen\tests\requires_workplace;

    #[\Override]
    protected function tearDown(): void {
        external_page::reset_for_testing();
        parent::tearDown();
    }

    /**
     * Without tool_wp a core admin external page gated by the capability is built.
     */
    public function test_falls_back_to_core_page_without_workplace(): void {
        $this->resetAfterTest();
        external_page::simulate_workplace_unavailable_for_testing();

        $page = external_page::create(
            'local_coursegen_test_page',
            'Test page',
            'https://example.com/local/coursegen/test.php',
            'local/coursegen:manageimagegeneration',
            null,
            true
        );

        $this->assertSame(\admin_externalpage::class, get_class($page));
        $this->assertSame(['local/coursegen:manageimagegeneration'], $page->req_capability);
        $this->assertTrue($page->hidden);

        $this->setAdminUser();
        $this->assertTrue($page->check_access());
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($page->check_access());
    }

    /**
     * With tool_wp a Workplace page is built, gated by the capability when no access check is given.
     */
    public function test_builds_workplace_page_with_capability_check(): void {
        $this->resetAfterTest();
        $this->require_tool_wp();

        $page = external_page::create(
            'local_coursegen_test_page',
            'Test page',
            'https://example.com/local/coursegen/test.php',
            'local/coursegen:manageimagegeneration'
        );

        $this->assertInstanceOf(\tool_wp\admin_externalpage::class, $page);
        $this->assertFalse($page->hidden);

        $this->setAdminUser();
        $this->assertTrue($page->check_access());
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($page->check_access());
    }

    /**
     * With tool_wp a custom access check replaces the capability check.
     */
    public function test_workplace_page_uses_custom_access_check(): void {
        $this->resetAfterTest();
        $this->require_tool_wp();

        $page = external_page::create(
            'local_coursegen_test_page',
            'Test page',
            'https://example.com/local/coursegen/test.php',
            'local/coursegen:manageimagegeneration',
            static fn(): bool => true
        );

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertTrue($page->check_access());
    }
}
