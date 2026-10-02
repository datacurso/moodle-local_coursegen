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

namespace local_coursegen\utils;

/**
 * The address of a file of a mold resolves to the file, and the markers the AI service left are removed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\mold_file_copier
 */
final class mold_file_copier_test extends \advanced_testcase {
    /**
     * An address resolves to the file it names, with or without an item id.
     */
    public function test_resolve_url_finds_the_file_of_an_address(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $fs = get_file_storage();
        $labelcontext = \context_module::instance($label->cmid);
        $lessoncontext = \context_module::instance($lesson->cmid);
        $fs->create_file_from_string([
            'contextid' => $labelcontext->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'a b.png',
        ], 'NOITEM');
        $fs->create_file_from_string([
            'contextid' => $lessoncontext->id, 'component' => 'mod_lesson', 'filearea' => 'page_contents', 'itemid' => 7,
            'filepath' => '/', 'filename' => 'c.png',
        ], 'WITHITEM');

        $noitem = mold_file_copier::resolve_url(
            $CFG->wwwroot . '/pluginfile.php/' . $labelcontext->id . '/mod_label/intro/a%20b.png'
        );
        $withitem = mold_file_copier::resolve_url(
            $CFG->wwwroot . '/pluginfile.php/' . $lessoncontext->id . '/mod_lesson/page_contents/7/c.png'
        );

        $this->assertSame('NOITEM', $noitem->get_content());
        $this->assertSame('WITHITEM', $withitem->get_content());
    }

    /**
     * Addresses that do not name a file resolve to nothing.
     *
     * @dataProvider unresolvable_provider
     * @param string $suffix
     */
    public function test_resolve_url_refuses_what_is_not_a_file_of_this_site(string $suffix): void {
        global $CFG;
        $this->resetAfterTest();

        $this->assertNull(mold_file_copier::resolve_url($CFG->wwwroot . '/pluginfile.php/' . $suffix));
    }

    /**
     * Addresses that are too short, point nowhere or name a directory.
     *
     * @return array
     */
    public static function unresolvable_provider(): array {
        return [
            'too short' => ['1/mod_label'],
            'no context' => ['0/mod_label/intro/a.png'],
            'no file' => ['1/mod_label/intro/0/nothere.png'],
            'a directory' => ['1/mod_label/intro/0/'],
        ];
    }

    /**
     * An address of another site is not resolved.
     */
    public function test_resolve_url_refuses_another_site(): void {
        $this->assertNull(mold_file_copier::resolve_url('https://example.org/pluginfile.php/1/mod_label/intro/0/a.png'));
    }

    /**
     * Both dialects in one text are stripped; a marker may hold a single bracket or span lines.
     */
    public function test_strip_image_markers_handles_both_dialects_in_one_text(): void {
        $text = "<p>A ⟦coursegen:image:one⟧ B [[coursegen:image:two]] C</p>\n"
            . "<p>D [[coursegen:image: with ] inside]] E [[coursegen:image:multi\nline]] F</p>";

        $this->assertSame(
            "<p>A  B  C</p>\n<p>D  E  F</p>",
            mold_file_copier::strip_image_markers($text)
        );
    }

    /**
     * Only image markers go: wiki-style links and repeat markers written with double brackets stay.
     */
    public function test_strip_image_markers_keeps_other_double_bracket_text(): void {
        $text = '<p>See [[Page]] and [[coursegen:repeat: one per unit]]<li>x</li>[[/coursegen:repeat]] and [[image]].</p>';

        $this->assertSame($text, mold_file_copier::strip_image_markers($text));
    }
}
