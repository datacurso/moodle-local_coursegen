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

/**
 * One file of the payload, answering what a stored_file answers.
 *
 * A module's view code asks a file for its name, its path, its size and its
 * type, and builds the file's address from those; the payload carries every
 * one of them. It never asks for the bytes during a view, so there are none
 * here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_file {
    /** @var array The file's columns, as the payload lists them. */
    protected array $row;

    /**
     * Constructor.
     *
     * @param array $row
     */
    public function __construct(array $row) {
        $this->row = $row;
    }

    /**
     * The id the file storage gave it.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) ($this->row['id'] ?? 0);
    }

    /**
     * The context it is stored in.
     *
     * @return int
     */
    public function get_contextid(): int {
        return (int) ($this->row['contextid'] ?? 0);
    }

    /**
     * The component that owns it.
     *
     * @return string
     */
    public function get_component(): string {
        return (string) ($this->row['component'] ?? '');
    }

    /**
     * The file area it is in.
     *
     * @return string
     */
    public function get_filearea(): string {
        return (string) ($this->row['filearea'] ?? '');
    }

    /**
     * The item it belongs to within its area.
     *
     * @return int
     */
    public function get_itemid(): int {
        return (int) ($this->row['itemid'] ?? 0);
    }

    /**
     * Its directory, with a slash at both ends.
     *
     * @return string
     */
    public function get_filepath(): string {
        return (string) ($this->row['filepath'] ?? '/');
    }

    /**
     * Its name, or "." for a directory.
     *
     * @return string
     */
    public function get_filename(): string {
        return (string) ($this->row['filename'] ?? '');
    }

    /**
     * Its size in bytes.
     *
     * @return int
     */
    public function get_filesize(): int {
        return (int) ($this->row['filesize'] ?? 0);
    }

    /**
     * Its MIME type, or null for a directory.
     *
     * @return string|null
     */
    public function get_mimetype(): ?string {
        if (!isset($this->row['mimetype'])) {
            return null;
        }
        return (string) $this->row['mimetype'];
    }

    /**
     * When it was stored.
     *
     * @return int
     */
    public function get_timecreated(): int {
        return (int) ($this->row['timecreated'] ?? 0);
    }

    /**
     * When it was last changed.
     *
     * @return int
     */
    public function get_timemodified(): int {
        return (int) ($this->row['timemodified'] ?? 0);
    }

    /**
     * Its place in the area's order; a resource's main file is 1.
     *
     * @return int
     */
    public function get_sortorder(): int {
        return (int) ($this->row['sortorder'] ?? 0);
    }

    /**
     * Who is credited for it.
     *
     * @return string|null
     */
    public function get_author(): ?string {
        if (!isset($this->row['author'])) {
            return null;
        }
        return (string) $this->row['author'];
    }

    /**
     * Its licence.
     *
     * @return string|null
     */
    public function get_license(): ?string {
        if (!isset($this->row['license'])) {
            return null;
        }
        return (string) $this->row['license'];
    }

    /**
     * Who uploaded it: not part of a template.
     *
     * @return int|null
     */
    public function get_userid(): ?int {
        return null;
    }

    /**
     * A file the payload lists is the module's own, not a reference to a repository.
     *
     * @return int
     */
    public function get_repository_id(): int {
        return 0;
    }

    /**
     * Whether it is an external reference: never.
     *
     * @return bool
     */
    public function is_external_file(): bool {
        return false;
    }

    /**
     * Whether it is a directory entry.
     *
     * @return bool
     */
    public function is_directory(): bool {
        return !empty($this->row['isdir']);
    }

    /**
     * The hash the file storage keys files by, built the same way.
     *
     * @return string
     */
    public function get_pathnamehash(): string {
        return sha1('/' . $this->get_contextid() . '/' . $this->get_component() . '/' . $this->get_filearea() . '/'
            . $this->get_itemid() . $this->get_filepath() . $this->get_filename());
    }

    /**
     * Where it is served from, as the payload was told.
     *
     * @return string|null
     */
    public function get_url(): ?string {
        if (!isset($this->row['url'])) {
            return null;
        }
        return (string) $this->row['url'];
    }
}
