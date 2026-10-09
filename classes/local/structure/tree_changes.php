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

namespace local_coursegen\local\structure;

/**
 * Compares the tree of a template activity with the same tree after the agent rewrote its words.
 *
 * Only texts that differ are reported. The agent keeps the tags, the rows and their ids, so the two trees have the
 * same shape; a key that only one side has is left alone, and so is every key that names a row or its owner.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tree_changes {
    /** @var string[] Keys that identify a row or its module: they are never rewritten. */
    private const IDENTITY_KEYS = ['id', 'moduleid', 'modulename', 'contextid'];

    /**
     * The texts that differ between the template tree and the rewritten tree.
     *
     * @param array $source The tree of the template activity as it is now.
     * @param array $rewritten The tree the agent returned.
     * @return tree_change[]
     */
    public static function between(array $source, array $rewritten): array {
        $changes = [];
        self::collect($source, $rewritten, [], $changes);
        return $changes;
    }

    /**
     * Walk both trees together, level by level.
     *
     * @param array $source One level of the template tree.
     * @param array $rewritten The same level of the rewritten tree.
     * @param array $path The keys already walked.
     * @param tree_change[] $changes Filled with the texts that differ.
     * @return void
     */
    private static function collect(array $source, array $rewritten, array $path, array &$changes): void {
        foreach ($rewritten as $key => $value) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $here = array_merge($path, [$key]);
            self::collect_one($source[$key], $value, $here, $changes);
        }
    }

    /**
     * One entry of a level: go deeper into a list or a row, or compare a text.
     *
     * @param mixed $sourcevalue What the template tree holds.
     * @param mixed $value What the rewritten tree holds.
     * @param array $here The keys that lead to this entry.
     * @param tree_change[] $changes Filled with the texts that differ.
     * @return void
     */
    private static function collect_one($sourcevalue, $value, array $here, array &$changes): void {
        if (is_array($value) && is_array($sourcevalue)) {
            self::collect($sourcevalue, $value, $here, $changes);
            return;
        }
        if (!is_string($value) || !is_string($sourcevalue)) {
            return;
        }
        $key = end($here);
        if (in_array($key, self::IDENTITY_KEYS, true)) {
            return;
        }
        if ($value === $sourcevalue) {
            return;
        }
        $changes[] = new tree_change($here, $value);
    }
}
