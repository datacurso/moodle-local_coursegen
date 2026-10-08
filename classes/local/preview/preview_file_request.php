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
 * The address a file of the preview is asked for by: "/<session>/<uid>/<file name>".
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_file_request {
    /** @var int Longest uid accepted. */
    private const MAX_UID_LENGTH = 64;

    /** @var int Id of the generation session. */
    private int $sessionid;

    /** @var string Opaque uid of the activity the file belongs to. */
    private string $uid;

    /** @var string Name of the file. */
    private string $filename;

    /**
     * Constructor.
     *
     * @param int $sessionid
     * @param string $uid
     * @param string $filename
     */
    private function __construct(int $sessionid, string $uid, string $filename) {
        $this->sessionid = $sessionid;
        $this->uid = $uid;
        $this->filename = $filename;
    }

    /**
     * Read the path the file is asked for by.
     *
     * @param string $relativepath For example "/221/7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10/guide.pdf".
     * @return self
     * @throws \moodle_exception The path is not a session, a uid and a file name.
     */
    public static function from_path(string $relativepath): self {
        $trimmed = ltrim($relativepath, '/');
        $parts = explode('/', $trimmed, 3);
        $total = count($parts);
        if ($total !== 3) {
            throw new \moodle_exception('filenotfound');
        }
        $sessionid = self::session_of($parts[0]);
        $uid = self::uid_of($parts[1]);
        $filename = self::filename_of($parts[2]);
        return new self($sessionid, $uid, $filename);
    }

    /**
     * The id of the generation session.
     *
     * @return int
     */
    public function sessionid(): int {
        return $this->sessionid;
    }

    /**
     * The opaque uid of the activity the file belongs to.
     *
     * @return string
     */
    public function uid(): string {
        return $this->uid;
    }

    /**
     * The name of the file.
     *
     * @return string
     */
    public function filename(): string {
        return $this->filename;
    }

    /**
     * The session id of a path part.
     *
     * @param string $part
     * @return int
     * @throws \moodle_exception It is not a positive whole number.
     */
    private static function session_of(string $part): int {
        $digits = ctype_digit($part);
        $sessionid = (int) $part;
        if (!$digits || $sessionid <= 0) {
            throw new \moodle_exception('filenotfound');
        }
        return $sessionid;
    }

    /**
     * The uid of a path part.
     *
     * @param string $part
     * @return string
     * @throws \moodle_exception It has a character a uid does not, or it is too long.
     */
    private static function uid_of(string $part): string {
        $found = preg_match('/^[A-Za-z0-9_-]+$/', $part);
        $length = strlen($part);
        if ($found !== 1 || $length > self::MAX_UID_LENGTH) {
            throw new \moodle_exception('filenotfound');
        }
        return $part;
    }

    /**
     * The file name of a path part.
     *
     * @param string $part
     * @return string
     * @throws \moodle_exception It is empty or it is not a plain file name.
     */
    private static function filename_of(string $part): string {
        $clean = clean_param($part, PARAM_FILE);
        if ($clean === '' || $clean !== $part) {
            throw new \moodle_exception('filenotfound');
        }
        return $clean;
    }
}
