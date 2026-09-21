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
        $out = '';
        if ($mode != 'date') {
            if ($this->glossary->showalphabet) {
                $out .= '<div class="glossaryexplain">' . get_string("explainalphabet", "glossary") . '</div><br />';
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
        $out = '';
        if ($this->glossary->showall) {
            $strallentries       = get_string("allentries", "glossary");
            if ($hook == 'ALL') {
                $out .= "<b>$strallentries</b>";
            } else {
                $strexplainall = strip_tags(get_string("explainall", "glossary"));
                $out .= "<a title=\"$strexplainall\" href=\"" . $this->url(['mode' => $mode, 'hook' => 'ALL']) . "\">$strallentries</a>";
            }
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_special_links().
     *
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_special_links($mode, $hook) {
        $out = '';
        if ($this->glossary->showspecial) {
            $strspecial          = get_string("special", "glossary");
            if ($hook == 'SPECIAL') {
                $out .= "<b>$strspecial</b> | ";
            } else {
                $strexplainspecial = strip_tags(get_string("explainspecial", "glossary"));
                $out .= "<a title=\"$strexplainspecial\" href=\"" . $this->url(['mode' => $mode, 'hook' => 'SPECIAL']) . "\">$strspecial</a> | ";
            }
        }
        return $out;
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
        $out = '';
        if ($this->glossary->showalphabet) {
            $alphabet = explode(",", get_string('alphabet', 'langconfig'));
            for ($i = 0; $i < count($alphabet); $i++) {
                if ($hook == $alphabet[$i] and $hook) {
                    $out .= "<b>$alphabet[$i]</b>";
                } else {
                    $out .= "<a href=\"" . $this->url(['mode' => $mode, 'hook' => $alphabet[$i], 'sortkey' => $sortkey, 'sortorder' => $sortorder]) . "\">$alphabet[$i]</a>";
                }
                $out .= ' | ';
            }
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
        usort($all, static function (stdClass $a, stdClass $b): int {
            $order = strcmp(core_text::strtolower((string) $a->concept), core_text::strtolower((string) $b->concept));
            return $order !== 0 ? $order : ((int) $a->id <=> (int) $b->id);
        });
        $count = count($all);
        return [array_slice($all, $from, $limit ?: null), $count];
    }
}
