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

namespace local_coursegen\local\service\mold_export;

/**
 * Unit tests for imscp_mold_export — the package structure walked into ordered {title, html} pages.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\imscp_mold_export
 */
final class imscp_mold_export_test extends \advanced_testcase {
    /**
     * Pages follow the structure depth-first, carry body-only HTML, and a missing file is skipped.
     */
    public function test_exports_keepold_and_pages(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $imscp = $this->getDataGenerator()->create_module('imscp', [
            'course' => $course->id, 'name' => 'Mold package', 'intro' => '<p>IMS</p>', 'introformat' => FORMAT_HTML,
        ]);
        $context = \context_module::instance($imscp->cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_imscp', 'content');
        // mod_imscp files its content under the package revision, not under item id 0.
        $base = [
            'contextid' => $context->id, 'component' => 'mod_imscp', 'filearea' => 'content',
            'itemid' => (int) $imscp->revision,
        ];
        $fs->create_file_from_string($base + ['filepath' => '/', 'filename' => 'one.html'],
            "<html><head><title>x</title></head>\n<body class=\"a\">\n<h1>⟦texto⟧</h1>\n</body></html>");
        $fs->create_file_from_string($base + ['filepath' => '/sub/', 'filename' => 'two.html'], '<p>Fragment</p>');
        $DB->set_field('imscp', 'keepold', 3, ['id' => $imscp->id]);
        $DB->set_field('imscp', 'structure', serialize([
            ['href' => 'one.html#top', 'title' => 'One', 'level' => 0, 'subitems' => [
                ['href' => 'sub/two.html?x=1', 'title' => 'Two', 'level' => 1, 'subitems' => []],
            ]],
            ['href' => 'missing.html', 'title' => 'Missing', 'level' => 0, 'subitems' => []],
        ]), ['id' => $imscp->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($imscp->cmid);

        $result = imscp_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertSame(['text' => '<p>IMS</p>', 'format' => 1], $result['introeditor']);
        $this->assertEquals(3, $result['keepold']);
        $this->assertSame([
            ['title' => 'One', 'html' => '<h1>⟦texto⟧</h1>'],
            ['title' => 'Two', 'html' => '<p>Fragment</p>'],
        ], $result['mod_settings']['pages']);
        $this->assertArrayNotHasKey('structure', $result);
        $this->assertArrayNotHasKey('revision', $result);
    }

    /**
     * A page file above the inline limit is skipped (with debugging); one exactly at the limit travels.
     */
    public function test_oversize_page_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $imscp = $this->getDataGenerator()->create_module('imscp', ['course' => $course->id]);
        $context = \context_module::instance($imscp->cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_imscp', 'content');
        $base = [
            'contextid' => $context->id, 'component' => 'mod_imscp', 'filearea' => 'content',
            'itemid' => (int) $imscp->revision, 'filepath' => '/',
        ];
        $limit = imscp_mold_export::MAX_INLINE_HTML_BYTES;
        $fs->create_file_from_string($base + ['filename' => 'exact.html'], str_repeat('a', $limit));
        $fs->create_file_from_string($base + ['filename' => 'over.html'], str_repeat('b', $limit + 1));
        $DB->set_field('imscp', 'structure', serialize([
            ['href' => 'exact.html', 'title' => 'Exact', 'level' => 0, 'subitems' => []],
            ['href' => 'over.html', 'title' => 'Over', 'level' => 0, 'subitems' => []],
        ]), ['id' => $imscp->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($imscp->cmid);

        $result = imscp_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertSame(['Exact'], array_column($result['mod_settings']['pages'], 'title'));
        $this->assertSame($limit, strlen($result['mod_settings']['pages'][0]['html']));
    }

    /**
     * A corrupt structure blob yields no pages, and says so through debugging().
     */
    public function test_corrupt_structure_yields_no_pages(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $imscp = $this->getDataGenerator()->create_module('imscp', ['course' => $course->id]);
        $DB->set_field('imscp', 'structure', 'garbage', ['id' => $imscp->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($imscp->cmid);

        $result = imscp_mold_export::export($cm);
        $this->assertDebuggingCalled();

        $this->assertSame([], $result['mod_settings']['pages']);
        $this->assertArrayHasKey('keepold', $result);
    }
}
