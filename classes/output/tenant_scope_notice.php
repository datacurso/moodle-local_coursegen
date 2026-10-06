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
use local_coursegen\local\tenancy;
use renderable;
use templatable;

/**
 * Read-only notice naming the tenant the configuration of a page applies to.
 *
 * Every plugin configuration page (general and development settings, image
 * generation, system instructions) shows it at the top: the values always
 * belong to the tenant the user is currently in, there is no tenant selector.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_scope_notice implements renderable, templatable {
    /** @var string|null Localised page description shown with the notice, null for none. */
    private ?string $description;

    /**
     * Constructor.
     *
     * @param string|null $description Localised page description, null to render none.
     */
    public function __construct(?string $description = null) {
        $this->description = $description;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $tenantname = tenancy::get_tenant_name(tenancy::get_tenant_id());
        return [
            'message' => get_string('tenantscopenotice', 'local_coursegen', $tenantname),
            'hasdescription' => $this->description !== null && $this->description !== '',
            'description' => (string) $this->description,
        ];
    }
}
