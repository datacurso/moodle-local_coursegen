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

namespace local_coursegen\local\link;

/**
 * Shows an H5P activity inside the text that links to it.
 *
 * A link the AI writes with data-coursegen-embed="1" and the H5P activity of the course as its target is turned, once
 * the activity exists, into the player of that activity, so the student uses it without leaving the page. Anything
 * else is left as the link it is: a target that is not an H5P activity, or an activity with no package yet.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embed_link {
    /** @var string An anchor that asks to be shown embedded. */
    private const ANCHOR_PATTERN = '/<a\b[^>]*\bdata-coursegen-embed=(["\'])1\1[^>]*>.*?<\/a>/is';

    /**
     * Replace each embed link whose target is an H5P activity with its player.
     *
     * @param string $text
     * @return string
     */
    public static function apply(string $text): string {
        if (strpos($text, 'data-coursegen-embed') === false) {
            return $text;
        }
        return preg_replace_callback(self::ANCHOR_PATTERN, static function (array $match): string {
            $player = self::player(self::href($match[0]));
            return $player ?? $match[0];
        }, $text);
    }

    /**
     * The href of an anchor, unescaped.
     *
     * @param string $anchor
     * @return string
     */
    private static function href(string $anchor): string {
        if (!preg_match('/\bhref=(["\'])(.*?)\1/is', $anchor, $found)) {
            return '';
        }
        return html_entity_decode($found[2], ENT_QUOTES);
    }

    /**
     * The player of the H5P activity a URL points to.
     *
     * @param string $href
     * @return string|null Null when the URL is not an H5P activity with a package.
     */
    private static function player(string $href): ?string {
        global $CFG;

        if (!preg_match('~/mod/h5pactivity/view\.php\?(?:[^#]*&)?id=(\d+)~', $href, $found)) {
            return null;
        }
        $context = \context_module::instance((int) $found[1], IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        $files = get_file_storage()->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        $package = \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_h5pactivity',
            'package',
            0,
            $file->get_filepath(),
            $file->get_filename()
        );
        $embed = new \moodle_url('/h5p/embed.php', [
            'url' => $package->out(false),
            'component' => 'mod_h5pactivity',
        ]);
        return '<div class="coursegen-h5p-embed" style="margin:0 auto;max-width:1000px;">'
            . '<iframe src="' . s($embed->out(false)) . '" width="100%" height="637" allowfullscreen="allowfullscreen" '
            . 'style="border:0;width:100%;" title="H5P"></iframe>'
            . '<script src="' . s($CFG->wwwroot) . '/h5p/h5p-resizer.js" charset="UTF-8"></script></div>';
    }
}
