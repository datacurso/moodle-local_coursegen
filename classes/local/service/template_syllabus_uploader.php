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

use stored_file;

/**
 * Sends the syllabus a teacher attached to a template generation to the template agent of the service.
 *
 * The file is read from the draft area of the current user and uploaded to the syllabus endpoint of the run, which
 * is not the endpoint of the file a question asks for. The draft area is emptied afterwards, whether the upload
 * worked or not, so no copy stays behind. The refusal of the service, which the provider client reports as an HTTP
 * status, is turned into a message the teacher can act on.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_syllabus_uploader {
    /** @var int Biggest syllabus the plugin sends, in bytes (25 MiB); the service applies its own limits as well. */
    public const MAX_FILE_BYTES = 26214400;

    /** @var string[] Message of the plugin for each HTTP status the service refuses the syllabus with. */
    private const STATUS_STRINGS = [
        400 => 'templatesyllabusrejected',
        404 => 'templatesyllabusrun',
        408 => 'templatesyllabustimeout',
        409 => 'templatesyllabusrun',
        413 => 'templatesyllabustoolarge',
        415 => 'templatesyllabusrejected',
        422 => 'templatesyllabusrejected',
        504 => 'templatesyllabustimeout',
    ];

    /** @var template_ai_api_service Client of the template agent endpoints. */
    private template_ai_api_service $api;

    /**
     * Constructor.
     *
     * @param template_ai_api_service|null $api Client of the service, the configured one when omitted.
     */
    public function __construct(?template_ai_api_service $api = null) {
        if ($api === null) {
            $api = new template_ai_api_service();
        }
        $this->api = $api;
    }

    /**
     * Send the syllabus of a draft area to the run of a thread.
     *
     * @param string $threadid Thread of the run, for example "3f2a9c1e-77b4".
     * @param int $draftitemid Draft item id holding the syllabus, for example 8421.
     * @return array The name and the size in bytes of the file that was sent.
     * @throws \moodle_exception When there is no file, it is empty or too large, or the service refuses it.
     */
    public function send(string $threadid, int $draftitemid): array {
        $file = user_draft_file::first($draftitemid);
        if ($file === null) {
            throw new \moodle_exception('templatesyllabusmissing', 'local_coursegen');
        }
        try {
            $this->check_file($file);
            $this->upload($threadid, $file);
            $filename = $file->get_filename();
            $filesize = $file->get_filesize();
            return ['filename' => $filename, 'filesize' => (int) $filesize];
        } finally {
            user_draft_file::remove_all($draftitemid);
        }
    }

    /**
     * Refuse a file that is empty or over the structural limit, before anything is sent.
     *
     * @param stored_file $file File of the draft area.
     */
    private function check_file(stored_file $file): void {
        $size = $file->get_filesize();
        if ($size <= 0) {
            throw new \moodle_exception('templatesyllabusempty', 'local_coursegen');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new \moodle_exception('templatesyllabustoolarge', 'local_coursegen');
        }
    }

    /**
     * Upload the file and turn any refusal of the service into a message of the plugin.
     *
     * @param string $threadid Thread of the run.
     * @param stored_file $file File of the draft area.
     */
    private function upload(string $threadid, stored_file $file): void {
        try {
            $this->api->upload_syllabus($threadid, $file);
        } catch (\moodle_exception $exception) {
            $refusal = $this->refusal($exception);
            throw $refusal;
        }
    }

    /**
     * The message of the plugin for an HTTP error of the service, or the error itself when it has no message.
     *
     * @param \moodle_exception $exception Error of the provider client.
     * @return \moodle_exception The exception to throw.
     */
    private function refusal(\moodle_exception $exception): \moodle_exception {
        if ($exception->errorcode !== 'httperror') {
            return $exception;
        }
        $status = (int) $exception->a;
        $string = self::STATUS_STRINGS[$status] ?? '';
        if ($string === '') {
            return $exception;
        }
        return new \moodle_exception($string, 'local_coursegen', '', null, 'HTTP ' . $status);
    }
}
