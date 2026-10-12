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
     * Returns the data add_moduleinfo needs to create the page.
     *
     * A page the AI service wrote as a standard activity already carries its editor fields and is created as it is.
     * Only the page the template agent wrote, which carries its text as plain html, is built from its template page.
     *
     * @return object The module info of the page.
     */
    public function get_parameters() {
        $parameters = (array) $this->parameters;
        if ($this->has_page_editor($parameters)) {
            return $this->parameters;
        }

        $rawcmid = $parameters['source_cmid'] ?? 0;
        $sourcecmid = (int) $rawcmid;
        unset($parameters['source_cmid']);

        $info = agent_page_moduleinfo::build($parameters, $sourcecmid);
        return (object) $info;
    }

    /**
     * Whether the parameters already hold the text of the page in the editor field the page module reads.
     *
     * @param array $parameters The parameters of the generated activity.
     * @return bool True when the page text is there as an editor field.
     */
    private function has_page_editor(array $parameters): bool {
        $editor = $parameters['page'] ?? null;
        if (is_object($editor)) {
            $editor = (array) $editor;
        }
        if (!is_array($editor)) {
            return false;
        }
        return array_key_exists('text', $editor);
    }
}
