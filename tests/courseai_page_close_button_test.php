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

/**
 * Close button contract of the AI course creation page.
 *
 * The page runs on the popup layout (no Moodle navbar), so without an
 * explicit close control there is no way back to My courses. The button
 * must be server-rendered in every mode, since it is the only exit.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class courseai_page_close_button_test extends \advanced_testcase {
    /**
     * Render the courseai_page template with a minimal context.
     *
     * @param bool $templatemode Whether template mode is active.
     * @return string Rendered HTML.
     */
    private function render_page(bool $templatemode): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_coursegen/courseai_page', [
            'guidelines' => '[]',
            'coursetemplates' => [],
            'templatepickerformhtml' => '',
            'templateemptystatehtml' => '',
            'hascoursetemplates' => false,
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => '',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'showsessionsview' => false,
            'templatemodeactive' => $templatemode,
            'subsectionsenabled' => false,
            'closeurl' => (new \moodle_url('/my/courses.php'))->out(false),
        ]);
    }

    /**
     * Template mode renders a close control pointing back to My courses.
     */
    public function test_template_mode_renders_close_button_to_my_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringContainsString('id="courseaiCloseBtn"', $html);
        $this->assertStringContainsString('/my/courses.php', $html);
    }

    /**
     * Free mode renders the same close control: it is the page's only exit.
     */
    public function test_free_mode_renders_close_button_to_my_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(false);

        $this->assertStringContainsString('id="courseaiCloseBtn"', $html);
        $this->assertStringContainsString('/my/courses.php', $html);
    }
}
