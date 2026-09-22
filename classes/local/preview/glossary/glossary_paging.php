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
 * mod/glossary/lib.php's glossary_get_paging_bar(), ported verbatim: it
 * takes no instance state, only its own arguments, so it needs nothing from
 * the trait it used to sit in beyond a home under the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_paging {
    /**
     * mod/glossary/lib.php glossary_get_paging_bar().
     *
     * @param int $totalcount
     * @param int $page
     * @param int $perpage
     * @param string $baseurl
     * @param int $maxpageallowed
     * @param int $maxdisplay
     * @param string $separator
     * @param string $specialtext
     * @param int $specialvalue
     * @param bool $previousandnext
     * @return string
     */
    public static function glossary_get_paging_bar($totalcount, $page, $perpage, $baseurl, $maxpageallowed = 99999,
            $maxdisplay = 20, $separator = "&nbsp;", $specialtext = "", $specialvalue = -1, $previousandnext = true) {
        global $OUTPUT;

        if ($totalcount <= $perpage) {
            return '';
        }

        $data = self::glossary_paging_data($totalcount, $page, $perpage, $baseurl, $maxpageallowed, $maxdisplay,
            $separator, $specialtext, $specialvalue, $previousandnext);
        return $OUTPUT->render_from_template('local_coursegen/preview_glossary_paging', $data);
    }

    /**
     * glossary_get_paging_bar(), as data for preview_glossary_paging.mustache.
     *
     * @param int $totalcount
     * @param int $page
     * @param int $perpage
     * @param string $baseurl
     * @param int $maxpageallowed
     * @param int $maxdisplay
     * @param string $separator
     * @param string $specialtext
     * @param int $specialvalue
     * @param bool $previousandnext
     * @return array
     */
    protected static function glossary_paging_data($totalcount, $page, $perpage, $baseurl, $maxpageallowed,
            $maxdisplay, $separator, $specialtext, $specialvalue, $previousandnext): array {
        $maxpage = (int) (($totalcount - 1) / $perpage);

        // Whether the special entry is the one selected is decided from the
        // page as it was asked for, before it is clamped into the display
        // range below - a request for the special page itself is often
        // outside that range (glossary's own call passes -1 for both).
        $showspecial = !empty($specialtext);
        $specialselected = $showspecial && $page == $specialvalue;

        $page = max(0, $page);
        $page = min($page, $maxpageallowed, $maxpage);

        $pagefrom = max(0, $page - (int) ($maxdisplay / 2));
        $pageto = min($pagefrom + $maxdisplay - 1, $maxpageallowed, $maxpage);
        if ($pageto - $pagefrom < $maxdisplay - 1 && $pageto - $maxdisplay + 1 > 0) {
            $pagefrom = $pageto - $maxdisplay + 1;
        }

        $pages = [];
        for ($pagenum = $pagefrom; $pagenum <= $pageto; $pagenum++) {
            $pages[] = [
                'url' => "{$baseurl}page=$pagenum",
                'label' => $pagenum + 1,
                'current' => $pagenum == $page && !$specialselected,
            ];
        }

        return [
            'show' => true,
            'separator' => $separator,
            'hasprev' => $page > 0 && $previousandnext,
            'prevurl' => "{$baseurl}page=" . ($page - 1),
            'prevlabel' => get_string('previous'),
            'showfirstpage' => $pagefrom > 0,
            'firsturl' => "{$baseurl}page=0",
            'showfirstellipsis' => $pagefrom > 1,
            'pages' => $pages,
            'showlastellipsis' => $pageto < $maxpage - 1,
            'showlastpage' => $pageto < $maxpage,
            'lasturl' => "{$baseurl}page=$maxpage",
            'lastlabel' => $maxpage + 1,
            'hasnext' => $page < $maxpage && $page < $maxpageallowed && $previousandnext,
            'nexturl' => "{$baseurl}page=" . ($page + 1),
            'nextlabel' => get_string('next'),
            'showspecial' => $showspecial,
            'specialselected' => $specialselected,
            'specialurl' => "{$baseurl}page=$specialvalue",
            'specialtext' => $specialtext,
        ];
    }
}
