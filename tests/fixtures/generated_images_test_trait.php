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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;

/**
 * Helpers for the tests of the generated images stored by the module settings.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait generated_images_test_trait {
    /**
     * A 1x1 transparent PNG.
     *
     * @return string
     */
    protected function png_bytes(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    /**
     * Make the AI service client return a PNG for every download, stored in the requested draft area.
     *
     * @return void
     */
    protected function inject_image_client(): void {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $client->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename, array $filerecord = []): \stored_file {
                global $USER;

                return get_file_storage()->create_file_from_string([
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $filerecord['itemid'] ?? file_get_unused_draft_itemid(),
                    'filepath' => '/',
                    'filename' => $filename,
                ], $this->png_bytes());
            }
        );
        api_client_factory::set_test_client($client);
    }

    /**
     * Create a draft area of the current user holding one image, as the parameter cleaner leaves it.
     *
     * @param string $filename
     * @return int The draft itemid.
     */
    protected function draft_with_image(string $filename): int {
        global $USER;

        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $this->png_bytes());
        return $itemid;
    }
}
