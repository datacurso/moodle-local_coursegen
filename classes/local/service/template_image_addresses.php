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

namespace local_coursegen\local\service;

use local_coursegen\local\files\file_reference;
use local_coursegen\local\files\generated_image_source;
use local_coursegen\utils\generated_file_cache;

/**
 * The addresses of one picture of a template run: the picture of the template it starts from, and the new one.
 *
 * The page of the run lists every picture the AI service makes. The original is the template's own file: the picture
 * of a slot of the course (cover, section picture, picture of a section summary) found by the slot's key, or the file
 * a reference marker of an activity points at, found by its name among the files of the template's activities. The
 * new picture is downloaded from the service once and served from this site: a file the run stored (by its thread,
 * id and name, only for the thread of the session) or a picture saved inside an activity (by its path).
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_image_addresses {
    /** @var string[] Kinds of the template's slots, whose original is found by the slot's key. */
    private const SLOT_KINDS = ['course_cover', 'section_image', 'summary_image'];

    /** @var string[] File areas whose addresses carry no item id. */
    private const AREAS_WITHOUT_ITEMID = ['intro', 'overviewfiles'];

    /** @var string Kind of a picture asked for by a reference marker of an activity. */
    private const REFERENCE_KIND = 'reference';

    /** @var int The template's course. */
    private int $courseid;

    /** @var string The thread of the session the pictures belong to. */
    private string $thread;

    /** @var generated_file_cache Downloads and keeps the files the run stored. */
    private generated_file_cache $cache;

    /** @var generated_image_source Downloads and keeps the pictures saved inside the activities. */
    private generated_image_source $images;

    /** @var array<string,\stored_file>|null Slot files of the template by key, read once. */
    private ?array $slotfiles = null;

    /** @var array<string,\stored_file|null> Activity files of the template by name, as they are looked up. */
    private array $activityfiles = [];

    /**
     * Prepare the addresses of the pictures of one session.
     *
     * @param int $courseid The template's course.
     * @param string $thread The thread of the session.
     * @param generated_file_cache|null $cache Replaces the download of stored files; tests pass their own.
     * @param generated_image_source|null $images Replaces the download of saved pictures; tests pass their own.
     */
    public function __construct(
        int $courseid,
        string $thread,
        ?generated_file_cache $cache = null,
        ?generated_image_source $images = null
    ) {
        $this->courseid = $courseid;
        $this->thread = $thread;
        $this->cache = $cache ?? new generated_file_cache();
        $this->images = $images ?? new generated_image_source();
    }

    /**
     * The address of the template's picture a picture starts from, or '' when it starts from none.
     *
     * @param array $item The picture: kind, key and reference (the name of the template's file).
     * @return string
     */
    public function original(array $item): string {
        $kind = (string) ($item['kind'] ?? '');
        $file = null;
        if (in_array($kind, self::SLOT_KINDS, true)) {
            $key = (string) ($item['key'] ?? '');
            $files = $this->slot_files();
            $file = $files[$key] ?? null;
        }
        if ($kind === self::REFERENCE_KIND) {
            $file = $this->activity_file((string) ($item['reference'] ?? ''));
        }
        return self::address_of($file);
    }

    /**
     * The address of the new picture, or '' when it is not made yet or does not belong to the session.
     *
     * @param array $item The picture: thread_id, file_id and filename of a stored file, or path of a saved picture.
     * @return string
     */
    public function current(array $item): string {
        $fileid = (string) ($item['file_id'] ?? '');
        if ($fileid !== '') {
            return $this->stored_address($item);
        }
        $path = (string) ($item['path'] ?? '');
        if ($path === '' || !generated_image_source::is_image_path($path)) {
            return '';
        }
        $file = $this->images->find(new file_reference(file_reference::KIND_IMAGE_PATH, $path));
        return self::address_of($file);
    }

    /**
     * The address of a file the run stored, downloaded once; only a file of the session's thread is served.
     *
     * @param array $item
     * @return string
     */
    private function stored_address(array $item): string {
        $thread = (string) ($item['thread_id'] ?? '');
        if ($thread === '' || $thread !== $this->thread) {
            return '';
        }
        $entry = [
            'thread_id' => $thread,
            'file_id' => (string) $item['file_id'],
            'filename' => (string) ($item['filename'] ?? ''),
        ];
        return self::address_of($this->cache->get($entry));
    }

    /**
     * The files of the template's slots by key.
     *
     * @return array<string,\stored_file>
     */
    private function slot_files(): array {
        if ($this->slotfiles !== null) {
            return $this->slotfiles;
        }
        $course = get_course($this->courseid);
        $slots = template_course_image_slots::for_course($course);
        $this->slotfiles = self::files_by_key($slots);
        return $this->slotfiles;
    }

    /**
     * The stored file of each slot that has one, by the slot's key.
     *
     * @param array[] $slots
     * @return array<string,\stored_file>
     */
    private static function files_by_key(array $slots): array {
        $files = [];
        foreach ($slots as $slot) {
            if ($slot['file'] !== null) {
                $files[(string) $slot['key']] = $slot['file'];
            }
        }
        return $files;
    }

    /**
     * The first picture of the template's activities with a name, or null.
     *
     * @param string $filename
     * @return \stored_file|null
     */
    private function activity_file(string $filename): ?\stored_file {
        if ($filename === '') {
            return null;
        }
        if (!array_key_exists($filename, $this->activityfiles)) {
            $this->activityfiles[$filename] = $this->find_activity_file($filename);
        }
        return $this->activityfiles[$filename];
    }

    /**
     * Look up a picture by name among the files of the modules of the template's course.
     *
     * @param string $filename
     * @return \stored_file|null
     */
    private function find_activity_file(string $filename): ?\stored_file {
        global $DB;
        $coursecontext = \context_course::instance($this->courseid);
        $like = $DB->sql_like('ctx.path', ':path');
        $sql = "SELECT f.id
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE $like AND ctx.contextlevel = :level AND f.filename = :filename AND f.mimetype LIKE :image
              ORDER BY f.id";
        $params = [
            'path' => $coursecontext->path . '/%',
            'level' => CONTEXT_MODULE,
            'filename' => $filename,
            'image' => 'image/%',
        ];
        $ids = $DB->get_fieldset_sql($sql, $params, 0, 1);
        if (!$ids) {
            return null;
        }
        $id = reset($ids);
        return get_file_storage()->get_file_by_id((int) $id) ?: null;
    }

    /**
     * The address a stored file is served at, or '' when there is no file.
     *
     * @param \stored_file|null $file
     * @return string
     */
    private static function address_of(?\stored_file $file): string {
        if ($file === null) {
            return '';
        }
        $itemid = $file->get_itemid();
        if (in_array($file->get_filearea(), self::AREAS_WITHOUT_ITEMID, true)) {
            $itemid = null;
        }
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $itemid,
            $file->get_filepath(),
            $file->get_filename()
        );
        return $url->out(false);
    }
}
