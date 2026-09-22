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

namespace local_coursegen\local\preview;

use local_coursegen\local\preview\glossary\view;
use moodle_url;

/**
 * A glossary, drawn by mod_glossary's own view code run against the payload.
 *
 * The entries a glossary holds are written by its users, so a kept glossary
 * arrives without them and previews as the empty glossary it will be created
 * as. A glossary the run writes arrives with the entries the plan intends,
 * which are put in as the rows mod_glossary reads.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'glossary';
    }

    /**
     * The plan's entries become the glossary's rows.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('glossary');
        if (!$rows) {
            return;
        }
        $glossary = reset($rows);
        $now = time();
        $id = 1;
        foreach (($this->parameters['mod_settings']['entries'] ?? []) as $entry) {
            $definition = $entry['definition_editor'] ?? ($entry['definition'] ?? '');
            if (is_array($definition)) {
                $definition = $definition['text'] ?? '';
            }
            $store->add('glossary_entries', [
                'id' => $id++,
                'glossaryid' => $glossary->id,
                'userid' => 0,
                'concept' => (string) ($entry['concept'] ?? ''),
                'definition' => (string) $definition,
                'definitionformat' => FORMAT_HTML,
                'definitiontrust' => 0,
                'attachment' => '',
                'timecreated' => $now,
                'timemodified' => $now,
                'teacherentry' => 1,
                'sourceglossaryid' => 0,
                'usedynalink' => (int) ($glossary->usedynalink ?? 0),
                'casesensitive' => 0,
                'fullmatch' => 1,
                'approved' => 1,
            ]);
        }
    }

    /**
     * The glossary page, browsing by letter.
     *
     * @return string
     */
    public function render(): string {
        $glossary = $this->instance();
        if ($glossary === null) {
            return $this->nothing_yet();
        }

        $displayformat = $this->config_record('glossary_formats', ['name' => $glossary->displayformat]);
        if (!$displayformat) {
            $displayformat = (object) ['name' => $glossary->displayformat, 'showtabs' => 'standard', 'popupformatname' => $glossary->displayformat];
        }

        $view = new view(
            $glossary,
            $this->cm(),
            $this->course(),
            $this->context(),
            $this->store(),
            $displayformat,
            fn(array $params): moodle_url => $this->url_to(array_intersect_key($params, ['hook' => 1, 'page' => 1, 'mode' => 1]))
        );
        $hook = optional_param('hook', 'ALL', PARAM_ALPHANUMEXT);
        return $view->page($hook, $this->page);
    }
}
