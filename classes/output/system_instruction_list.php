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

namespace local_coursegen\output;

use core\output\renderer_base;
use moodle_url;
use renderable;
use templatable;

/**
 * List of the system instructions of the current tenant, on the management page.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_instruction_list implements renderable, templatable {
    /** @var \stdClass[] Instructions from system_instruction_service::get_available(). */
    private array $instructions;

    /**
     * Constructor.
     *
     * @param \stdClass[] $instructions Instructions of the current tenant, from system_instruction_service::get_available().
     */
    public function __construct(array $instructions) {
        $this->instructions = $instructions;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $editurl = new moodle_url('/local/coursegen/edit_system_instruction.php');
        $manageurl = new moodle_url('/local/coursegen/manage_system_instructions.php');

        $rows = [];
        foreach ($this->instructions as $instruction) {
            $id = (int) $instruction->id;
            $rows[] = [
                'id' => $id,
                'name' => format_string($instruction->name),
                'timecreated' => (int) $instruction->timecreated,
                'timemodified' => (int) $instruction->timemodified,
                'editurl' => (new moodle_url($editurl, ['id' => $id]))->out(false),
                'deleteurl' => (new moodle_url($manageurl, ['action' => 'delete', 'id' => $id]))->out(false),
            ];
        }

        return [
            'addurl' => $editurl->out(false),
            'hasinstructions' => !empty($rows),
            'instructions' => $rows,
        ];
    }
}
