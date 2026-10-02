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

use local_coursegen\local\service\template_ai_api_service;

/**
 * The files the AI service made for a template run, kept in Moodle's file storage once downloaded.
 *
 * The result of a run only describes its generated files (name, type, size, and the thread and id to download
 * them by). The review preview and the creation of the course both need the bytes, so the first one that asks
 * downloads the file, as a file and not into memory, and the others find it stored: a file is downloaded once.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_file_cache {
    /** @var string The file area the files are stored in, under the system context. */
    public const FILEAREA = 'generatedfiles';

    /** @var callable Downloads one file: (threadid, fileid, filename, filerecord) => stored_file|null. */
    private $downloader;

    /**
     * Constructor.
     *
     * @param callable|null $downloader Replaces the download from the AI service; tests pass their own.
     */
    public function __construct(?callable $downloader = null) {
        if ($downloader === null) {
            $api = new template_ai_api_service();
            $downloader = [$api, 'download_generated_file'];
        }
        $this->downloader = $downloader;
    }

    /**
     * The file record of a generated file in the file storage.
     *
     * @param array $entry An entry of the result's generated_files.
     * @return array
     */
    public static function file_record(array $entry): array {
        return [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => self::FILEAREA,
            'itemid' => 0,
            'filepath' => '/' . self::text($entry, 'thread_id') . '/',
            'filename' => self::text($entry, 'filename'),
        ];
    }

    /**
     * The stored file of a generated file, downloaded when it is not stored yet.
     *
     * @param array $entry An entry of the result's generated_files.
     * @return \stored_file
     * @throws \moodle_exception The download failed, or the file is not the size the result says.
     */
    public function get(array $entry): \stored_file {
        $record = self::file_record($entry);
        $fs = get_file_storage();
        $stored = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($stored) {
            return $stored;
        }
        $downloaded = ($this->downloader)(
            self::text($entry, 'thread_id'),
            self::text($entry, 'file_id'),
            $record['filename'],
            $record
        );
        if (!$downloaded) {
            throw new \moodle_exception('template_reference_download_failed', 'local_coursegen', '', $record['filename']);
        }
        if ((int) $downloaded->get_filesize() !== (int) ($entry['size'] ?? -1)) {
            $downloaded->delete();
            throw new \moodle_exception('template_reference_download_failed', 'local_coursegen', '', $record['filename']);
        }
        return $downloaded;
    }

    /**
     * Remove a stored generated file once nothing needs it any more.
     *
     * @param array $entry An entry of the result's generated_files.
     */
    public function forget(array $entry): void {
        $record = self::file_record($entry);
        $fs = get_file_storage();
        $stored = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($stored) {
            $stored->delete();
        }
    }

    /**
     * A text field of an entry.
     *
     * @param array $entry
     * @param string $key
     * @return string
     * @throws \coding_exception The entry lacks the field.
     */
    private static function text(array $entry, string $key): string {
        $value = (string) ($entry[$key] ?? '');
        if ($value === '') {
            throw new \coding_exception('A generated file entry has no ' . $key);
        }
        return $value;
    }
}
