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

use stdClass;

/**
 * A page, drawn by mod_page's own view code run against the payload.
 *
 * The body of render() is mod/page/view.php (Moodle 4.5) from where it formats
 * the content to where it prints the footer, with the page row read from the
 * payload and the context handed in. Nothing decides here what a page looks
 * like.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'page';
    }

    /**
     * A draft replaces the page's body.
     *
     * A page is one piece, so a plan for it has one part, and the answer for
     * it carries the body under the field its form submits.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('page');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $body = $this->parameters['page'] ?? null;
        if ($body === null) {
            $body = $this->parameters['content'] ?? null;
        }
        if (is_array($body)) {
            $body = $body['text'] ?? null;
        }
        if (is_string($body) && trim($body) !== '') {
            $store->set('page', $row->id, 'content', $body);
        }
    }

    /**
     * The page's saved display options, unserialized.
     *
     * @param stdClass $page
     * @return array
     */
    private function display_options(stdClass $page): array {
        if (empty($page->displayoptions)) {
            return [];
        }
        $options = unserialize_array($page->displayoptions);
        return (array) $options;
    }

    /**
     * The page, as mod/page/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $page = $this->instance();
        if ($page === null) {
            return $this->nothing_yet();
        }
        $context = $this->context();
        $options = $this->display_options($page);

        // From here, mod/page/view.php.
        $content = file_rewrite_pluginfile_urls($page->content, 'pluginfile.php', $context->id, 'mod_page', 'content', $page->revision);
        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        $formatoptions->overflowdiv = true;
        $formatoptions->context = $context;
        $content = format_text($content, $page->contentformat, $formatoptions);
        $out = $OUTPUT->box($content, "generalbox center clearfix");

        if (!isset($options['printlastmodified']) || !empty($options['printlastmodified'])) {
            $strlastmodified = get_string("lastmodified");
            $lastmodified = "$strlastmodified: " . userdate($page->timemodified);
            $templatecontext = [
                'classes' => 'modified',
                'content' => $lastmodified,
            ];
            $out .= $OUTPUT->render_from_template('local_coursegen/preview_container', $templatecontext);
        }
        return $out;
    }

    /**
     * The description, only when the page is set to print it.
     *
     * mod/page/view.php empties the header's description unless printintro is
     * on; the header otherwise shows the activity record's intro.
     *
     * @return string
     */
    public function header_description(): string {
        $page = $this->instance();
        if ($page === null) {
            return '';
        }
        $options = $this->display_options($page);
        if (empty($options['printintro'])) {
            return '';
        }
        return $this->module_intro($page);
    }

    /**
     * A page's own display options, decoded from its stored row.
     *
     * @param stdClass $page
     * @return array
     */
    protected function display_options(stdClass $page): array {
        if (empty($page->displayoptions)) {
            return [];
        }
        return (array) unserialize_array($page->displayoptions);
    }

    /**
     * This module reads its own page at the narrower width (mod/page/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}
