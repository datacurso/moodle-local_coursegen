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
 * The files the AI service made for a template run, kept in the draft area of the user.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\preview_draft_store
 */
final class preview_draft_store_test extends \advanced_testcase {
    /** @var string A thread id. */
    private const THREAD = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /** @var string The opaque uid of an activity. */
    private const UID = '7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10';

    /**
     * An entry of the template agent's generated_files: it has no thread_id and no size.
     *
     * @param string $filename
     * @return array
     */
    private function entry(string $filename = 'guide.pdf'): array {
        return ['file_id' => 'f1.pdf', 'filename' => $filename, 'content_type' => 'application/pdf', 'role' => 'main'];
    }

    /**
     * A downloader that stores the content it is given and counts its calls.
     *
     * @param string $content
     * @param array $calls Appended to with each call's arguments.
     * @return callable
     */
    private function downloader(string $content, array &$calls): callable {
        return static function (string $threadid, string $fileid, string $filename, array $record) use ($content, &$calls) {
            $calls[] = [$threadid, $fileid, $filename];
            return get_file_storage()->create_file_from_string($record, $content);
        };
    }

    /**
     * The file is downloaded once and stored in the draft area of the user under the folder of the uid.
     */
    public function test_the_file_is_stored_in_the_user_draft_area_under_the_uid(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('PDFDATA', $calls));

        $stored = $store->get(self::UID, $this->entry());

        $this->assertSame([[self::THREAD, 'f1.pdf', 'guide.pdf']], $calls);
        $this->assertSame(\context_user::instance($USER->id)->id, (int) $stored->get_contextid());
        $this->assertSame('user', $stored->get_component());
        $this->assertSame('draft', $stored->get_filearea());
        $this->assertSame($store->itemid(), (int) $stored->get_itemid());
        $this->assertSame('/' . self::UID . '/', $stored->get_filepath());
        $this->assertSame('PDFDATA', $stored->get_content());
    }

    /**
     * The second request finds the stored file and does not download again.
     */
    public function test_the_second_get_does_not_download_again(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('PDFDATA', $calls));

        $store->get(self::UID, $this->entry());
        $store->get(self::UID, $this->entry());

        $this->assertCount(1, $calls);
    }

    /**
     * The draft item is the same for every request of a generation session, and another for another session.
     */
    public function test_the_draft_item_is_remembered_per_generation_session(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = new preview_draft_store(211, self::THREAD);
        $again = new preview_draft_store(211, self::THREAD);
        $other = new preview_draft_store(212, self::THREAD);

        $this->assertSame($first->itemid(), $again->itemid());
        $this->assertNotSame($first->itemid(), $other->itemid());
    }

    /**
     * A size is only checked when the result states one, and a different one is refused and not kept.
     */
    public function test_a_file_of_another_size_is_refused_and_removed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('SHORT', $calls));
        $entry = $this->entry();
        $entry['size'] = 99;

        try {
            $store->get(self::UID, $entry);
            $this->fail('A moodle_exception was expected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_reference_download_failed', $exception->errorcode);
        }

        $record = $store->file_record(self::UID, 'guide.pdf');
        $this->assertFalse(get_file_storage()->file_exists(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        ));
    }

    /**
     * A downloader that gives nothing is an error.
     */
    public function test_a_failed_download_is_an_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = new preview_draft_store(211, self::THREAD, static fn() => null);

        $this->expectException(\moodle_exception::class);
        $store->get(self::UID, $this->entry());
    }

    /**
     * An entry needs its file id and its name, and nothing else.
     */
    public function test_an_entry_without_a_file_id_is_a_coding_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = new preview_draft_store(211, self::THREAD, static fn() => null);

        $this->expectException(\coding_exception::class);
        $store->get(self::UID, ['filename' => 'guide.pdf']);
    }

    /**
     * The address of a stored file is the page of the preview that serves it, without the site address.
     */
    public function test_the_address_is_the_preview_file_page_of_the_session(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = new preview_draft_store(211, self::THREAD);

        $address = $store->address(self::UID, 'guide.pdf');

        $this->assertStringEndsWith('/local/coursegen/preview_file.php/211/' . self::UID . '/guide.pdf', $address);
        $this->assertStringStartsNotWith('http', $address);
        $this->assertStringNotContainsString('draftfile.php', $address);
    }

    /**
     * A name with spaces and accents is encoded in the address.
     */
    public function test_the_address_encodes_the_file_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = new preview_draft_store(211, self::THREAD);

        $address = $store->address(self::UID, 'Guía semanal.pdf');

        $this->assertStringEndsWith('/' . self::UID . '/Gu%C3%ADa%20semanal.pdf', $address);
    }

    /**
     * A file already stored is the one the preview serves.
     */
    public function test_served_finds_a_file_that_is_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('%PDF-1.4', $calls));
        $store->get(self::UID, $this->entry());

        $served = $store->served(self::UID, 'guide.pdf');

        $this->assertNotNull($served);
        $this->assertSame('%PDF-1.4', $served->get_content());
    }

    /**
     * Serving never downloads from the AI service.
     */
    public function test_served_does_not_download_a_missing_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('x', $calls));
        $store->itemid();

        $this->assertNull($store->served(self::UID, 'guide.pdf'));
        $this->assertSame([], $calls);
    }

    /**
     * A generation session the user never opened has no draft item, and asking for it does not create one.
     */
    public function test_served_of_an_unknown_session_is_nothing_and_creates_no_draft_item(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        $store = new preview_draft_store(999, self::THREAD, static fn() => null);

        $this->assertNull($store->served(self::UID, 'guide.pdf'));
        $remembered = $SESSION->local_coursegen_previewdrafts ?? [];
        $this->assertArrayNotHasKey(999, $remembered);
    }

    /**
     * Another user cannot read a draft file of the first one, even knowing the session, the uid and the name.
     */
    public function test_served_never_reads_the_draft_area_of_another_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $owner = new preview_draft_store(211, self::THREAD, $this->downloader('secret', $calls));
        $owner->get(self::UID, $this->entry());
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $store = new preview_draft_store(211, self::THREAD, static fn() => null);

        $this->assertNull($store->served(self::UID, 'guide.pdf'));
    }

    /**
     * A folder is not a file to serve.
     */
    public function test_served_does_not_serve_a_folder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('x', $calls));
        $store->get(self::UID, $this->entry());

        $this->assertNull($store->served(self::UID, '.'));
    }

    /**
     * Storing an activity's files stores every one of them.
     */
    public function test_store_keeps_every_file_of_the_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('DATA', $calls));

        $store->store(self::UID, [$this->entry('a.png'), $this->entry('b.png')]);

        $this->assertCount(2, $calls);
    }

    /**
     * Discarding empties the draft item and forgets it, and discarding twice is harmless.
     */
    public function test_discard_empties_the_draft_item(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = new preview_draft_store(211, self::THREAD, $this->downloader('PDFDATA', $calls));
        $store->get(self::UID, $this->entry());
        $record = $store->file_record(self::UID, 'guide.pdf');

        $store->discard();
        $store->discard();

        $this->assertFalse(get_file_storage()->file_exists(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        ));
        $store->get(self::UID, $this->entry());
        $this->assertCount(2, $calls);
    }
}
