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

use backup_nested_element;
use base_attribute;
use base_final_element;
use base_nested_element;
use local_coursegen\local\backup\backup_vars_processor;
use local_coursegen\local\backup\structure_node_resolver;

/**
 * Lists the rows an activity is made of, with the file areas its module declares for each.
 *
 * The module's own backup structure is the one place that says which tables
 * make an activity up and which file areas their texts may refer to, so a
 * module added tomorrow is covered without anybody describing it here. Walking
 * the structure of an activity that already exists yields its real rows: every
 * element is reported with the id the row has now and the item id its files
 * are stored under.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_carrier_collector extends backup_vars_processor {
    /** @var array[] The elements being walked, innermost last: {table, id, areas}. */
    protected array $open = [];

    /** @var text_carrier[] The rows found, in the order their elements finished. */
    protected array $carriers = [];

    /**
     * The rows found once the walk has finished.
     *
     * @return text_carrier[]
     */
    public function get_carriers(): array {
        return $this->carriers;
    }

    /**
     * An element is starting: its row has been read.
     *
     * @param base_nested_element $nested
     */
    public function pre_process_nested_element(base_nested_element $nested) {
        $table = structure_node_resolver::source_table($nested);
        $id = $this->id_of($nested);
        $areas = $this->areas_of($nested);
        if (!$areas && $this->open) {
            $parent = end($this->open);
            $areas = $parent['areas'];
        }
        $this->open[] = ['table' => $table, 'id' => $id, 'areas' => $areas];
    }

    /**
     * An element has finished: its row is a carrier when the table and the id are known.
     *
     * @param base_nested_element $nested
     */
    public function post_process_nested_element(base_nested_element $nested) {
        $finished = array_pop($this->open);
        if ($finished === null || $finished['table'] === null || $finished['id'] <= 0) {
            return;
        }
        $this->carriers[] = new text_carrier($finished['table'], $finished['id'], $finished['areas']);
    }

    /**
     * Nothing is read between an element's opening and its closing.
     *
     * @param base_nested_element $nested
     */
    public function process_nested_element(base_nested_element $nested) {
        return;
    }

    /**
     * Values are read when the element opens: only the id and the annotations matter.
     *
     * @param base_final_element $final
     */
    public function process_final_element(base_final_element $final) {
        return;
    }

    /**
     * Attributes are read when the element opens.
     *
     * @param base_attribute $attribute
     */
    public function process_attribute(base_attribute $attribute) {
        return;
    }

    /**
     * The id of the row an element holds, declared as an attribute or as a value.
     *
     * @param base_nested_element $nested
     * @return int Zero when the element has no id.
     */
    protected function id_of(base_nested_element $nested): int {
        $attributes = $nested->get_attributes();
        if (isset($attributes['id'])) {
            $value = $attributes['id']->get_value();
            return (int) $value;
        }
        $finals = $nested->get_final_elements();
        if (isset($finals['id'])) {
            $value = $finals['id']->get_value();
            return (int) $value;
        }
        return 0;
    }

    /**
     * The areas an element declares for its row.
     *
     * An annotation that names an element stores its files under that
     * element's value (the row id); one that names none stores them under 0.
     *
     * @param base_nested_element $nested
     * @return file_area[]
     */
    protected function areas_of(base_nested_element $nested): array {
        if (!($nested instanceof backup_nested_element)) {
            return [];
        }
        $modulecontext = (int) $this->get_var(\backup::VAR_CONTEXTID);
        $areas = [];
        foreach ($nested->get_file_annotations() as $component => $fileareas) {
            $found = $this->areas_of_component((string) $component, $fileareas, $modulecontext);
            $areas = array_merge($areas, $found);
        }
        return $areas;
    }

    /**
     * The areas one component declares.
     *
     * @param string $component
     * @param array $fileareas filearea => annotation.
     * @param int $defaultcontextid
     * @return file_area[]
     */
    protected function areas_of_component(string $component, array $fileareas, int $defaultcontextid): array {
        $areas = [];
        foreach ($fileareas as $filearea => $info) {
            $contextid = $info->contextid ?? $defaultcontextid;
            $itemid = 0;
            if ($info->element !== null) {
                $itemid = $info->element->get_value();
            }
            $areas[] = new file_area((int) $contextid, $component, (string) $filearea, (int) $itemid);
        }
        return $areas;
    }
}
