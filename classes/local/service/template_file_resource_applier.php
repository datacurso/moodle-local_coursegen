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

/**
 * Gives the copies of the template resources the files the run attached to them.
 *
 * Each file is downloaded into the draft area of the user, put in the resource and deleted from the draft area
 * straight away, whether the replacement worked or not, so no copy of it stays behind.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_file_resource_applier {
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
     * Replace the file of every copied resource that has one attached by the run.
     *
     * @param string $threadid Thread id of the run, for example "5c1e2a".
     * @param array[] $selected Output of template_file_resources::select().
     * @param int[] $copiedcms Template cmid => cmid of its copy in the new course.
     * @return string[] Names of the files that could not be put in their resource.
     */
    public function apply(string $threadid, array $selected, array $copiedcms): array {
        $failures = [];
        foreach ($selected as $entry) {
            if (!$this->apply_entry($threadid, $entry, $copiedcms)) {
                $failures[] = $this->file_name($entry);
            }
        }
        return $failures;
    }

    /**
     * Put the file of one selected resource in the copy of that resource.
     *
     * @param string $threadid Thread id of the run.
     * @param array $entry One entry of template_file_resources::select().
     * @param int[] $copiedcms Template cmid => cmid of its copy in the new course.
     * @return bool True when the copy now holds the file.
     */
    private function apply_entry(string $threadid, array $entry, array $copiedcms): bool {
        $cmid = (int) $entry['cmid'];
        $copied = $copiedcms[$cmid] ?? null;
        if ($copied === null) {
            return false;
        }
        return $this->apply_one($threadid, $entry['file'], (int) $copied);
    }

    /**
     * The name of the file of a selected resource.
     *
     * @param array $entry One entry of template_file_resources::select().
     * @return string File name, for example "guide.pdf".
     */
    private function file_name(array $entry): string {
        $name = $entry['file']['filename'];
        return (string) $name;
    }

    /**
     * Download one file and put it in a resource.
     *
     * @param string $threadid Thread id of the run.
     * @param array $file Entry of generated_files: file_id and filename.
     * @param int $cmid Course module id of the copy of the resource.
     * @return bool True when the resource now holds the file.
     */
    private function apply_one(string $threadid, array $file, int $cmid): bool {
        $draftid = file_get_unused_draft_itemid();
        $fileid = (string) $file['file_id'];
        $filename = (string) $file['filename'];
        try {
            $stored = $this->api->download_generated_file($threadid, $fileid, $filename, ['itemid' => $draftid]);
            if ($stored === null) {
                return false;
            }
            resource_file_replacer::replace($cmid, $stored);
            return true;
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            debugging('local_coursegen: the file ' . $filename . ' could not be put in its resource: ' . $reason, DEBUG_DEVELOPER);
            return false;
        } finally {
            $this->empty_draft($draftid);
        }
    }

    /**
     * Delete every file of a draft area of the current user.
     *
     * @param int $draftid Draft item id.
     */
    private function empty_draft(int $draftid): void {
        global $USER;
        $context = \context_user::instance($USER->id);
        $storage = get_file_storage();
        $storage->delete_area_files($context->id, 'user', 'draft', $draftid);
    }
}
