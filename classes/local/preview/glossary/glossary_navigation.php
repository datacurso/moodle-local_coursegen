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

use core_text;
use stdClass;

/**
 * mod_glossary's alphabet menu (A-Z, ALL, SPECIAL) and its paging bar,
 * built the same way view.php builds them. Kept apart from view.php only because
 * together they crossed the 250-line cap - both are the same "browse
 * navigation" concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_navigation {
    /**
     * mod/glossary/lib.php glossary_print_alphabet_menu(), returning what it prints.
     *
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @return string
     */
    protected function glossary_print_alphabet_menu($mode, $hook, $sortkey = '', $sortorder = '') {
        global $OUTPUT;
        $out = '';
        if ($mode != 'date') {
            if ($this->glossary->showalphabet) {
                $explaintext = get_string('explainalphabet', 'glossary');
                $out .= $OUTPUT->render_from_template('local_coursegen/preview_glossary_explain', [
                    'text' => $explaintext,
                ]);
            }

            $out .= $this->glossary_print_special_links($mode, $hook);

            $out .= $this->glossary_print_alphabet_links($mode, $hook, $sortkey, $sortorder);

            $out .= $this->glossary_print_all_links($mode, $hook);
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_all_links().
     *
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_all_links($mode, $hook) {
        global $OUTPUT;
        if (!$this->glossary->showall) {
            return '';
        }
        $alltext = get_string('allentries', 'glossary');
        $allexplaintext = get_string('explainall', 'glossary');
        $alltitle = strip_tags($allexplaintext);
        $allurl = $this->url(['mode' => $mode, 'hook' => 'ALL']);
        return $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
            'current' => $hook == 'ALL',
            'text' => $alltext,
            'title' => $alltitle,
            'url' => $allurl,
            'suffix' => '',
        ]);
    }

    /**
     * mod/glossary/lib.php glossary_print_special_links().
     *
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_special_links($mode, $hook) {
        global $OUTPUT;
        if (!$this->glossary->showspecial) {
            return '';
        }
        $specialtext = get_string('special', 'glossary');
        $specialexplaintext = get_string('explainspecial', 'glossary');
        $specialtitle = strip_tags($specialexplaintext);
        $specialurl = $this->url(['mode' => $mode, 'hook' => 'SPECIAL']);
        return $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
            'current' => $hook == 'SPECIAL',
            'text' => $specialtext,
            'title' => $specialtitle,
            'url' => $specialurl,
            'suffix' => ' | ',
        ]);
    }

    /**
     * mod/glossary/lib.php glossary_print_alphabet_links().
     *
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @return string
     */
    protected function glossary_print_alphabet_links($mode, $hook, $sortkey, $sortorder) {
        global $OUTPUT;
        if (!$this->glossary->showalphabet) {
            return '';
        }
        $out = '';
        $alphabetstring = get_string('alphabet', 'langconfig');
        $alphabet = explode(",", $alphabetstring);
        foreach ($alphabet as $letter) {
            $letterurl = $this->url(['mode' => $mode, 'hook' => $letter, 'sortkey' => $sortkey, 'sortorder' => $sortorder]);
            $out .= $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
                'current' => $hook == $letter && $hook,
                'text' => $letter,
                'url' => $letterurl,
                'suffix' => ' | ',
            ]);
        }
        return $out;
    }


    /**
     * mod/glossary/lib.php glossary_get_entries_by_letter(), against the store.
     *
     * Approved entries, filtered by the first letter of the concept, ordered
     * by concept then id, one page of them.
     *
     * @param string $letter
     * @param int $from
     * @param int $limit
     * @return array [entries, count]
     */
    protected function glossary_get_entries_by_letter(string $letter, int $from, int $limit): array {
        $all = [];
        $entries = $this->store->get_records('glossary_entries', ['glossaryid' => $this->glossary->id]);
        foreach ($entries as $entry) {
            if (empty($entry->approved)) {
                continue;
            }
            $conceptfirstchar = core_text::substr((string) $entry->concept, 0, 1);
            $first = core_text::strtoupper($conceptfirstchar);
            if ($letter != 'ALL' && $letter != 'SPECIAL' && core_text::strlen($letter)) {
                if ($first !== core_text::strtoupper($letter)) {
                    continue;
                }
            }
            if ($letter == 'SPECIAL') {
                $alphabetstring = get_string('alphabet', 'langconfig');
                $alphabet = explode(',', $alphabetstring);
                $upperalphabet = array_map([core_text::class, 'strtoupper'], $alphabet);
                if (in_array($first, $upperalphabet, true)) {
                    continue;
                }
            }
            $all[] = $entry;
        }
        usort($all, [$this, 'compare_entries_by_concept']);
        $count = count($all);
        $sizelimit = $limit;
        if (!$limit) {
            $sizelimit = null;
        }
        $page = array_slice($all, $from, $sizelimit);
        return [$page, $count];
    }

    /**
     * usort() comparator for glossary_get_entries_by_letter(): by concept,
     * then by id to break a tie.
     *
     * @param stdClass $a
     * @param stdClass $b
     * @return int
     */
    protected function compare_entries_by_concept(stdClass $a, stdClass $b): int {
        $aconcept = core_text::strtolower((string) $a->concept);
        $bconcept = core_text::strtolower((string) $b->concept);
        $order = strcmp($aconcept, $bconcept);
        if ($order !== 0) {
            return $order;
        }
        return (int) $a->id <=> (int) $b->id;
    }
}
