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

namespace local_coursegen\local\files;

/**
 * Decides which file area of a row a text column keeps its files in.
 *
 * A module declares the areas its rows may hold (see text_carrier_collector)
 * but not which text uses which. Moodle names an area after the text it
 * stores, so in nearly every case the column says it. The two places where
 * the name does not say are registered here, and so are the columns a module
 * shows as they are, without ever resolving a placeholder in them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_area_chooser {
    /**
     * Columns whose area does not carry their name: "table.column" => [component, filearea].
     *
     * A forum post keeps its message images in "post" and a glossary entry
     * keeps its definition images in "entry"; nothing in either name links them.
     */
    private const AREA_OF_COLUMN = [
        'forum_posts.message' => ['mod_forum', 'post'],
        'glossary_entries.definition' => ['mod_glossary', 'entry'],
    ];

    /**
     * Columns the module shows as written, so no file can be served from them: "table" => columns.
     *
     * A database activity prints its templates without rewriting the
     * placeholders in them (mod_data never calls file_rewrite_pluginfile_urls
     * for a template), so a text there keeps whatever address it was given.
     */
    private const SHOWN_AS_WRITTEN = [
        'data' => [
            'listtemplate', 'listtemplateheader', 'listtemplatefooter', 'singletemplate', 'asearchtemplate',
            'addtemplate', 'rsstemplate', 'rsstitletemplate', 'csstemplate', 'jstemplate',
        ],
    ];

    /**
     * Whether a column can hold a file reference the module resolves.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function holds_files(string $table, string $column): bool {
        $columns = self::SHOWN_AS_WRITTEN[$table] ?? [];
        return !in_array($column, $columns, true);
    }

    /**
     * The area a column of a row keeps its files in.
     *
     * @param text_carrier $carrier
     * @param string $column
     * @param \stored_file|null $source The file the text references when it is known, which names its own area.
     * @param string $where The activity and field, for the error.
     * @param string $file The reference, for the error.
     * @return file_area
     * @throws file_copy_exception When the row declares no area that can be told to hold the column's files.
     */
    public function choose(text_carrier $carrier, string $column, ?\stored_file $source, string $where, string $file): file_area {
        $registered = $this->registered($carrier, $column);
        $named = $this->named_after($carrier->areas, $column);
        $single = $this->only($carrier->areas);
        $ofsource = $this->of_source($carrier->areas, $source);
        $chosen = $registered ?? $named ?? $single ?? $ofsource;
        if ($chosen === null) {
            throw file_copy_exception::area_unknown($where, $file);
        }
        return $chosen;
    }

    /**
     * The area the registry assigns to a column of this row.
     *
     * @param text_carrier $carrier
     * @param string $column
     * @return file_area|null
     */
    private function registered(text_carrier $carrier, string $column): ?file_area {
        $key = $carrier->table . '.' . $column;
        if (!isset(self::AREA_OF_COLUMN[$key])) {
            return null;
        }
        [$component, $filearea] = self::AREA_OF_COLUMN[$key];
        return $this->area_named($carrier->areas, $component, $filearea);
    }

    /**
     * The one area whose name is the column's, or contains it, or is contained by it.
     *
     * @param file_area[] $areas
     * @param string $column
     * @return file_area|null
     */
    private function named_after(array $areas, string $column): ?file_area {
        $same = $this->matching($areas, $column, false);
        if (count($same) === 1) {
            return $same[0];
        }
        $related = $this->matching($areas, $column, true);
        if (count($related) === 1) {
            return $related[0];
        }
        return null;
    }

    /**
     * The areas whose name is the column's, or, when asked, contains it or is contained by it.
     *
     * @param file_area[] $areas
     * @param string $column
     * @param bool $loosely
     * @return file_area[]
     */
    private function matching(array $areas, string $column, bool $loosely): array {
        $found = [];
        foreach ($areas as $area) {
            if ($this->name_matches($area->filearea, $column, $loosely)) {
                $found[] = $area;
            }
        }
        return $found;
    }

    /**
     * Whether an area's name goes with a column's.
     *
     * @param string $filearea
     * @param string $column
     * @param bool $loosely
     * @return bool
     */
    private function name_matches(string $filearea, string $column, bool $loosely): bool {
        if ($filearea === $column) {
            return true;
        }
        if (!$loosely) {
            return false;
        }
        return str_contains($filearea, $column) || str_contains($column, $filearea);
    }

    /**
     * The area, when the row declares only one.
     *
     * @param file_area[] $areas
     * @return file_area|null
     */
    private function only(array $areas): ?file_area {
        if (count($areas) === 1) {
            return $areas[0];
        }
        return null;
    }

    /**
     * The declared area that is the one the source file was stored in.
     *
     * @param file_area[] $areas
     * @param \stored_file|null $source
     * @return file_area|null
     */
    private function of_source(array $areas, ?\stored_file $source): ?file_area {
        if ($source === null) {
            return null;
        }
        return $this->area_named($areas, $source->get_component(), $source->get_filearea());
    }

    /**
     * The area with this component and name.
     *
     * @param file_area[] $areas
     * @param string $component
     * @param string $filearea
     * @return file_area|null
     */
    private function area_named(array $areas, string $component, string $filearea): ?file_area {
        foreach ($areas as $area) {
            if ($area->component === $component && $area->filearea === $filearea) {
                return $area;
            }
        }
        return null;
    }
}
