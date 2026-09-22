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
 * mod_imscp's view code, run here against the payload instead of the database.
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
        global $PAGE, $OUTPUT;

        $imscp = $this->imscp;
        $items = array_filter((array) unserialize_array($imscp->structure));

        $nodes = [];
        foreach ($items as $item) {
            $nodes[] = $this->imscp_item_node($item, $imscp);
        }
        $out = $OUTPUT->render_from_template('local_coursegen/preview_imscp_tree', ['items' => $nodes]);

        $PAGE->requires->js_init_call('M.mod_imscp.init');
        return $out;
    }

    /**
     * mod/imscp/locallib.php imscp_htmllize_item(), as data for preview_imscp_item.mustache.
     *
     * @param array $item
     * @param stdClass $imscp
     * @return array
     */
    protected function imscp_item_node($item, $imscp): array {
        $node = [
            'haslink' => (bool) $item['href'],
            'url' => '',
            'title' => $item['title'],
            'hassubitems' => false,
            'subitems' => [],
        ];

        if ($node['haslink']) {
            $node['url'] = $this->imscp_item_url($item['href'], $imscp);
        }

        if ($item['subitems']) {
            $node['hassubitems'] = true;
            foreach ($item['subitems'] as $subitem) {
                $node['subitems'][] = $this->imscp_item_node($subitem, $imscp);
            }
        }

        return $node;
    }

    /**
     * mod/imscp/locallib.php imscp_htmllize_item(), the url of one item's own file.
     *
     * @param string $href
     * @param stdClass $imscp
     * @return string
     */
    protected function imscp_item_url(string $href, $imscp): string {
        global $CFG;

        if (preg_match('|^https?://|', $href)) {
            return $href;
        }
        $context = $this->context;
        $urlbase = "$CFG->wwwroot/pluginfile.php";
        $path = '/'.$context->id.'/mod_imscp/content/'.$imscp->revision.'/'.$href;
        return file_encode_url($urlbase, $path, false);
    }
}
