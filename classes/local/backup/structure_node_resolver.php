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

namespace local_coursegen\local\backup;

use backup_nested_element;
use base_nested_element;

/**
 * Two things structure_array_processor reads off one element as it opens or
 * closes it: where its rows come from (its table and any column aliases),
 * and where its file placeholders point once resolved. Kept apart because
 * neither needs the processor's own stack-walking state, only the element
 * (and, for files, the node already built from it) being looked at.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure_node_resolver {
    /**
     * Where this element's rows come from.
     *
     * @param base_nested_element $nested
     * @return string|null The table name, or null when the element declares none.
     */
    public static function source_table(base_nested_element $nested): ?string {
        if (!($nested instanceof backup_nested_element)) {
            return null;
        }
        $table = $nested->get_source_table();
        if ($table) {
            return $table;
        }
        $sql = (string) $nested->get_source_sql();
        if ($sql !== '' && preg_match('~FROM\s+\{(\w+)\}~i', $sql, $found)) {
            return $found[1];
        }
        return null;
    }

    /**
     * Declared column => table column, for this element, where they differ.
     *
     * An alias is declared as "this column travels under that name" and kept
     * by the element as column => final element. There is no reader for it,
     * only a writer, so it is read the one way it can be.
     *
     * @param base_nested_element $nested
     * @return array Declared column => table column.
     */
    public static function aliases(base_nested_element $nested): array {
        if (!($nested instanceof backup_nested_element)) {
            return [];
        }
        $property = new \ReflectionProperty(backup_nested_element::class, 'aliases');
        $property->setAccessible(true);
        $aliases = [];
        foreach ((array) $property->getValue($nested) as $column => $final) {
            if (is_object($final) && method_exists($final, 'get_name')) {
                $aliases[$final->get_name()] = $column;
            }
        }
        return $aliases;
    }

    /**
     * Text that names its files by a placeholder, renamed to where they are.
     *
     * A module keeps "@@PLUGINFILE@@/x.png" in its text and resolves it on the
     * way out with the file area the text belongs to. Backup keeps the
     * placeholder, because restore resolves it again; this tree is read by
     * things that own no file area, so it is resolved here, with the same
     * areas backup declares for the element and the same context the module
     * would use. Text with no placeholder is left exactly as it was.
     *
     * @param array $node
     * @param array $annotations component => filearea => info, as declared.
     * @param int $fallbackcontextid Used when an annotation names no context of its own.
     * @return array
     */
    public static function with_file_addresses(array $node, array $annotations, int $fallbackcontextid): array {
        if (!$annotations) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if (!is_string($value) || strpos($value, '@@PLUGINFILE@@') === false) {
                continue;
            }
            foreach ($annotations as $component => $areas) {
                foreach ($areas as $filearea => $info) {
                    $contextid = $info->contextid ?? null;
                    $contextid = $contextid !== null ? (int) $contextid : $fallbackcontextid;
                    $itemid = isset($info->element) && $info->element !== null ? $info->element->get_value() : null;
                    $value = file_rewrite_pluginfile_urls($value, 'pluginfile.php', $contextid, $component, $filearea, $itemid);
                }
            }
            $node[$key] = $value;
        }
        return $node;
    }
}
