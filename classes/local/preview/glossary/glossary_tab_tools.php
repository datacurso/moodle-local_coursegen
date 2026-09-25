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

namespace local_coursegen\local\preview\glossary;

/**
 * The extra tools (category/date/author view links) mod_glossary's action
 * bar offers, and the jump links each browse tab carries. Kept apart from
 * glossary_action_bar.php only because together they crossed the 250-line
 * cap - both are the same "draw the page chrome" concern, split for size
 * alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_tab_tools {

    /**
     * standard_action_bar::get_additional_tools().
     *
     * @param \renderer_base $output
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @param int $offset
     * @param int $pagelimit
     * @return array
     */
    protected function get_additional_tools(
        \renderer_base $output,
        string $mode,
        string $hook,
        string $sortkey,
        string $sortorder,
        int $offset,
        int $pagelimit
    ): array {
        // The tools import, export, print and feed the real glossary, and
        // every one of them leaves the page. Nobody may act on an activity
        // that does not exist: none is offered. The search box beside them
        // stays.
        return [];
    }

    /**
     * standard_action_bar::generate_tab_jumps().
     *
     * @param \renderer_base $output
     * @param string $mode
     * @param int $tab
     * @return array|null
     */
    protected function generate_tab_jumps(\renderer_base $output, string $mode, int $tab) {
        $tabs = $this->glossary_get_visible_tabs($this->displayformat);
        $validtabs = [
            self::GLOSSARY_STANDARD => [
                'mode' => 'letter',
                'descriptor' => 'standardview',
            ],
            self::GLOSSARY_CATEGORY => [
                'mode' => 'cat',
                'descriptor' => 'categoryview',
            ],
            self::GLOSSARY_DATE => [
                'mode' => 'date',
                'descriptor' => 'dateview',
            ],
            self::GLOSSARY_AUTHOR => [
                'mode' => 'author',
                'descriptor' => 'authorview',
            ],
        ];

        $baseurl = ($this->urls)([]);
        $active = null;
        $options = [];
        foreach ($validtabs as $key => $tabinfo) {
            if (in_array($key, $tabs)) {
                $baseurl->params(['mode' => $tabinfo['mode']]);
                $tabouturl = $baseurl->out(false);
                $active = $active ?? $tabouturl;
                if ($tabinfo['mode'] == $mode) {
                    $active = $tabouturl;
                }
                $taboptionlabel = get_string($tabinfo['descriptor'], 'glossary');
                $options[$taboptionlabel] = $tabouturl;
            }
        }

        if ($tab < self::GLOSSARY_STANDARD_VIEW || $tab > self::GLOSSARY_AUTHOR_VIEW) {
            $editlabel = get_string('edit');
            $options[$editlabel] = '#';
        }

        if (count($options) > 1) {
            $urloptions = array_flip($options);
            $select = new url_select($urloptions, $active, null);
            $selectlabel = get_string('explainalphabet', 'glossary');
            $select->set_label($selectlabel, ['class' => 'sr-only']);
            return $select->export_for_template($output);
        }

        return null;
    }

}
