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

use local_coursegen\local\link\embed_link;

/**
 * The embed link: when a link becomes a player and when it stays a link.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\link\embed_link
 */
final class embed_link_test extends \advanced_testcase {
    /**
     * A text with no embed link is returned as it came.
     */
    public function test_a_text_without_an_embed_link_is_untouched(): void {
        $text = '<p><a href="https://example.com/mod/h5pactivity/view.php?id=5">Go</a></p>';

        $this->assertSame($text, embed_link::apply($text));
    }

    /**
     * An embed link whose target is not an H5P activity stays a link.
     */
    public function test_an_embed_link_to_another_page_stays_a_link(): void {
        $text = '<a data-coursegen-embed="1" href="https://example.com/mod/page/view.php?id=5">Go</a>';

        $this->assertSame($text, embed_link::apply($text));
    }

    /**
     * An embed link to an H5P activity that does not exist stays a link.
     */
    public function test_an_embed_link_to_a_missing_activity_stays_a_link(): void {
        $this->resetAfterTest();
        $text = '<a data-coursegen-embed="1" href="https://example.com/mod/h5pactivity/view.php?id=999999">Go</a>';

        $this->assertSame($text, embed_link::apply($text));
    }

    /**
     * An embed link to an H5P activity with a package becomes its player.
     */
    public function test_an_embed_link_to_an_h5p_activity_becomes_its_player(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('h5pactivity', $activity->id);
        $text = '<a data-coursegen-embed="1" href="' . $CFG->wwwroot . '/mod/h5pactivity/view.php?id=' . $cm->id . '">Go</a>';

        $result = embed_link::apply($text);

        $this->assertStringContainsString('<iframe', $result);
        $this->assertStringContainsString('/h5p/embed.php?url=', $result);
        $this->assertStringNotContainsString('<a ', $result);
    }
}
