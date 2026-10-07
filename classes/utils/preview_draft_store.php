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
 * The files the AI service made for a template run, kept in the draft area of the user who reviews it.
 *
 * Every file lives in one draft item of the current user, remembered for the generation session, under a folder
 * named by the opaque uid of its activity: "/<uid>/<filename>". The result of a run only names its files (an id
 * and a name), so the first request that needs one downloads it from the service and every other request finds it
 * stored. The review previews address the files by the draft URL, and the creation of the course reads the same
 * stored files and moves them into the new course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_draft_store {
    /** @var string Key of the user session that remembers the draft item of each generation session. */
    private const REMEMBERED = 'local_coursegen_previewdrafts';

    /** @var int Id of the generation session. */
    private int $sessionid;

    /** @var string Thread id of the run in the AI service, for example "5c1e2a". */
    private string $threadid;

    /** @var callable Downloads one file: (threadid, fileid, filename, filerecord) => stored_file|null. */
    private $downloader;

    /**
     * Constructor.
     *
     * @param int $sessionid Id of the generation session, for example 211.
     * @param string $threadid Thread id of the run in the AI service.
     * @param callable|null $downloader Replaces the download from the AI service; tests pass their own.
     */
    public function __construct(int $sessionid, string $threadid, ?callable $downloader = null) {
        if ($downloader === null) {
            $api = new template_ai_api_service();
            $downloader = [$api, 'download_generated_file'];
        }
        $this->sessionid = $sessionid;
        $this->threadid = $threadid;
        $this->downloader = $downloader;
    }

    /**
     * The draft item the files of this generation session are in.
     *
     * @return int Draft item id, the same one on every call of the same generation session.
     */
    public function itemid(): int {
        global $SESSION;

        $remembered = $SESSION->{self::REMEMBERED} ?? [];
        $known = (int) ($remembered[$this->sessionid] ?? 0);
        if ($known > 0) {
            return $known;
        }
        $itemid = file_get_unused_draft_itemid();
        $remembered[$this->sessionid] = $itemid;
        $SESSION->{self::REMEMBERED} = $remembered;
        return $itemid;
    }

    /**
     * The file record of a generated file in the draft area of the current user.
     *
     * @param string $uid Opaque uid of the activity, for example "7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10".
     * @param string $filename File name, for example "guide.pdf".
     * @return array
     */
    public function file_record(string $uid, string $filename): array {
        global $USER;

        $context = \context_user::instance($USER->id);
        return [
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $this->itemid(),
            'filepath' => '/' . $uid . '/',
            'filename' => $filename,
        ];
    }

    /**
     * The stored file of a generated file, downloaded when it is not stored yet.
     *
     * @param string $uid Opaque uid of the activity the file belongs to.
     * @param array $entry An entry of the result's generated_files: file_id and filename, and size when given.
     * @return \stored_file
     * @throws \moodle_exception The download failed, or the file is not the size the result says.
     */
    public function get(string $uid, array $entry): \stored_file {
        $filename = $this->text($entry, 'filename');
        $fileid = $this->text($entry, 'file_id');
        $record = $this->file_record($uid, $filename);
        $stored = $this->find($record);
        if ($stored) {
            return $stored;
        }
        $downloaded = ($this->downloader)($this->threadid, $fileid, $filename, $record);
        if (!$downloaded) {
            throw new \moodle_exception('template_reference_download_failed', 'local_coursegen', '', $filename);
        }
        $size = (int) ($entry['size'] ?? 0);
        $actualsize = (int) $downloaded->get_filesize();
        if ($size > 0 && $actualsize !== $size) {
            $downloaded->delete();
            throw new \moodle_exception('template_reference_download_failed', 'local_coursegen', '', $filename);
        }
        return $downloaded;
    }

    /**
     * Store every generated file of an activity.
     *
     * @param string $uid Opaque uid of the activity the files belong to.
     * @param array[] $entries The activity's generated_files.
     */
    public function store(string $uid, array $entries): void {
        foreach ($entries as $entry) {
            $this->get($uid, $entry);
        }
    }

    /**
     * The address a stored generated file is served from.
     *
     * It is the draft file URL without the site address: the text formatting of Moodle turns a draft file URL that
     * starts with the site address into a broken one, since a draft URL should never reach a saved text.
     *
     * @param string $uid Opaque uid of the activity the file belongs to.
     * @param string $filename File name, for example "guide.pdf".
     * @return string
     */
    public function address(string $uid, string $filename): string {
        $url = \moodle_url::make_draftfile_url($this->itemid(), '/' . $uid . '/', $filename);
        return $url->get_path(true);
    }

    /**
     * Delete every file of this generation session from the draft area, once the course is made or the run is cancelled.
     */
    public function discard(): void {
        global $USER, $SESSION;

        $context = \context_user::instance($USER->id);
        $remembered = $SESSION->{self::REMEMBERED} ?? [];
        $known = (int) ($remembered[$this->sessionid] ?? 0);
        if ($known <= 0) {
            return;
        }
        get_file_storage()->delete_area_files($context->id, 'user', 'draft', $known);
        unset($remembered[$this->sessionid]);
        $SESSION->{self::REMEMBERED} = $remembered;
    }

    /**
     * The file a record names, when it is already stored.
     *
     * @param array $record
     * @return \stored_file|null
     */
    private function find(array $record): ?\stored_file {
        $stored = get_file_storage()->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if (!$stored) {
            return null;
        }
        return $stored;
    }

    /**
     * A text field of an entry.
     *
     * @param array $entry
     * @param string $key
     * @return string
     * @throws \coding_exception The entry lacks the field.
     */
    private function text(array $entry, string $key): string {
        $value = (string) ($entry[$key] ?? '');
        if ($value === '') {
            throw new \coding_exception('A generated file entry has no ' . $key);
        }
        return $value;
    }
}
