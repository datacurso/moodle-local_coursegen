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
 * Sends the answer of a teacher to the question a template run is paused on.
 *
 * A file answer reads the file from the draft area of the current user, uploads it to the service and answers
 * with its id. The draft area is emptied afterwards, whether the upload worked or not, so no copy stays behind.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_question_answerer {
    /** @var int Biggest file the service accepts to a question, in bytes (25 MiB, the service default). */
    public const MAX_FILE_BYTES = 26214400;

    /** @var template_ai_api_service Client of the template agent endpoints. */
    private template_ai_api_service $api;

    /**
     * Constructor.
     *
     * @param template_ai_api_service|null $api Optional pre-built service client; tests pass a mock.
     */
    public function __construct(?template_ai_api_service $api = null) {
        if ($api === null) {
            $api = new template_ai_api_service();
        }
        $this->api = $api;
    }

    /**
     * Answer the pending question of a run.
     *
     * @param string $threadid Thread id of the run, for example "5c1e2a".
     * @param string $callid Call id of the pending question, for example "c4".
     * @param string $kind Kind of answer: file, text or choice.
     * @param int $draftitemid Draft item id holding the file, 0 for a text or a choice.
     * @param string $text Text of a text answer.
     * @param string $choice Option of a choice answer.
     * @return array What the service stored.
     * @throws \moodle_exception When the answer is empty or the file cannot be used.
     * @throws \coding_exception When the kind is unknown.
     */
    public function answer(string $threadid, string $callid, string $kind, int $draftitemid, string $text, string $choice): array {
        if ($kind === 'file') {
            return $this->answer_file($threadid, $callid, $draftitemid);
        }
        if ($kind === 'text') {
            return $this->answer_text($threadid, $callid, $text);
        }
        if ($kind === 'choice') {
            return $this->answer_choice($threadid, $callid, $choice);
        }
        throw new \coding_exception('Unknown answer kind: ' . $kind);
    }

    /**
     * Answer with a text.
     *
     * @param string $threadid Thread id of the run.
     * @param string $callid Call id of the pending question.
     * @param string $text Text typed by the teacher.
     * @return array What the service stored.
     */
    private function answer_text(string $threadid, string $callid, string $text): array {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new \moodle_exception('templateanswertextmissing', 'local_coursegen');
        }
        return $this->api->answer($threadid, $callid, ['text' => $trimmed]);
    }

    /**
     * Answer with one of the options of the question.
     *
     * @param string $threadid Thread id of the run.
     * @param string $callid Call id of the pending question.
     * @param string $choice Option chosen by the teacher.
     * @return array What the service stored.
     */
    private function answer_choice(string $threadid, string $callid, string $choice): array {
        $trimmed = trim($choice);
        if ($trimmed === '') {
            throw new \moodle_exception('templateanswerchoicemissing', 'local_coursegen');
        }
        return $this->api->answer($threadid, $callid, ['choice' => $trimmed]);
    }

    /**
     * Answer with the file of a draft area, and empty the draft area afterwards.
     *
     * @param string $threadid Thread id of the run.
     * @param string $callid Call id of the pending question.
     * @param int $draftitemid Draft item id holding the file.
     * @return array What the service stored.
     */
    private function answer_file(string $threadid, string $callid, int $draftitemid): array {
        $file = $this->draft_file($draftitemid);
        if ($file === null) {
            throw new \moodle_exception('templateanswerfilemissing', 'local_coursegen');
        }
        try {
            $this->check_file($file);
            return $this->send_file($threadid, $callid, $file);
        } finally {
            $this->empty_draft($draftitemid);
        }
    }

    /**
     * Refuse a file the service would not take.
     *
     * @param stored_file $file File of the draft area.
     */
    private function check_file(stored_file $file): void {
        $size = $file->get_filesize();
        if ($size <= 0) {
            throw new \moodle_exception('templateanswerfileempty', 'local_coursegen');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new \moodle_exception('templateanswerfiletoolarge', 'local_coursegen');
        }
    }

    /**
     * Upload the file and answer with the id the service gave it.
     *
     * @param string $threadid Thread id of the run.
     * @param string $callid Call id of the pending question.
     * @param stored_file $file File of the draft area.
     * @return array What the service stored.
     */
    private function send_file(string $threadid, string $callid, stored_file $file): array {
        $uploaded = $this->api->upload_answer_file($threadid, $file);
        $fileid = (string) ($uploaded['file_id'] ?? '');
        if ($fileid === '') {
            throw new \moodle_exception('templateanswerfilenotstored', 'local_coursegen');
        }
        return $this->api->answer($threadid, $callid, ['file_id' => $fileid]);
    }

    /**
     * The first file of a draft area of the current user.
     *
     * @param int $draftitemid Draft item id.
     * @return stored_file|null The file, or null when the draft area holds none.
     */
    private function draft_file(int $draftitemid): ?stored_file {
        global $USER;
        $context = \context_user::instance($USER->id);
        $storage = get_file_storage();
        $files = $storage->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return $file;
    }

    /**
     * Delete every file of a draft area of the current user.
     *
     * @param int $draftitemid Draft item id.
     */
    private function empty_draft(int $draftitemid): void {
        global $USER;
        $context = \context_user::instance($USER->id);
        $storage = get_file_storage();
        $storage->delete_area_files($context->id, 'user', 'draft', $draftitemid);
    }
}
