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

namespace local_coursegen\mod_parameters;

use local_coursegen\local\service\agent_page_moduleinfo;

defined('MOODLE_INTERNAL') || die();

/**
 * Class page_parameters
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_parameters extends base_parameters {
    /**
     * Returns the data add_moduleinfo needs to create the page the template agent wrote.
     *
     * @return object The module info of the page.
     */
    public function get_parameters() {
        $parameters = (array) $this->parameters;
        $rawcmid = $parameters['source_cmid'] ?? 0;
        $sourcecmid = (int) $rawcmid;
        unset($parameters['source_cmid']);

        $info = agent_page_moduleinfo::build($parameters, $sourcecmid);
        return (object) $info;
    }
}
