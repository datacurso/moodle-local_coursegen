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

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\template;
use local_coursegen\local\service\supported_activity_types;
use local_coursegen\local\service\template_content_generator;

/**
 * The activity types a template works with: every AI-supported module that is
 * installed and enabled, for every template alike.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\supported_activity_types
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class supported_activity_types_test extends \advanced_testcase {
    /**
     * Only modules the AI service has a contract for are listed.
     */
    public function test_installed_lists_only_ai_supported_modules(): void {
        $this->resetAfterTest();

        $installed = supported_activity_types::installed();

        $this->assertNotEmpty($installed);
        $unsupported = array_diff($installed, template_content_generator::AI_SUPPORTED_TYPES);
        $this->assertSame([], $unsupported);
        $this->assertContains('page', $installed);
        $this->assertContains('forum', $installed);
    }

    /**
     * A module with no AI content contract is never a supported type.
     */
    public function test_a_module_without_an_ai_contract_is_not_supported(): void {
        $this->resetAfterTest();

        $installed = supported_activity_types::installed();
        $ltisupported = supported_activity_types::is_supported('lti');
        $pagesupported = supported_activity_types::is_supported('page');

        $this->assertNotContains('lti', $installed);
        $this->assertFalse($ltisupported);
        $this->assertTrue($pagesupported);
    }

    /**
     * The list is sorted, so the professor-side catalog is stable.
     */
    public function test_installed_is_sorted(): void {
        $this->resetAfterTest();

        $installed = supported_activity_types::installed();
        $sorted = $installed;
        sort($sorted);

        $this->assertSame($sorted, $installed);
    }

    /**
     * The professor-side catalog offers every supported type, even for a
     * template row saved by an older version with a narrower list.
     */
    public function test_professor_catalog_ignores_a_narrowing_legacy_saved_list(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $template = new template(0, (object) [
            'name' => 'Legacy list',
            'courseid' => $course->id,
            'allowedtypes' => '["forum"]',
        ]);
        $template->create();

        $result = get_template_structure::execute((int) $template->get('id'));

        $modnames = array_column($result['allowedactivities'], 'modname');
        sort($modnames);
        $installed = supported_activity_types::installed();
        $this->assertSame($installed, $modnames);
    }
}
