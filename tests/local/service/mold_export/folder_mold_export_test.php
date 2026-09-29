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
 * Unit tests for folder_mold_export — display settings plus every content file with its HTML when small.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\service\mold_export\folder_mold_export
 */
final class folder_mold_export_test extends \advanced_testcase {
    /**
     * HTML files carry their content; binaries only their pluginfile URL; directories are skipped.
     */
    public function test_exports_settings_and_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', [
            'course' => $course->id, 'name' => 'Mold folder', 'intro' => '<p>Docs</p>', 'introformat' => FORMAT_HTML,
            'display' => 1, 'showexpanded' => 0, 'showdownloadfolder' => 0, 'forcedownload' => 1,
        ]);
        $context = \context_module::instance($folder->cmid);
        $fs = get_file_storage();
        $base = ['contextid' => $context->id, 'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0];
        $fs->create_file_from_string($base + ['filepath' => '/Docs/', 'filename' => 'doc.html', 'mimetype' => 'text/html'],
            '<p>⟦coursegen:repeat: doc⟧⟦texto⟧⟦/coursegen:repeat⟧</p>');
        $fs->create_file_from_string($base + ['filepath' => '/', 'filename' => 'img.png', 'mimetype' => 'image/png'], 'PNG');
        $cm = get_fast_modinfo($course->id)->get_cm($folder->cmid);

        $result = folder_mold_export::export($cm);

        $this->assertSame(['text' => '<p>Docs</p>', 'format' => 1], $result['introeditor']);
        $this->assertEquals(1, $result['display']);
        $this->assertEquals(0, $result['showexpanded']);
        $this->assertEquals(0, $result['showdownloadfolder']);
        $this->assertEquals(1, $result['forcedownload']);
        $files = $result['mod_settings']['files'];
        $this->assertCount(2, $files);
        $html = $files[0]['file_name'] === 'doc.html' ? $files[0] : $files[1];
        $png = $files[0]['file_name'] === 'img.png' ? $files[0] : $files[1];
        $this->assertSame('/Docs/', $html['folder_path']);
        $this->assertSame('text/html', $html['mimetype']);
        $this->assertSame('<p>⟦coursegen:repeat: doc⟧⟦texto⟧⟦/coursegen:repeat⟧</p>', $html['content_html']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_folder/content/0/Docs/doc.html',
            $html['pluginfile_url']
        );
        $this->assertSame('/', $png['folder_path']);
        $this->assertSame('image/png', $png['mimetype']);
        $this->assertArrayNotHasKey('content_html', $png);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_folder/content/0/img.png',
            $png['pluginfile_url']
        );
    }

    /**
     * An HTML file exactly at the inline limit carries its content; one byte over only carries its URL.
     */
    public function test_html_inline_limit_boundary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $context = \context_module::instance($folder->cmid);
        $fs = get_file_storage();
        $base = [
            'contextid' => $context->id, 'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'mimetype' => 'text/html',
        ];
        $limit = folder_mold_export::MAX_INLINE_HTML_BYTES;
        $fs->create_file_from_string($base + ['filename' => 'exact.html'], str_repeat('a', $limit));
        $fs->create_file_from_string($base + ['filename' => 'over.html'], str_repeat('b', $limit + 1));
        $cm = get_fast_modinfo($course->id)->get_cm($folder->cmid);

        $files = folder_mold_export::export($cm)['mod_settings']['files'];

        $byname = array_column($files, null, 'file_name');
        $this->assertSame($limit, strlen($byname['exact.html']['content_html']));
        $this->assertArrayNotHasKey('content_html', $byname['over.html']);
        $this->assertStringContainsString('/mod_folder/content/0/over.html', $byname['over.html']['pluginfile_url']);
    }
}
