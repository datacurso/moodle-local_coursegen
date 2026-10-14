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

use local_coursegen\mod_settings\lesson_settings;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();

/**
 * The navigation buttons of a generated content page sit side by side.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\mod_settings\lesson_settings
 */
final class lesson_settings_layout_test extends \advanced_testcase {
    /**
     * Build the properties the lesson API receives for one content page.
     *
     * @param array $extra Keys added to the page definition.
     * @return \stdClass
     */
    private function properties_of(array $extra = []): \stdClass {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');
        require_once($CFG->dirroot . '/mod/lesson/pagetypes/truefalse.php');

        $this->resetAfterTest();
        $settings = new lesson_settings((object) ['id' => 0], []);
        $method = new ReflectionMethod($settings, 'build_page_properties');
        $method->setAccessible(true);
        $page = [
            'page_type' => 'content',
            'title' => 'Page',
            'content_html' => '<p>Text</p>',
            'buttons' => [['text' => 'Anterior', 'jumpto' => -40], ['text' => 'Siguiente', 'jumpto' => -1]],
        ] + $extra;

        return $method->invoke($settings, $page, 0);
    }

    /**
     * A content page arranges its buttons horizontally, as the lesson form does by default.
     */
    public function test_content_page_arranges_buttons_horizontally(): void {
        $this->assertSame(1, $this->properties_of()->layout);
    }

    /**
     * A page that carries its own layout keeps it.
     */
    public function test_page_layout_is_kept(): void {
        $this->assertSame(0, $this->properties_of(['layout' => 0])->layout);
    }
}
