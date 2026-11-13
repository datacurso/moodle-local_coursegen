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
 * Which section of the template each section of a new course stands for.
 *
 * A course made from a template has the template's sections, so a section of
 * the new course is the section of the template with the same number: the
 * identity. A flexsections course with a different number of units is
 * resized (template_unit_resizer), and then the numbers differ: each entry of
 * sections_info says where it comes from, and a clone says it is one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_section_map {
    /** @var int[] New number => template number, only where they differ or are cloned. */
    private array $sources;

    /** @var bool[] New number => whether it is a clone. */
    private array $clones;

    /** @var int[] Template number => new number, for the sections that are not clones. */
    private array $targets;

    /**
     * Build the map from the sections of a payload.
     *
     * @param array $sectionsinfo The payload's sections_info.
     * @return self
     */
    public static function from_sections_info(array $sectionsinfo): self {
        $map = new self();
        $map->sources = [];
        $map->clones = [];
        $map->targets = [];
        foreach ($sectionsinfo as $entry) {
            $number = (int) $entry['section'];
            $source = (int) ($entry['source_section'] ?? $number);
            $map->sources[$number] = $source;
            $map->clones[$number] = !empty($entry['cloned']);
            if (empty($entry['cloned'])) {
                $map->targets[$source] = $number;
            }
        }
        return $map;
    }

    /**
     * Whether every section is the template's section of the same number.
     *
     * @param int|null $templatesections How many sections the template has; when given, a map that lacks
     *        some of them (they were removed) is not the identity.
     * @return bool
     */
    public function is_identity(?int $templatesections = null): bool {
        if ($templatesections !== null && count($this->sources) !== $templatesections) {
            return false;
        }
        foreach ($this->sources as $number => $source) {
            if ($number !== $source || $this->clones[$number]) {
                return false;
            }
        }
        return count($this->targets) === count($this->sources);
    }

    /**
     * The section of the template a section of the new course comes from.
     *
     * @param int $number
     * @return int
     */
    public function source_of(int $number): int {
        return $this->sources[$number] ?? $number;
    }

    /**
     * The section of the new course that is the template's section, null when the new course lacks it.
     *
     * A clone is never it: what the template holds in a section belongs to the section itself.
     *
     * @param int $source
     * @return int|null
     */
    public function target_of_source(int $source): ?int {
        if (!$this->sources) {
            return $source;
        }
        return $this->targets[$source] ?? null;
    }

    /**
     * Whether a section of the new course is a copy of another one.
     *
     * @param int $number
     * @return bool
     */
    public function is_cloned(int $number): bool {
        return $this->clones[$number] ?? false;
    }
}
