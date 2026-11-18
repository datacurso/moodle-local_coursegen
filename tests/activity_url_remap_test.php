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

use local_coursegen\local\link\activity_url_remap;

/**
 * Pointing the activity addresses of a text at other course modules.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\link\activity_url_remap
 */
final class activity_url_remap_test extends \basic_testcase {
    /**
     * A mapped address gets the new id and keeps its host, path and query.
     */
    public function test_replaces_the_id_of_a_mapped_address(): void {
        $text = '<a href="http://site/mod/page/view.php?id=9322&amp;forceview=1">Go</a>';

        $result = activity_url_remap::apply($text, [9322 => 17]);

        $this->assertSame('<a href="http://site/mod/page/view.php?id=17&amp;forceview=1">Go</a>', $result);
    }

    /**
     * Every address of the text is changed, whatever the module.
     */
    public function test_replaces_every_mapped_address(): void {
        $text = '/mod/page/view.php?id=1 /mod/forum/view.php?id=2 /mod/resource/view.php?id=1';

        $result = activity_url_remap::apply($text, [1 => 10, 2 => 20]);

        $this->assertSame('/mod/page/view.php?id=10 /mod/forum/view.php?id=20 /mod/resource/view.php?id=10', $result);
    }

    /**
     * An address that is not in the map is left as it is.
     */
    public function test_leaves_an_unmapped_address_untouched(): void {
        $text = '<a href="/mod/page/view.php?id=5">Go</a>';

        $this->assertSame($text, activity_url_remap::apply($text, [6 => 7]));
    }

    /**
     * A new id equal to another old id is not mapped a second time.
     */
    public function test_maps_each_address_once(): void {
        $text = '/mod/page/view.php?id=1 /mod/page/view.php?id=2';

        $result = activity_url_remap::apply($text, [1 => 2, 2 => 3]);

        $this->assertSame('/mod/page/view.php?id=2 /mod/page/view.php?id=3', $result);
    }

    /**
     * Only a whole id matches: 93220 is not 9322.
     */
    public function test_matches_whole_ids_only(): void {
        $text = '/mod/page/view.php?id=93220';

        $this->assertSame($text, activity_url_remap::apply($text, [9322 => 1]));
    }

    /**
     * Other addresses, such as a course page or an external site, are not activity addresses.
     */
    public function test_ignores_addresses_that_are_not_activity_pages(): void {
        $text = 'http://site/course/view.php?id=9322 http://other/page?id=9322 /mod/page/index.php?id=9322';

        $this->assertSame($text, activity_url_remap::apply($text, [9322 => 1]));
    }

    /**
     * A text with no address, or an empty map, comes back unchanged.
     */
    public function test_empty_text_and_empty_map(): void {
        $this->assertSame('', activity_url_remap::apply('', [1 => 2]));
        $this->assertSame('/mod/page/view.php?id=1', activity_url_remap::apply('/mod/page/view.php?id=1', []));
    }
}
