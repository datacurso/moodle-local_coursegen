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

namespace local_coursegen\local\preview\imscp;

use context;
use stdClass;

/**
 * mod_imscp's view code, ported to run against the payload.
 *
 * Copied from mod/imscp/view.php and locallib.php (Moodle 4.5). Method names
 * are the functions they came from. What changed: the package's structure is
 * read from the payload's row, and the context is handed in. The content the
 * table of contents opens is the package's own files, served from where the
 * template keeps them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass */
    protected stdClass $imscp;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var context */
    protected context $context;

    /**
     * Constructor.
     *
     * @param stdClass $imscp
     * @param stdClass $cm
     * @param context $context
     */
    public function __construct(stdClass $imscp, stdClass $cm, context $context) {
        $this->imscp = $imscp;
        $this->cm = $cm;
        $this->context = $context;
    }

    /**
     * mod/imscp/view.php: what the page asks for before its header.
     */
    public function require_page_assets(): void {
        global $PAGE;
        $PAGE->requires->js('/mod/imscp/dummyapi.js', true);

        $PAGE->requires->string_for_js('navigation', 'imscp');
        $PAGE->requires->string_for_js('toc', 'imscp');
        $PAGE->requires->string_for_js('hide', 'moodle');
        $PAGE->requires->string_for_js('show', 'moodle');
    }

    /**
     * mod/imscp/locallib.php imscp_print_content(), returning what it prints.
     *
     * @return string
     */
    public function imscp_print_content(): string {
        global $PAGE;

        $imscp = $this->imscp;
        $cm = $this->cm;
        $items = array_filter((array) unserialize_array($imscp->structure));

        $out = '';
        $out .= '<div id="imscp_layout">';
        $out .= '<div id="imscp_toc">';
        $out .= '<div id="imscp_tree"><ul>';
        foreach ($items as $item) {
            $out .= $this->imscp_htmllize_item($item, $imscp, $cm);
        }
        $out .= '</ul></div>';
        $out .= '<div id="imscp_nav" style="display:none">';
        $out .= '<button id="nav_skipprev">&lt;&lt;</button><button id="nav_prev">&lt;</button><button id="nav_up">^</button>';
        $out .= '<button id="nav_next">&gt;</button><button id="nav_skipnext">&gt;&gt;</button>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';

        $PAGE->requires->js_init_call('M.mod_imscp.init');
        return $out;
    }

    /**
     * mod/imscp/locallib.php imscp_htmllize_item().
     *
     * @param array $item
     * @param stdClass $imscp
     * @param stdClass $cm
     * @return string
     */
    protected function imscp_htmllize_item($item, $imscp, $cm) {
        global $CFG;

        if ($item['href']) {
            if (preg_match('|^https?://|', $item['href'])) {
                $url = $item['href'];
            } else {
                $context = $this->context;
                $urlbase = "$CFG->wwwroot/pluginfile.php";
                $path = '/'.$context->id.'/mod_imscp/content/'.$imscp->revision.'/'.$item['href'];
                $url = file_encode_url($urlbase, $path, false);
            }
            $result = "<li><a href=\"$url\">".$item['title'].'</a>';
        } else {
            $result = '<li>'.$item['title'];
        }
        if ($item['subitems']) {
            $result .= '<ul>';
            foreach ($item['subitems'] as $subitem) {
                $result .= $this->imscp_htmllize_item($subitem, $imscp, $cm);
            }
            $result .= '</ul>';
        }
        $result .= '</li>';

        return $result;
    }
}
