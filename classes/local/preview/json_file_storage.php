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

use core_collator;

/**
 * The files of an activity, answered from the payload instead of the file storage.
 *
 * A module's view code asks the file storage for the files of an area, or for
 * the area laid out as a tree of directories. The payload lists every file the
 * activity holds, so the same two questions are answered from it, the way
 * file_storage::get_area_files() and get_area_tree() answer them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_file_storage {
    /** @var json_file[] */
    protected array $files = [];

    /**
     * Constructor.
     *
     * @param array $files The files as the payload lists them.
     */
    public function __construct(array $files) {
        foreach ($files as $row) {
            if (is_array($row)) {
                $this->files[] = new json_file($row);
            }
        }
    }

    /**
     * file_storage::get_area_files(), against the payload.
     *
     * @param int $contextid
     * @param string $component
     * @param string|false $filearea
     * @param int|false $itemid
     * @param string $sort An ORDER BY list of the file's columns.
     * @param bool $includedirs
     * @return json_file[] Keyed by pathname hash, as the file storage keys them.
     */
    public function get_area_files($contextid, $component, $filearea = false, $itemid = false,
            $sort = "itemid, filepath, filename", $includedirs = true): array {
        $found = [];
        foreach ($this->files as $file) {
            if ((int) $contextid !== $file->get_contextid() || (string) $component !== $file->get_component()) {
                continue;
            }
            if ($filearea !== false && (string) $filearea !== $file->get_filearea()) {
                continue;
            }
            if ($itemid !== false && (int) $itemid !== $file->get_itemid()) {
                continue;
            }
            if (!$includedirs && $file->is_directory()) {
                continue;
            }
            $found[] = $file;
        }
        $this->order($found, (string) $sort);
        $keyed = [];
        foreach ($found as $file) {
            $keyed[$file->get_pathnamehash()] = $file;
        }
        return $keyed;
    }

    /**
     * file_storage::get_area_tree(), against the payload.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @return array
     */
    public function get_area_tree($contextid, $component, $filearea, $itemid): array {
        $result = ['dirname' => '', 'dirfile' => null, 'subdirs' => [], 'files' => []];
        $files = $this->get_area_files($contextid, $component, $filearea, $itemid, '', true);
        $this->build_directory_structure($result, $files);
        $this->place_files_in_tree($result, $files);
        $result = $this->sort_area_tree($result);
        return $result;
    }

    /**
     * Walk to a filepath's directory node inside the tree, creating any
     * missing subdirectory nodes along the way.
     *
     * @param array $root The tree node to walk from, by reference.
     * @param string $filepath
     * @return array The filepath's own directory node, by reference.
     */
    private function &directory_node(array &$root, string $filepath): array {
        $parts = explode('/', trim($filepath, '/'));
        $pointer =& $root;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!isset($pointer['subdirs'][$part])) {
                $pointer['subdirs'][$part] = ['dirname' => $part, 'dirfile' => null, 'subdirs' => [], 'files' => []];
            }
            $pointer =& $pointer['subdirs'][$part];
        }
        return $pointer;
    }

    /**
     * Fold every directory entry from the area's files into the tree, and
     * remove those entries from $files - what is left afterwards is plain
     * files only.
     *
     * @param array $result The tree, by reference.
     * @param array $files The area's files, by reference.
     */
    private function build_directory_structure(array &$result, array &$files): void {
        // first create directory structure
        foreach ($files as $hash => $dir) {
            if (!$dir->is_directory()) {
                continue;
            }
            unset($files[$hash]);
            if ($dir->get_filepath() === '/') {
                $result['dirfile'] = $dir;
                continue;
            }
            $node =& $this->directory_node($result, $dir->get_filepath());
            $node['dirfile'] = $dir;
            unset($node);
        }
    }

    /**
     * Place every remaining (non-directory) file into its directory node.
     *
     * A file whose directory was never listed still has a place: the node
     * is created on the way down, the same as for a listed directory.
     *
     * @param array $result The tree, by reference.
     * @param array $files The area's files left after build_directory_structure().
     */
    private function place_files_in_tree(array &$result, array $files): void {
        foreach ($files as $file) {
            $node =& $this->directory_node($result, $file->get_filepath());
            $node['files'][$file->get_filename()] = $file;
            unset($node);
        }
    }

    /**
     * file_storage::sort_area_tree().
     *
     * @param array $tree
     * @return array
     */
    protected function sort_area_tree($tree) {
        foreach ($tree as $key => &$value) {
            if ($key == 'subdirs') {
                core_collator::ksort($value, core_collator::SORT_NATURAL);
                $this->sort_subdirs($value);
            } else if ($key == 'files') {
                core_collator::ksort($value, core_collator::SORT_NATURAL);
            }
        }
        return $tree;
    }

    /**
     * Recursively sort every subdirectory node.
     *
     * @param array $subdirs
     */
    private function sort_subdirs(array &$subdirs): void {
        foreach ($subdirs as $subdirname => &$subtree) {
            $subtree = $this->sort_area_tree($subtree);
        }
    }

    /**
     * Put files in the order an ORDER BY list of their columns asks for.
     *
     * @param json_file[] $files
     * @param string $sort
     */
    protected function order(array &$files, string $sort): void {
        $terms = [];
        foreach (array_filter(array_map('trim', explode(',', $sort))) as $term) {
            $parts = preg_split('/\s+/', $term);
            $direction = 1;
            if (strtoupper($parts[1] ?? 'ASC') === 'DESC') {
                $direction = -1;
            }
            $terms[] = [strtolower($parts[0]), $direction];
        }
        if (!$terms) {
            return;
        }
        usort($files, static function (json_file $a, json_file $b) use ($terms): int {
            foreach ($terms as [$column, $direction]) {
                $getter = 'get_' . $column;
                if (!method_exists($a, $getter)) {
                    continue;
                }
                $order = $a->$getter() <=> $b->$getter();
                if ($order !== 0) {
                    return $order * $direction;
                }
            }
            return 0;
        });
    }
}
