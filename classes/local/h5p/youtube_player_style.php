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

namespace local_coursegen\local\h5p;

/**
 * Keeps the YouTube player of every H5P video in sight, on every site, without anyone pasting CSS by hand.
 *
 * H5P.Video before 1.6.79 places the YouTube player through an internal property of the YouTube API that no longer
 * exists: the player falls below the visible area and the sound plays over a black box. It hits every H5P with a
 * YouTube video (an interactive video, a video in a presentation, a column or a book). The fixed H5P.Video needs the
 * H5P framework 1.28, which a Moodle 4.5 with the 1.27 library does not have, so the player is put back in place with
 * one CSS rule in the site's H5P custom CSS (core_h5p/h5pcustomcss), between its own markers: it is added once, the
 * site's own CSS is kept, and the CSS file H5P serves is written again.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class youtube_player_style {
    /** @var string Marker that opens the rule. */
    public const BEGIN = '/* BEGIN youtube player position (local_coursegen) */';

    /** @var string Marker that closes the rule. */
    public const END = '/* END youtube player position (local_coursegen) */';

    /** @var string The rule: the YouTube iframe of an H5P video fills its box. */
    public const RULE = '.h5p-video.h5p-youtube iframe[id^="h5p-youtube-"] '
        . '{ position: absolute; top: 0; left: 0; width: 100%; height: 100%; }';

    /**
     * Add the rule to the site's H5P custom CSS when it is not there yet, and write the CSS file H5P serves.
     *
     * @return bool Whether the rule was added now.
     */
    public static function apply(): bool {
        $css = (string) get_config('core_h5p', 'h5pcustomcss');
        if (str_contains($css, self::BEGIN)) {
            return false;
        }
        $block = self::BEGIN . "\n" . self::RULE . "\n" . self::END;
        $css = trim($css) === '' ? $block : rtrim($css) . "\n\n" . $block;
        set_config('h5pcustomcss', $css, 'core_h5p');
        \core_h5p\local\library\autoloader::register();
        \core_h5p\file_storage::generate_custom_styles();
        return true;
    }
}
