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

namespace local_coursegen\local\preview;

use local_coursegen\local\link\link_token;
use local_coursegen\utils\preview_draft_store;

/**
 * Where a page of the preview shows the file a run gave to a resource.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\embedded_file_addresses
 */
final class embedded_file_addresses_test extends \advanced_testcase {
    /** @var string A thread id. */
    private const THREAD = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /** @var string The opaque uid of the resource the run gave a file to. */
    private const RESOURCE_UID = '0dd7846d-0fba-42d7-aae6-477c1cd1cdda';

    /** @var string The opaque uid of another resource. */
    private const OTHER_UID = '5a1c9e22-3f4b-4d7e-8a10-2b6c7d9e0f11';

    /**
     * A resource of the result.
     *
     * @param string $uid
     * @param array $files Its generated_files.
     * @param string $type
     * @return array
     */
    private function activity(string $uid, array $files, string $type = 'resource'): array {
        return ['uid' => $uid, 'resource_type' => $type, 'generated_files' => $files];
    }

    /**
     * The entry of a generated file.
     *
     * @param string $filename
     * @return array
     */
    private function entry(string $filename = 'syllabus.pdf'): array {
        return ['file_id' => 'f1.pdf', 'filename' => $filename, 'content_type' => 'application/pdf', 'role' => 'main'];
    }

    /**
     * A draft store whose downloads are counted.
     *
     * @param array $calls Appended to with the name of each file downloaded.
     * @return preview_draft_store
     */
    private function store(array &$calls): preview_draft_store {
        $downloader = static function (string $threadid, string $fileid, string $filename, array $record) use (&$calls) {
            $calls[] = $filename;
            return get_file_storage()->create_file_from_string($record, 'PDFDATA');
        };
        return new preview_draft_store(211, self::THREAD, $downloader);
    }

    /**
     * The parameters of a page whose text holds a token to a resource.
     *
     * @param string $uid
     * @return array
     */
    private function page_pointing_to(string $uid): array {
        return ['content' => '<iframe src="$@COURSEGENLINK*' . $uid . '@$"></iframe>'];
    }

    /**
     * A resource the page points to is served from the draft area, under its uid.
     */
    public function test_the_file_of_the_resource_the_page_points_to_is_served_from_the_draft_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store($calls);
        $activities = [$this->activity(self::RESOURCE_UID, [$this->entry()])];

        $addresses = embedded_file_addresses::for_page($activities, $this->page_pointing_to(self::RESOURCE_UID), $store);

        $this->assertSame([self::RESOURCE_UID], array_keys($addresses));
        $this->assertSame($store->address(self::RESOURCE_UID, 'syllabus.pdf'), $addresses[self::RESOURCE_UID]);
        $this->assertSame(['syllabus.pdf'], $calls);
    }

    /**
     * A resource the page does not name is not downloaded.
     */
    public function test_a_resource_the_page_does_not_name_is_not_downloaded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store($calls);
        $activities = [
            $this->activity(self::RESOURCE_UID, [$this->entry()]),
            $this->activity(self::OTHER_UID, [$this->entry('other.pdf')]),
        ];

        $addresses = embedded_file_addresses::for_page($activities, $this->page_pointing_to(self::RESOURCE_UID), $store);

        $this->assertSame([self::RESOURCE_UID], array_keys($addresses));
        $this->assertSame(['syllabus.pdf'], $calls);
    }

    /**
     * A resource with no file of the run, and an activity that is not a resource, give no address.
     */
    public function test_only_a_resource_with_a_file_of_the_run_has_an_address(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store($calls);
        $activities = [
            $this->activity(self::RESOURCE_UID, []),
            $this->activity(self::OTHER_UID, [$this->entry()], 'page'),
        ];
        $parameters = ['content' => '<a href="$@COURSEGENLINK*' . self::RESOURCE_UID . '@$">a</a>'
            . '<a href="$@COURSEGENLINK*' . self::OTHER_UID . '@$">b</a>'];

        $this->assertSame([], embedded_file_addresses::for_page($activities, $parameters, $store));
        $this->assertSame([], $calls);
    }

    /**
     * The token in the intro counts as well, and a page with no text gives nothing.
     */
    public function test_the_intro_counts_and_a_page_without_text_gives_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store($calls);
        $activities = [$this->activity(self::RESOURCE_UID, [$this->entry()])];
        $intro = ['intro' => 'See ' . self::RESOURCE_UID];

        $this->assertSame([self::RESOURCE_UID], array_keys(embedded_file_addresses::for_page($activities, $intro, $store)));
        $this->assertSame([], embedded_file_addresses::for_page($activities, [], $store));
        $this->assertSame([], embedded_file_addresses::for_page($activities, ['content' => 7], $store));
    }

    /**
     * The same file is downloaded once however many times the page shows it.
     */
    public function test_the_same_file_is_downloaded_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store($calls);
        $activities = [$this->activity(self::RESOURCE_UID, [$this->entry()])];

        embedded_file_addresses::for_page($activities, $this->page_pointing_to(self::RESOURCE_UID), $store);
        embedded_file_addresses::for_page($activities, $this->page_pointing_to(self::RESOURCE_UID), $store);

        $this->assertCount(1, $calls);
    }

    /**
     * The address of the file replaces the token of a src, and a href keeps the page of the resource.
     */
    public function test_the_address_is_what_a_src_shows_and_a_href_keeps_the_page(): void {
        $text = '<iframe src="$@COURSEGENLINK*' . self::RESOURCE_UID . '@$"></iframe>'
            . '<a href="$@COURSEGENLINK*' . self::RESOURCE_UID . '@$">file</a>';

        $laid = link_token::replace(
            $text,
            [self::RESOURCE_UID => 'https://example.com/preview?uid=r'],
            [self::RESOURCE_UID => '/draftfile.php/5/user/draft/9/uid/syllabus.pdf']
        );

        $this->assertStringContainsString('src="/draftfile.php/5/user/draft/9/uid/syllabus.pdf"', $laid);
        $this->assertStringContainsString('href="https://example.com/preview?uid=r"', $laid);
    }
}
