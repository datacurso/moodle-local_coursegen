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

use local_coursegen\utils\preview_draft_store;

/**
 * Makes the files the AI service made for an activity visible in its review preview.
 *
 * The finished activity names them as "@@PLUGINFILE@@/name", which only means something once the activity exists.
 * The preview keeps each generated file in the draft area of the reviewer, in the folder of the opaque uid of its
 * activity (downloaded once, see preview_draft_store), and writes the draft address in place of the placeholder.
 * The stored result is left as it is: creating the course needs the placeholders.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_file_preview {
    /** @var preview_draft_store Where the files are kept. */
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
     * The parameters of an activity with its generated files addressed.
     *
     * @param array $parameters The activity's parameters.
     * @param array[] $entries The activity's generated_files.
     * @param string $uid Opaque uid of the activity, for example "7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10".
     * @return array The same parameters, every "@@PLUGINFILE@@/name" of a generated file written as its address.
     */
    public function addressed(array $parameters, array $entries, string $uid): array {
        $addresses = [];
        foreach ($entries as $entry) {
            $this->store->get($uid, $entry);
            $filename = (string) $entry['filename'];
            $addresses[$filename] = $this->store->address($uid, $filename);
        }
        if (!$addresses) {
            return $parameters;
        }
        return $this->walk($parameters, $addresses);
    }

    /**
     * The file rows of a resource whose file the run attached, in place of the template's own.
     *
     * Each row describes the file the way the module shows its file (its own context and area), and carries the
     * address of the draft area of the reviewer, where the file is stored under the uid, to serve it from.
     *
     * @param array[] $entries The activity's generated_files.
     * @param string $uid Opaque uid of the activity.
     * @param int $contextid Context the preview of the module reads its files from, for example 4511.
     * @return array[] Rows the resource preview reads its files from.
     */
    public function resource_rows(array $entries, string $uid, int $contextid): array {
        $rows = [];
        foreach ($entries as $entry) {
            $stored = $this->store->get($uid, $entry);
            $filename = $stored->get_filename();
            $hash = $stored->get_pathnamehash();
            $size = (int) $stored->get_filesize();
            $mimetype = $stored->get_mimetype();
            $address = $this->store->address($uid, $filename);
            $rows[] = [
                'id' => 'draft-' . $hash,
                'contextid' => $contextid,
                'component' => 'mod_resource',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
                'filesize' => $size,
                'mimetype' => $mimetype,
                'url' => $address,
            ];
        }
        return $rows;
    }

    /**
     * A value with the placeholders of every text inside it replaced.
     *
     * @param mixed $value
     * @param string[] $addresses File name => address.
     * @return mixed
     */
    private function walk($value, array $addresses) {
        if (is_string($value)) {
            return $this->replace($value, $addresses);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->walk($item, $addresses);
        }
        return $value;
    }

    /**
     * A text with the placeholders of the generated files replaced by their addresses.
     *
     * @param string $text
     * @param string[] $addresses File name => address.
     * @return string
     */
    private function replace(string $text, array $addresses): string {
        if (!str_contains($text, '@@PLUGINFILE@@/')) {
            return $text;
        }
        $callback = static function (array $found) use ($addresses): string {
            $name = rawurldecode($found[1]);
            return $addresses[$name] ?? $found[0];
        };
        return preg_replace_callback('~@@PLUGINFILE@@/([^"\'<>\s?#)]+)~u', $callback, $text) ?? $text;
    }
}
