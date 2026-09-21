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
    public static function glossary_get_paging_bar($totalcount, $page, $perpage, $baseurl, $maxpageallowed = 99999, $maxdisplay = 20, $separator = "&nbsp;", $specialtext = "", $specialvalue = -1, $previousandnext = true) {

        $code = '';

        $showspecial = false;
        $specialselected = false;

        //Check if we have to show the special link
        if (!empty($specialtext)) {
            $showspecial = true;
        }
        //Check if we are with the special link selected
        if ($showspecial && $page == $specialvalue) {
            $specialselected = true;
        }

        //If there are results (more than 1 page)
        if ($totalcount > $perpage) {
            $code .= "<div style=\"text-align:center\">";
            $code .= "<p>" . get_string("page") . ":";

            $maxpage = (int)(($totalcount - 1) / $perpage);

            //Lower and upper limit of page
            if ($page < 0) {
                $page = 0;
            }
            if ($page > $maxpageallowed) {
                $page = $maxpageallowed;
            }
            if ($page > $maxpage) {
                $page = $maxpage;
            }

            //Calculate the window of pages
            $pagefrom = $page - ((int)($maxdisplay / 2));
            if ($pagefrom < 0) {
                $pagefrom = 0;
            }
            $pageto = $pagefrom + $maxdisplay - 1;
            if ($pageto > $maxpageallowed) {
                $pageto = $maxpageallowed;
            }
            if ($pageto > $maxpage) {
                $pageto = $maxpage;
            }

            //Some movements can be necessary if don't see enought pages
            if ($pageto - $pagefrom < $maxdisplay - 1) {
                if ($pageto - $maxdisplay + 1 > 0) {
                    $pagefrom = $pageto - $maxdisplay + 1;
                }
            }

            //Calculate first and last if necessary
            $firstpagecode = '';
            $lastpagecode = '';
            if ($pagefrom > 0) {
                $firstpagecode = "$separator<a href=\"{$baseurl}page=0\">1</a>";
                if ($pagefrom > 1) {
                    $firstpagecode .= "$separator...";
                }
            }
            if ($pageto < $maxpage) {
                if ($pageto < $maxpage - 1) {
                    $lastpagecode = "$separator...";
                }
                $lastpagecode .= "$separator<a href=\"{$baseurl}page=$maxpage\">" . ($maxpage + 1) . "</a>";
            }

            //Previous
            if ($page > 0 && $previousandnext) {
                $pagenum = $page - 1;
                $code .= "&nbsp;(<a  href=\"{$baseurl}page=$pagenum\">" . get_string("previous") . "</a>)&nbsp;";
            }

            //Add first
            $code .= $firstpagecode;

            $pagenum = $pagefrom;

            //List of maxdisplay pages
            while ($pagenum <= $pageto) {
                $pagetoshow = $pagenum + 1;
                if ($pagenum == $page && !$specialselected) {
                    $code .= "$separator<b>$pagetoshow</b>";
                } else {
                    $code .= "$separator<a href=\"{$baseurl}page=$pagenum\">$pagetoshow</a>";
                }
                $pagenum++;
            }

            //Add last
            $code .= $lastpagecode;

            //Next
            if ($page < $maxpage && $page < $maxpageallowed && $previousandnext) {
                $pagenum = $page + 1;
                $code .= "$separator(<a href=\"{$baseurl}page=$pagenum\">" . get_string("next") . "</a>)";
            }

            //Add special
            if ($showspecial) {
                $code .= '<br />';
                if ($specialselected) {
                    $code .= "$separator<b>$specialtext</b>";
                } else {
                    $code .= "$separator<a href=\"{$baseurl}page=$specialvalue\">$specialtext</a>";
                }
            }

            //End html
            $code .= "</p>";
            $code .= "</div>";
        }

        return $code;
    }
}
