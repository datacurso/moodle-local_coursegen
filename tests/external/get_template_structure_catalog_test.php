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

/**
 * The activity catalog that get_template_structure returns to the template
 * editor: which types it lists, the shape of each entry and the order in
 * which they are listed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class get_template_structure_catalog_test extends \advanced_testcase {
    /**
     * The catalog of a freshly created template, as the webservice returns it.
     *
     * @return array
     */
    private function fetch_catalog(): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $record = (object) ['name' => 'Catalog fixture', 'courseid' => $course->id];
        $template = new template(0, $record);
        $template->create();
        $templateid = (int) $template->get('id');

        $result = get_template_structure::execute($templateid);
        return $result['allowedactivities'];
    }

    /**
     * The catalog lists exactly the installed types the plugin supports.
     */
    public function test_catalog_lists_the_installed_supported_types(): void {
        $catalog = $this->fetch_catalog();

        $listed = array_column($catalog, 'modname');
        $installed = supported_activity_types::installed();
        sort($listed);
        sort($installed);
        $this->assertSame($installed, $listed);
    }

    /**
     * Each entry carries the module name, its localised name, its purpose and
     * an icon, and the name is the module's own plugin name.
     */
    public function test_catalog_entries_carry_name_purpose_and_icon(): void {
        $catalog = $this->fetch_catalog();

        $this->assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            $this->assertSame(['modname', 'displayname', 'purpose', 'iconhtml'], array_keys($entry));
            $expectedname = get_string('pluginname', 'mod_' . $entry['modname']);
            $this->assertSame($expectedname, $entry['displayname']);
            $this->assertNotSame('', $entry['iconhtml']);
        }
    }

    /**
     * The catalog is a plain list: it is sent as a JSON array, so the sort
     * must not leave gaps in its keys.
     */
    public function test_catalog_is_a_zero_indexed_list(): void {
        $catalog = $this->fetch_catalog();

        $this->assertNotEmpty($catalog);
        $this->assertTrue(array_is_list($catalog));
    }

    /**
     * The entries follow the collation of the site language (the one Moodle
     * applies to every sorted list), not the byte order of the names.
     */
    public function test_catalog_is_ordered_by_display_name_in_the_site_collation(): void {
        $catalog = $this->fetch_catalog();

        $names = array_column($catalog, 'displayname');
        $sorted = $names;
        \core_collator::asort($sorted);
        $this->assertSame(array_values($sorted), $names);
    }
}
