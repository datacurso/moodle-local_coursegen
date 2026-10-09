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

namespace local_coursegen\tests\fixtures;

use local_coursegen\local\service\template_ai_api_service;

/**
 * A template agent client whose download puts the file where the record says and remembers the draft item.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class downloading_template_api extends template_ai_api_service {
    /** @var int[] Draft ids the downloads were asked to use. */
    public array $drafts = [];

    /** @var string|null File id whose download fails, or null when none does. */
    private ?string $failfor;

    /** @var bool Whether a download answers no file. */
    private bool $answernothing;

    /**
     * Build the client without talking to any service.
     *
     * @param string|null $failfor File id whose download throws.
     * @param bool $answernothing Whether every download answers null.
     */
    public function __construct(?string $failfor = null, bool $answernothing = false) {
        $this->failfor = $failfor;
        $this->answernothing = $answernothing;
    }

    /**
     * Download a file into the draft area of the record.
     *
     * @param string $threadid Thread id of the run.
     * @param string $fileid File id of the service.
     * @param string $filename Name of the file.
     * @param array $filerecord The stored file record: the draft area and the folder of the uid.
     * @return \stored_file|null The file, or null when this client answers nothing.
     */
    public function download_generated_file(string $threadid, string $fileid, string $filename, array $filerecord): ?\stored_file {
        if ($this->failfor !== null && $fileid === $this->failfor) {
            throw new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso');
        }
        if ($this->answernothing) {
            return null;
        }
        $this->drafts[] = $filerecord['itemid'];
        $filerecord['filename'] = $filename;
        return get_file_storage()->create_file_from_string($filerecord, 'CONTENT OF ' . $fileid);
    }
}
