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
 * ported the same way view.php is. Kept apart from view.php only because
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
                $out .= $OUTPUT->render_from_template('local_coursegen/preview_glossary_explain', [
                    'text' => get_string('explainalphabet', 'glossary'),
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
        return $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
            'current' => $hook == 'ALL',
            'text' => get_string('allentries', 'glossary'),
            'title' => strip_tags(get_string('explainall', 'glossary')),
            'url' => $this->url(['mode' => $mode, 'hook' => 'ALL']),
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
        return $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
            'current' => $hook == 'SPECIAL',
            'text' => get_string('special', 'glossary'),
            'title' => strip_tags(get_string('explainspecial', 'glossary')),
            'url' => $this->url(['mode' => $mode, 'hook' => 'SPECIAL']),
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
        $alphabet = explode(",", get_string('alphabet', 'langconfig'));
        foreach ($alphabet as $letter) {
            $out .= $OUTPUT->render_from_template('local_coursegen/preview_bold_or_link', [
                'current' => $hook == $letter && $hook,
                'text' => $letter,
                'url' => $this->url(['mode' => $mode, 'hook' => $letter, 'sortkey' => $sortkey, 'sortorder' => $sortorder]),
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
        foreach ($this->store->get_records('glossary_entries', ['glossaryid' => $this->glossary->id]) as $entry) {
            if (empty($entry->approved)) {
                continue;
            }
            $first = core_text::strtoupper(core_text::substr((string) $entry->concept, 0, 1));
            if ($letter != 'ALL' && $letter != 'SPECIAL' && core_text::strlen($letter)) {
                if ($first !== core_text::strtoupper($letter)) {
                    continue;
                }
            }
            if ($letter == 'SPECIAL') {
                $alphabet = explode(',', get_string('alphabet', 'langconfig'));
                if (in_array($first, array_map([core_text::class, 'strtoupper'], $alphabet), true)) {
                    continue;
                }
            }
            $all[] = $entry;
        }
        usort($all, [$this, 'compare_entries_by_concept']);
        $count = count($all);
        return [array_slice($all, $from, $limit ?: null), $count];
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
        $order = strcmp(core_text::strtolower((string) $a->concept), core_text::strtolower((string) $b->concept));
        if ($order !== 0) {
            return $order;
        }
        return (int) $a->id <=> (int) $b->id;
    }
}
