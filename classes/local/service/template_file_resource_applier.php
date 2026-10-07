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

use local_coursegen\utils\preview_draft_store;

/**
 * Gives the copies of the template resources the files the run attached to them.
 *
 * Each file is read from the draft area of the user, where the review preview stored it under the uid of its
 * activity (it is downloaded only when the preview did not), and is copied from there into the resource.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_file_resource_applier {
    /** @var preview_draft_store Draft area the files of the run are in. */
    private preview_draft_store $store;

    /**
     * Constructor.
     *
     * @param preview_draft_store $store Draft store of the generation session.
     */
    public function __construct(preview_draft_store $store) {
        $this->store = $store;
    }

    /**
     * Replace the file of every copied resource that has one attached by the run.
     *
     * @param array[] $selected Output of template_file_resources::select().
     * @param int[] $copiedcms Template cmid => cmid of its copy in the new course.
     * @return string[] Names of the files that could not be put in their resource.
     */
    public function apply(array $selected, array $copiedcms): array {
        $failures = [];
        foreach ($selected as $entry) {
            if (!$this->apply_entry($entry, $copiedcms)) {
                $failures[] = $this->file_name($entry);
            }
        }
        return $failures;
    }

    /**
     * Put the file of one selected resource in the copy of that resource.
     *
     * @param array $entry One entry of template_file_resources::select().
     * @param int[] $copiedcms Template cmid => cmid of its copy in the new course.
     * @return bool True when the copy now holds the file.
     */
    private function apply_entry(array $entry, array $copiedcms): bool {
        $cmid = (int) $entry['cmid'];
        $copied = $copiedcms[$cmid] ?? null;
        if ($copied === null) {
            return false;
        }
        return $this->apply_one((string) $entry['uid'], $entry['file'], (int) $copied);
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
     * Put one stored file in a resource.
     *
     * @param string $uid Opaque uid of the activity the file belongs to.
     * @param array $file Entry of generated_files: file_id and filename.
     * @param int $cmid Course module id of the copy of the resource.
     * @return bool True when the resource now holds the file.
     */
    private function apply_one(string $uid, array $file, int $cmid): bool {
        try {
            $stored = $this->store->get($uid, $file);
            resource_file_replacer::replace($cmid, $stored);
            return true;
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            $filename = (string) $file['filename'];
            debugging('local_coursegen: the file ' . $filename . ' could not be put in its resource: ' . $reason, DEBUG_DEVELOPER);
            return false;
        }
    }
}
