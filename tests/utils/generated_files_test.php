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

use local_coursegen\local\files\file_reference;
use local_coursegen\local\files\generated_file_source;

/**
 * The files the AI service made for a template run, found in scope while an activity is created.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\generated_files_scope
 * @covers     \local_coursegen\local\files\generated_file_source
 */
final class generated_files_test extends \advanced_testcase {
    /** @var string The opaque uid of an activity. */
    private const UID = '7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10';

    /**
     * An entry of the template agent's generated_files.
     *
     * @param string $filename
     * @return array
     */
    private function entry(string $filename = 'forum-1a2b3c4d-1.png'): array {
        return ['file_id' => '0a1b2c3d4e5f.png', 'filename' => $filename, 'content_type' => 'image/png', 'role' => 'main'];
    }

    /**
     * A draft store whose downloader stores the content it is given and counts its calls.
     *
     * @param string $content
     * @param array $calls Appended to with each call's arguments.
     * @return preview_draft_store
     */
    private function store(string $content, array &$calls): preview_draft_store {
        $downloader = static function (string $threadid, string $fileid, string $filename, array $record) use ($content, &$calls) {
            $calls[] = [$threadid, $fileid, $filename];
            return get_file_storage()->create_file_from_string($record, $content);
        };
        return new preview_draft_store(211, 'thread-1', $downloader);
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
        $this->setAdminUser();
        $calls = [];
        $inside = null;

        $result = generated_files_scope::run(self::UID, [$this->entry()], static function () use (&$inside) {
            $inside = generated_files_scope::entry_named('forum-1a2b3c4d-1.png');
            return 'created';
        }, $this->store('PNGDATA', $calls));

        $this->assertSame('created', $result);
        $this->assertSame('forum-1a2b3c4d-1.png', $inside['filename']);
        $this->assertNull(generated_files_scope::entry_named('forum-1a2b3c4d-1.png'));
    }

    /**
     * The scope is left when the creation fails, and the files stay in the draft area for another attempt.
     */
    public function test_a_failed_creation_leaves_the_scope_and_keeps_the_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store('PNGDATA', $calls);
        $store->get(self::UID, $this->entry());

        try {
            generated_files_scope::run(self::UID, [$this->entry()], static function () {
                throw new \RuntimeException('boom');
            }, $store);
            $this->fail('The failure was expected to escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNull(generated_files_scope::entry_named('forum-1a2b3c4d-1.png'));
        $store->get(self::UID, $this->entry());
        $this->assertCount(1, $calls);
    }

    /**
     * A successful creation leaves the files in the draft area: the creation of the course discards them.
     */
    public function test_a_successful_creation_keeps_the_files_in_the_draft_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $store = $this->store('PNGDATA', $calls);
        $store->get(self::UID, $this->entry());

        generated_files_scope::run(self::UID, [$this->entry()], static fn() => 'created', $store);

        $store->get(self::UID, $this->entry());
        $this->assertCount(1, $calls);
    }

    /**
     * The placeholder of a generated file finds the file of the activity being created.
     */
    public function test_a_placeholder_of_a_generated_file_finds_the_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $source = new generated_file_source();
        $reference = new file_reference(file_reference::KIND_PLACEHOLDER, '/forum-1a2b3c4d-1.png');

        $found = generated_files_scope::run(self::UID, [$this->entry()], static function () use ($source, $reference) {
            return $source->find($reference);
        }, $this->store('PNGDATA', $calls));

        $this->assertSame('PNGDATA', $found->get_content());
        $this->assertSame('/' . self::UID . '/', $found->get_filepath());
    }

    /**
     * A placeholder that is not a generated file finds nothing, and nothing is downloaded.
     */
    public function test_other_placeholders_find_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $source = new generated_file_source();
        $reference = new file_reference(file_reference::KIND_PLACEHOLDER, '/other.png');

        $found = generated_files_scope::run(self::UID, [$this->entry()], static function () use ($source, $reference) {
            return $source->find($reference);
        }, $this->store('PNGDATA', $calls));

        $this->assertNull($found);
        $this->assertSame([], $calls);
    }

    /**
     * Outside a scope there is nothing to find.
     */
    public function test_without_a_scope_nothing_is_found(): void {
        $source = new generated_file_source();

        $this->assertNull($source->find(new file_reference(file_reference::KIND_PLACEHOLDER, '/forum-1a2b3c4d-1.png')));
    }

    /**
     * Only a placeholder is looked up here.
     */
    public function test_only_placeholders_are_looked_up(): void {
        $source = new generated_file_source();

        $this->assertNull($source->find(new file_reference(file_reference::KIND_URL, '/forum-1a2b3c4d-1.png')));
    }

    /**
     * A percent-encoded name is matched by its decoded form.
     */
    public function test_an_encoded_name_is_matched_decoded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $source = new generated_file_source();
        $reference = new file_reference(file_reference::KIND_PLACEHOLDER, '/my%20pic.png');

        $found = generated_files_scope::run(self::UID, [$this->entry('my pic.png')], static function () use ($source, $reference) {
            return $source->find($reference);
        }, $this->store('PNGDATA', $calls));

        $this->assertSame('PNGDATA', $found->get_content());
        $this->assertCount(1, $calls);
    }
}
