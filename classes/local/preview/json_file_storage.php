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
        $found = $this->matching_files((int) $contextid, (string) $component, $filearea, $itemid, $includedirs);
        $this->order($found, (string) $sort);
        return $this->keyed_by_pathnamehash($found);
    }

    /**
     * Every stored file matching the given area, in no particular order.
     *
     * @param int $contextid
     * @param string $component
     * @param string|false $filearea
     * @param int|false $itemid
     * @param bool $includedirs
     * @return json_file[]
     */
    protected function matching_files(int $contextid, string $component, $filearea, $itemid, bool $includedirs): array {
        $found = [];
        foreach ($this->files as $file) {
            if ($contextid !== $file->get_contextid() || $component !== $file->get_component()) {
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
        return $found;
    }

    /**
     * A list of files, keyed by pathname hash, as the file storage keys them.
     *
     * @param json_file[] $files
     * @return json_file[]
     */
    protected function keyed_by_pathnamehash(array $files): array {
        $keyed = [];
        foreach ($files as $file) {
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
        $result = array('dirname'=>'', 'dirfile'=>null, 'subdirs'=>array(), 'files'=>array());
        $files = $this->get_area_files($contextid, $component, $filearea, $itemid, '', true);
        $result = $this->place_directories($result, $files);
        $result = $this->place_files($result, $files);
        $result = $this->sort_area_tree($result);
        return $result;
    }

    /**
     * Builds the tree's directory structure from the directory-marker files,
     * removing each from the list as it is placed.
     *
     * @param array $result
     * @param json_file[] $files Passed by reference: directory entries are removed.
     * @return array
     */
    protected function place_directories(array $result, array &$files): array {
        // first create directory structure
        foreach ($files as $hash=>$dir) {
            if (!$dir->is_directory()) {
                continue;
            }
            unset($files[$hash]);
            if ($dir->get_filepath() === '/') {
                $result['dirfile'] = $dir;
                continue;
            }
            $node = &$this->tree_node_for($result, $dir->get_filepath());
            $node['dirfile'] = $dir;
            unset($node);
        }
        return $result;
    }

    /**
     * Places every remaining (non-directory) file into the tree.
     *
     * @param array $result
     * @param json_file[] $files
     * @return array
     */
    protected function place_files(array $result, array $files): array {
        foreach ($files as $hash=>$file) {
            $node = &$this->tree_node_for($result, $file->get_filepath());
            $node['files'][$file->get_filename()] = $file;
            unset($node);
        }
        return $result;
    }

    /**
     * The tree node a filepath's directory parts lead to, creating any that
     * are missing along the way.
     *
     * @param array $result Passed by reference: the tree grows in place.
     * @param string $filepath
     * @return array Reference to the leaf node.
     */
    protected function &tree_node_for(array &$result, string $filepath): array {
        $trimmed = trim($filepath, '/');
        $parts = explode('/', $trimmed);
        $pointer = &$result;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // A file whose directory was never listed still has a place.
            if (!isset($pointer['subdirs'][$part])) {
                $pointer['subdirs'][$part] = array('dirname'=>$part, 'dirfile'=>null, 'subdirs'=>array(), 'files'=>array());
            }
            $pointer = &$pointer['subdirs'][$part];
        }
        return $pointer;
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
                $value = $this->sort_subdirs($value);
            } else if ($key == 'files') {
                core_collator::ksort($value, core_collator::SORT_NATURAL);
            }
        }
        return $tree;
    }

    /**
     * Sorts every subdirectory's own tree, recursively.
     *
     * @param array $subdirs
     * @return array
     */
    protected function sort_subdirs(array $subdirs): array {
        foreach ($subdirs as $subdirname => $subtree) {
            $subdirs[$subdirname] = $this->sort_area_tree($subtree);
        }
        return $subdirs;
    }

    /**
     * Put files in the order an ORDER BY list of their columns asks for.
     *
     * @param json_file[] $files
     * @param string $sort
     */
    protected function order(array &$files, string $sort): void {
        $rawterms = explode(',', $sort);
        $trimmedterms = array_map('trim', $rawterms);
        $termstrings = array_filter($trimmedterms);

        $terms = [];
        foreach ($termstrings as $term) {
            $terms[] = $this->sort_term($term);
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

    /**
     * One ORDER BY term ("column" or "column DESC"), as a [column, direction] pair.
     *
     * @param string $term
     * @return array [string $column, int $direction] (1 ascending, -1 descending).
     */
    protected function sort_term(string $term): array {
        $parts = preg_split('/\s+/', $term);
        $column = strtolower($parts[0]);

        $rawdirection = $parts[1] ?? 'ASC';
        $direction = strtoupper($rawdirection);
        $factor = 1;
        if ($direction === 'DESC') {
            $factor = -1;
        }
        return [$column, $factor];
    }
}
