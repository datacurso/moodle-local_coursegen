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

namespace local_coursegen\local\service\mold_export;

use cm_info;
use context_module;
use stdClass;

/**
 * A mod_imscp mold: keepold plus the package's pages, walked from its stored structure.
 *
 * Each page's ``html`` is the inner HTML of its <body> when the file has
 * one, else the whole file: the service rebuilds the package from these
 * fragments and never carries the mold's own CSS or images.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imscp_mold_export extends base_mold_export {
    /** @var int Largest page file whose HTML travels (bytes); bigger pages are skipped. */
    public const MAX_INLINE_HTML_BYTES = 200 * 1024;

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        return array_merge(
            static::common($cm, $record),
            static::instance_columns($record, ['keepold']),
            ['mod_settings' => ['pages' => static::pages($cm, (string) $record->structure, (int) $record->revision)]]
        );
    }

    /**
     * The package's pages, depth-first in structure order.
     *
     * @param cm_info $cm
     * @param string $structure The serialized structure mod_imscp stores.
     * @param int $revision The package revision: mod_imscp files its content under that item id.
     * @return array
     */
    private static function pages(cm_info $cm, string $structure, int $revision): array {
        if ($structure === '') {
            return [];
        }
        $items = unserialize_array($structure);
        if (!is_array($items)) {
            debugging("local_coursegen: imscp mold {$cm->instance} has a corrupt structure; no pages exported.", DEBUG_DEVELOPER);
            return [];
        }
        $context = context_module::instance($cm->id);
        $pages = [];
        static::walk($items, $context, $revision, $pages);
        return $pages;
    }

    /**
     * Append every item (and its subitems) whose file exists and fits the inline limit.
     *
     * @param array $items
     * @param \context $context
     * @param int $revision
     * @param array $pages Accumulator.
     */
    private static function walk(array $items, \context $context, int $revision, array &$pages): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $href = static::clean_href((string) ($item['href'] ?? ''));
            if ($href !== '') {
                $html = static::page_html($context, $revision, $href);
                if ($html !== null) {
                    $pages[] = ['title' => (string) ($item['title'] ?? ''), 'html' => $html];
                }
            }
            if (!empty($item['subitems']) && is_array($item['subitems'])) {
                static::walk($item['subitems'], $context, $revision, $pages);
            }
        }
    }

    /**
     * A manifest href without its fragment/query, as a path inside the content area.
     *
     * @param string $href
     * @return string
     */
    private static function clean_href(string $href): string {
        $href = preg_replace('/[#?].*$/', '', $href);
        return ltrim(trim((string) $href), '/');
    }

    /**
     * The body-only HTML of one content file, or null when the file is missing or too big to travel inline.
     *
     * @param \context $context
     * @param int $revision
     * @param string $href
     * @return string|null
     */
    private static function page_html(\context $context, int $revision, string $href): ?string {
        $filepath = '/' . ltrim(dirname($href) === '.' ? '' : dirname($href) . '/', '/');
        $file = get_file_storage()->get_file($context->id, 'mod_imscp', 'content', $revision, $filepath, basename($href));
        if (!$file) {
            debugging("local_coursegen: imscp mold file '{$href}' not found; page skipped.", DEBUG_DEVELOPER);
            return null;
        }
        if ($file->get_filesize() > self::MAX_INLINE_HTML_BYTES) {
            debugging(
                "local_coursegen: imscp mold file '{$href}' exceeds " . self::MAX_INLINE_HTML_BYTES . ' bytes; page skipped.',
                DEBUG_DEVELOPER
            );
            return null;
        }
        $content = $file->get_content();
        if (preg_match('~<body[^>]*>(.*)</body>~is', $content, $matches)) {
            return trim($matches[1]);
        }
        return $content;
    }
}
