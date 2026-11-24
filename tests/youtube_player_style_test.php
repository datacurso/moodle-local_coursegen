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

use local_coursegen\local\h5p\youtube_player_style;

/**
 * The rule that keeps the YouTube player of an H5P video in sight is added to the site's H5P custom CSS once.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\h5p\youtube_player_style
 */
final class youtube_player_style_test extends \advanced_testcase {
    /**
     * The rule is added after the site's own CSS, which is kept, and the CSS file H5P serves is written.
     */
    public function test_the_rule_is_added_after_the_site_css_and_served(): void {
        $this->resetAfterTest();
        set_config('h5pcustomcss', '.site { color: red; }', 'core_h5p');

        $this->assertTrue(youtube_player_style::apply());

        $css = get_config('core_h5p', 'h5pcustomcss');
        $this->assertStringStartsWith('.site { color: red; }', $css);
        $this->assertStringContainsString(youtube_player_style::BEGIN . "\n" . youtube_player_style::RULE, $css);
        \core_h5p\local\library\autoloader::register();
        $this->assertNotNull(\core_h5p\file_storage::get_custom_styles());
    }

    /**
     * Applied again, nothing changes: the rule is there once.
     */
    public function test_applying_again_does_not_repeat_the_rule(): void {
        $this->resetAfterTest();
        set_config('h5pcustomcss', '', 'core_h5p');

        $this->assertTrue(youtube_player_style::apply());
        $this->assertFalse(youtube_player_style::apply());

        $css = get_config('core_h5p', 'h5pcustomcss');
        $this->assertSame(1, substr_count($css, youtube_player_style::BEGIN));
        $this->assertStringStartsWith(youtube_player_style::BEGIN, $css);
    }
}
