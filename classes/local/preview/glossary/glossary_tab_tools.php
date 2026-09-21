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
        global $USER, $CFG;
        $items = [];
        $buttons = [];
        $openinnewwindow = [];

        if (has_capability('mod/glossary:import', $this->context)) {
            $items['button'] = new single_button(
                ($this->urls)([]),
                get_string('importentries', 'glossary')
            );
        }

        if (has_capability('mod/glossary:export', $this->context)) {
            $url = ($this->urls)(['mode' => $mode, 'hook' => $hook]);
            $buttons[get_string('export', 'glossary')] = $url->out(false);
        }

        if (has_capability('mod/glossary:manageentries', $this->context) or $this->glossary->allowprintview) {
            $params = array(
                'id'        => $this->cm->id,
                'mode'      => $mode,
                'hook'      => $hook,
                'sortkey'   => $sortkey,
                'sortorder' => $sortorder,
                'offset'    => $offset,
                'pagelimit' => $pagelimit,
            );
            $printurl = ($this->urls)($params);
            $buttons[get_string('printerfriendly', 'glossary')] = $printurl->out(false);
            // Every tool leads to the preview, so the ones that open a new
            // window are told apart by name, not by address.
            $openinnewwindow[] = get_string('printerfriendly', 'glossary');
        }

        if (!empty($CFG->enablerssfeeds) && !empty($CFG->glossary_enablerssfeeds)
                && $this->glossary->rsstype && $this->glossary->rssarticles
                && has_capability('mod/glossary:view', $this->context)) {
            require_once("$CFG->libdir/rsslib.php");
            $string = get_string('rssfeed', 'glossary');
            $url = ($this->urls)([]);
            $buttons[$string] = $url->out(false);
            $openinnewwindow[] = $string;
        }

        foreach ($items as $key => $value) {
            $items[$key] = $value->export_for_template($output);
        }

        if ($buttons) {
            foreach ($buttons as $index => $value) {
                $items['select']['options'][] = [
                    'url' => $value,
                    'string' => $index,
                    'openinnewwindow' => ($openinnewwindow ? in_array($index, $openinnewwindow) : false),
                ];
            }
        }

        return $items;
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
                $active = $active ?? $baseurl->out(false);
                $active = ($tabinfo['mode'] == $mode ? $baseurl->out(false) : $active);
                $options[get_string($tabinfo['descriptor'], 'glossary')] = $baseurl->out(false);
            }
        }

        if ($tab < self::GLOSSARY_STANDARD_VIEW || $tab > self::GLOSSARY_AUTHOR_VIEW) {
            $options[get_string('edit')] = '#';
        }

        if (count($options) > 1) {
            $select = new url_select(array_flip($options), $active, null);
            $select->set_label(get_string('explainalphabet', 'glossary'), ['class' => 'sr-only']);
            return $select->export_for_template($output);
        }

        return null;
    }

}
