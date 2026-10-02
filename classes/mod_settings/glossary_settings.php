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

namespace local_coursegen\mod_settings;

use mod_glossary_external;

/**
 * Class glossary_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_settings extends base_settings {
    /**
     * Add specific settings for glossary module.
     */
    public function add_settings() {
        foreach ($this->modsettings['entries'] as $entry) {
            $this->add_entry($entry);
        }
    }

    /**
     * Add entry to glossary.
     *
     * @param array $entry Entry data.
     */
    protected function add_entry(array $entry) {
        $definition = $entry['definition_editor']['text'];
        $options = [
            [
                'name' => 'usedynalink',
                'value' => true,
            ],
        ];
        mod_glossary_external::add_entry(
            $this->cm->instance,
            $entry['concept'],
            $definition,
            FORMAT_HTML,
            $options
        );
    }
}
