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

namespace local_coursegen\admin;

use admin_setting_heading;
use local_coursegen\output\tenant_scope_notice;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Read-only heading telling which tenant the settings of an admin page apply to.
 *
 * The tenant name is resolved when the page is rendered (not when the admin
 * tree is built), so building the tree never depends on the tenancy state.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_tenant_scope_notice extends admin_setting_heading {
    /**
     * Constructor.
     *
     * @param string $name Unique setting name, e.g. 'local_coursegen/tenantscopenotice'.
     */
    public function __construct(string $name) {
        parent::__construct($name, '', '');
    }

    /**
     * Renders the tenant scope notice.
     *
     * @param mixed $data Unused.
     * @param string $query Unused.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;
        return $OUTPUT->render(new tenant_scope_notice());
    }
}
