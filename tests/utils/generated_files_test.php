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
 * The files the AI service made for a template run: stored once, found in scope, copied into a draft.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\generated_file_cache
 * @covers     \local_coursegen\utils\generated_files_scope
 * @covers     \local_coursegen\utils\mold_file_copier
 */
final class generated_files_test extends \advanced_testcase {
    /** @var string A thread id. */
    private const THREAD = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    /**
     * An entry of the result's generated_files.
     *
     * @param string $content What the file holds.
     * @param string $filename
     * @return array
     */
    private function entry(string $content = 'PNGDATA', string $filename = 'forum-1a2b3c4d-1.png'): array {
        return [
            'filename' => $filename,
            'mimetype' => 'image/png',
            'size' => strlen($content),
            'thread_id' => self::THREAD,
            'file_id' => '0a1b2c3d4e5f60718293a4b5c6d7e8f9.png',
        ];
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
     * The first request downloads the file and stores it under the system context.
     */
    public function test_the_first_get_downloads_and_stores_the_file(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));

        $stored = $cache->get($this->entry());

        $this->assertSame([[self::THREAD, '0a1b2c3d4e5f60718293a4b5c6d7e8f9.png', 'forum-1a2b3c4d-1.png']], $calls);
        $this->assertSame('PNGDATA', $stored->get_content());
        $this->assertSame(\context_system::instance()->id, (int) $stored->get_contextid());
        $this->assertSame('/' . self::THREAD . '/', $stored->get_filepath());
    }

    /**
     * The second request finds the stored file and does not download again.
     */
    public function test_the_second_get_does_not_download_again(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));

        $cache->get($this->entry());
        $cache->get($this->entry());

        $this->assertCount(1, $calls);
    }

    /**
     * A download that is not the size the result states is refused and not kept.
     */
    public function test_a_file_of_another_size_is_refused_and_removed(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('SHORT', $calls));

        try {
            $cache->get($this->entry('A LONGER CONTENT'));
            $this->fail('A moodle_exception was expected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_reference_download_failed', $exception->errorcode);
        }

        $record = generated_file_cache::file_record($this->entry('A LONGER CONTENT'));
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
        $cache = new generated_file_cache(static fn() => null);

        $this->expectException(\moodle_exception::class);
        $cache->get($this->entry());
    }

    /**
     * An entry without a thread cannot be addressed.
     */
    public function test_an_entry_without_a_thread_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        generated_file_cache::file_record(['filename' => 'a.png', 'file_id' => 'x']);
    }

    /**
     * Forgetting removes the stored copy, and forgetting twice is harmless.
     */
    public function test_forget_removes_the_stored_file(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $cache->get($this->entry());

        $cache->forget($this->entry());
        $cache->forget($this->entry());

        $cache->get($this->entry());
        $this->assertCount(2, $calls);
    }

    /**
     * Outside a scope no name is a generated file.
     */
    public function test_nothing_is_in_scope_by_default(): void {
        $this->assertNull(generated_files_scope::entry_named('forum-1a2b3c4d-1.png'));
    }

    /**
     * Inside a scope the entries are found by name, and the scope is left afterwards.
     */
    public function test_the_entries_are_in_scope_only_while_the_creation_runs(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $inside = null;

        $result = generated_files_scope::run([$this->entry()], static function () use (&$inside) {
            $inside = generated_files_scope::entry_named('forum-1a2b3c4d-1.png');
            return 'created';
        }, $cache);

        $this->assertSame('created', $result);
        $this->assertSame('forum-1a2b3c4d-1.png', $inside['filename']);
        $this->assertNull(generated_files_scope::entry_named('forum-1a2b3c4d-1.png'));
    }

    /**
     * The scope is left when the creation fails, and the stored copies are kept for another attempt.
     */
    public function test_a_failed_creation_leaves_the_scope_and_keeps_the_files(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $cache->get($this->entry());

        try {
            generated_files_scope::run([$this->entry()], static function () {
                throw new \RuntimeException('boom');
            }, $cache);
            $this->fail('The failure was expected to escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNull(generated_files_scope::entry_named('forum-1a2b3c4d-1.png'));
        $cache->get($this->entry());
        $this->assertCount(1, $calls);
    }

    /**
     * A successful creation removes the stored copies.
     */
    public function test_a_successful_creation_removes_the_stored_files(): void {
        $this->resetAfterTest();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $cache->get($this->entry());

        generated_files_scope::run([$this->entry()], static fn() => 'created', $cache);

        $cache->get($this->entry());
        $this->assertCount(2, $calls);
    }

    /**
     * The text keeps its placeholder and the draft receives the generated file.
     */
    public function test_a_placeholder_of_a_generated_file_puts_the_file_in_the_draft(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $draftid = file_get_unused_draft_itemid();
        $text = '<img src="@@PLUGINFILE@@/forum-1a2b3c4d-1.png" alt="x">';

        $result = generated_files_scope::run([$this->entry()], static function () use ($text, $draftid) {
            return mold_file_copier::copy_generated_files_to_draft($text, $draftid);
        }, $cache);

        $this->assertSame($text, $result);
        $files = get_file_storage()->get_area_files(
            \context_user::instance(\core\session\manager::get_realuser()->id)->id,
            'user',
            'draft',
            $draftid,
            'filename',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('PNGDATA', reset($files)->get_content());
    }

    /**
     * A placeholder that is not a generated file is left alone.
     */
    public function test_other_placeholders_copy_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $draftid = file_get_unused_draft_itemid();
        $text = '<img src="@@PLUGINFILE@@/other.png">';

        generated_files_scope::run([$this->entry()], static function () use ($text, $draftid) {
            return mold_file_copier::copy_generated_files_to_draft($text, $draftid);
        }, $cache);

        $this->assertSame([], $calls);
    }

    /**
     * Outside a scope the copier changes nothing.
     */
    public function test_without_a_scope_the_text_is_returned_as_it_is(): void {
        $this->resetAfterTest();
        $text = '<img src="@@PLUGINFILE@@/forum-1a2b3c4d-1.png">';

        $this->assertSame($text, mold_file_copier::copy_generated_files_to_draft($text, 123));
    }

    /**
     * A percent-encoded name is matched by its decoded form.
     */
    public function test_an_encoded_name_is_matched_decoded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $cache = new generated_file_cache($this->downloader('PNGDATA', $calls));
        $draftid = file_get_unused_draft_itemid();
        $entry = $this->entry('PNGDATA', 'my pic.png');

        generated_files_scope::run([$entry], static function () use ($draftid) {
            return mold_file_copier::copy_generated_files_to_draft('<img src="@@PLUGINFILE@@/my%20pic.png">', $draftid);
        }, $cache);

        $this->assertCount(1, $calls);
    }
}
