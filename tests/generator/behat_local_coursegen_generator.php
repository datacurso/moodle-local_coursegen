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

/**
 * Behat data generator for local_coursegen.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_coursegen_generator extends behat_generator_base {
    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'system instructions' => [
                'singular' => 'system instruction',
                'datagenerator' => 'system_instruction',
                'required' => ['name'],
                'switchids' => ['tenant' => 'tenantid'],
            ],
        ];
    }

    /**
     * Look up the id of a Workplace tenant from its name.
     *
     * @param string $tenantname Tenant name.
     * @return int Tenant id.
     */
    protected function get_tenant_id(string $tenantname): int {
        global $DB;

        $id = $DB->get_field('tool_tenant', 'id', ['name' => $tenantname]);
        if (!$id) {
            throw new Exception('The specified tenant with name "' . $tenantname . '" does not exist');
        }
        return (int) $id;
    }
}
