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

namespace local_coursegen\mod_parameters;

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\h5p_package_fixture;
use local_coursegen\local\api_client_factory;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/h5p_package_fixture.php');

/**
 * How an H5P activity gets its package: rebuilt from a mold, or downloaded.
 *
 * A model-driven activity has its package written by the service and downloads
 * it. A mold-driven one cannot: its content lives inside the mold's own .h5p,
 * next to the library folders and the images its coordinates are calibrated
 * against. So the service returns only the two filled texts and the plugin
 * rebuilds the package out of the mold's package, replacing just those two
 * entries - which is what these tests pin down, byte for byte.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\mod_parameters\h5pactivity_parameters
 */
final class h5pactivity_parameters_test extends \advanced_testcase {
    /** @var string A real core package: library folders, content.json AND an image. */
    private const MOLD_FIXTURE = '/h5p/tests/fixtures/guess-the-answer.h5p';

    /**
     * Always remove the injected factory test double between tests.
     */
    protected function tearDown(): void {
        api_client_factory::set_test_client(null);
        parent::tearDown();
    }

    /**
     * Create a real H5P activity to act as the mold.
     *
     * @return \cm_info The mold's course module.
     */
    private function create_mold(): \cm_info {
        global $CFG;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'packagefilepath' => $CFG->dirroot . self::MOLD_FIXTURE,
        ]);

        return get_fast_modinfo($course)->get_cm($module->cmid);
    }

    /**
     * Every entry of a zip, keyed by its archive path.
     *
     * @param string $path Full path to the zip on disk.
     * @return array<string, string> Archive path => raw bytes.
     */
    private function zip_entries(string $path): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The package must be a readable zip.');

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        }
        $zip->close();

        return $entries;
    }

    /**
     * The draft file the parameters handler produced, on disk.
     *
     * @param int $itemid The draft item id reported as packagefile.
     * @return \stored_file
     */
    private function draft_file(int $itemid): \stored_file {
        global $USER;

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $itemid,
            'id',
            false
        );
        $file = reset($files);
        $this->assertNotFalse($file, 'The handler must leave a draft file behind.');

        return $file;
    }

    /**
     * The whole point of this design: the rebuilt package is the MOLD's package
     * with exactly two entries rewritten. Every library folder, every image and
     * every other byte survives untouched, so the libraries the generated
     * activity runs on are the mold's own.
     */
    public function test_mold_rebuild_replaces_only_the_two_text_entries(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_mold();
        $original = $this->zip_entries($CFG->dirroot . self::MOLD_FIXTURE);

        $filledcontent = str_replace('Cherries', '⟦coursegen:respuesta⟧', $original['content/content.json']);
        $filledh5pjson = str_replace('"title":"Fruits"', '"title":"Frutas"', $original['h5p.json']);
        $this->assertNotSame($original['content/content.json'], $filledcontent);
        $this->assertNotSame($original['h5p.json'], $filledh5pjson);

        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => (int) $cm->id,
                'content_json' => $filledcontent,
                'h5p_json' => $filledh5pjson,
            ],
        ];

        $result = (new h5pactivity_parameters($parameters))->get_parameters();

        $file = $this->draft_file((int) $result->packagefile);
        $temppath = $file->copy_content_to_temp();
        try {
            $rebuilt = $this->zip_entries($temppath);
        } finally {
            @unlink($temppath);
        }

        // Checks (a) and (b): the two text entries are the filled ones.
        $this->assertSame($filledcontent, $rebuilt['content/content.json']);
        $this->assertSame($filledh5pjson, $rebuilt['h5p.json']);
        // Check (d): nothing was added and nothing was lost. The entry ORDER is not
        // asserted: a zip is read through its central directory, and the order
        // the rebuild walks the extracted tree in is the filesystem's.
        $this->assertCount(count($original), $rebuilt);
        $originalnames = array_keys($original);
        $rebuiltnames = array_keys($rebuilt);
        sort($originalnames);
        sort($rebuiltnames);
        $this->assertSame($originalnames, $rebuiltnames);
        // Check (c): every other entry is byte identical to the mold's.
        foreach ($original as $name => $bytes) {
            if ($name === 'content/content.json' || $name === 'h5p.json') {
                continue;
            }
            $this->assertSame($bytes, $rebuilt[$name], 'Entry ' . $name . ' must survive the rebuild untouched.');
        }
        // The image the mold's coordinates are calibrated against is one of them.
        $this->assertArrayHasKey('content/images/solutionImage-55487f41b3f85.jpg', $rebuilt);
    }

    /**
     * The rebuilt package is a real .h5p, named and typed as one.
     */
    public function test_mold_rebuild_produces_a_valid_named_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_mold();
        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => (int) $cm->id,
                'content_json' => '{"solutionText":"⟦coursegen:respuesta⟧"}',
                'h5p_json' => '{"title":"Frutas","mainLibrary":"H5P.GuessTheAnswer"}',
            ],
        ];

        $result = (new h5pactivity_parameters($parameters))->get_parameters();
        $file = $this->draft_file((int) $result->packagefile);

        $this->assertSame('guess-the-answer.h5p', $file->get_filename());
        $this->assertSame('application/zip.h5p', $file->get_mimetype());
    }

    /**
     * A payload carrying the download keys and no mold keys still downloads,
     * exactly as it did before the mold branch existed.
     */
    public function test_download_path_is_unchanged_without_mold_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $downloaded = [];
        $this->inject_download_client($downloaded);

        $parameters = (object) [
            'mod_settings' => [
                'file_path' => 'generated/packages/sample-activity.h5p',
                'file_name' => 'sample-activity.h5p',
            ],
        ];

        $result = (new h5pactivity_parameters($parameters))->get_parameters();

        $this->assertSame(
            '/files/download?path=' . rawurlencode('generated/packages/sample-activity.h5p'),
            $downloaded['endpoint']
        );
        $this->assertSame('sample-activity.h5p', $downloaded['filename']);
        $file = $this->draft_file((int) $result->packagefile);
        $this->assertSame(h5p_package_fixture::bytes(), $file->get_content());
    }

    /**
     * Inject an ai_course_api mock whose download_file() returns a real draft file.
     *
     * @param array $downloaded Filled with the endpoint and filename it was called with.
     * @return void
     */
    private function inject_download_client(array &$downloaded): void {
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();

        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename) use (&$downloaded): \stored_file {
                global $USER;

                $downloaded['endpoint'] = $endpoint;
                $downloaded['filename'] = $filename;

                return get_file_storage()->create_file_from_string([
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/',
                    'filename' => $filename,
                ], h5p_package_fixture::bytes());
            }
        );

        api_client_factory::set_test_client($mock);
    }

    /**
     * mold_source_cmid comes back from an external service, so it is untrusted
     * input. A cmid that resolves to nothing is refused outright - never
     * silently swapped for some other package.
     */
    public function test_unknown_mold_source_cmid_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => 99999999,
                'content_json' => '{}',
                'h5p_json' => '{}',
            ],
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_mold_source_not_found', 'local_coursegen', 99999999));
        (new h5pactivity_parameters($parameters))->get_parameters();
    }

    /**
     * A cmid that exists but is not an H5P activity is refused too: the module
     * type is part of what has to be verified, not assumed.
     */
    public function test_mold_source_cmid_of_another_module_type_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => (int) $page->cmid,
                'content_json' => '{}',
                'h5p_json' => '{}',
            ],
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(
            get_string('error_mold_source_not_found', 'local_coursegen', (int) $page->cmid)
        );
        (new h5pactivity_parameters($parameters))->get_parameters();
    }

    /**
     * Resolving the cmid is not enough: the user running the generation must be
     * allowed to see that module's content in ITS OWN context, or a template
     * could be used to read any package on the site.
     */
    public function test_mold_source_the_user_cannot_view_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_mold();

        // A plain authenticated user with no role in the mold's course.
        $this->setUser($this->getDataGenerator()->create_user());

        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => (int) $cm->id,
                'content_json' => '{}',
                'h5p_json' => '{}',
            ],
        ];

        $this->expectException(\required_capability_exception::class);
        (new h5pactivity_parameters($parameters))->get_parameters();
    }

    /**
     * A mold payload with no filled text is a broken payload: it would rebuild
     * the mold's package with empty content rather than fail.
     */
    public function test_mold_payload_without_the_filled_texts_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = $this->create_mold();
        $parameters = (object) [
            'mod_settings' => [
                'mold_source_cmid' => (int) $cm->id,
                'content_json' => '   ',
                'h5p_json' => '{}',
            ],
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_missing_mold_package_info', 'local_coursegen'));
        (new h5pactivity_parameters($parameters))->get_parameters();
    }
}
