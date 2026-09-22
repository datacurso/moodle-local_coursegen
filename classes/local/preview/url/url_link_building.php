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

namespace local_coursegen\local\preview\url;

/**
 * mod/url/locallib.php url_get_full_url(), building the encoded link a URL
 * resolves to. Kept apart from view.php only because together they crossed
 * the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait url_link_building {
    /**
     * mod/url/locallib.php url_get_full_url().
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param stdClass|null $config
     * @return string
     */
    public static function url_get_full_url($url, $cm, $course, $config = null) {

        $parameters = empty($url->parameters) ? [] : (array) unserialize_array($url->parameters);

        // make sure there are no encoded entities, it is ok to do this twice
        $fullurl = html_entity_decode($url->externalurl, ENT_QUOTES, 'UTF-8');

        $letters = '\pL';
        $latin = 'a-zA-Z';
        $digits = '0-9';
        $symbols = '\x{20E3}\x{00AE}\x{00A9}\x{203C}\x{2047}\x{2048}\x{2049}\x{3030}\x{303D}\x{2139}\x{2122}\x{3297}\x{3299}' .
                   '\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BF0}';
        $arabic = '\x{FE00}-\x{FEFF}';
        $math = '\x{2190}-\x{21FF}\x{2900}-\x{297F}';
        $othernumbers = '\x{2460}-\x{24FF}';
        $geometric = '\x{25A0}-\x{25FF}';
        $emojis = '\x{1F000}-\x{1F6FF}';

        if (preg_match('/^(\/|https?:|ftp:)/i', $fullurl) or preg_match('|^/|', $fullurl)) {
            // encode extra chars in URLs - this does not make it always valid, but it helps with some UTF-8 problems
            // Thanks to 💩.la emojis count as valid, too.
            $allowed = "[" . $letters . $latin . $digits . $symbols . $arabic . $math . $othernumbers . $geometric .
                $emojis . "]" . preg_quote(';/?:@=&$_.+!*(),-#%', '/');
            $fullurl = preg_replace_callback("/[^$allowed]/u", [self::class, 'url_filter_callback'], $fullurl);
        } else {
            // encode special chars only
            $fullurl = str_replace('"', '%22', $fullurl);
            $fullurl = str_replace('\'', '%27', $fullurl);
            $fullurl = str_replace(' ', '%20', $fullurl);
            $fullurl = str_replace('<', '%3C', $fullurl);
            $fullurl = str_replace('>', '%3E', $fullurl);
        }

        if (!$config) {
            $config = get_config('url');
        }

        // add variable url parameters
        if ($config->allowvariables && !empty($parameters)) {
            $paramvalues = self::url_get_variable_values($url, $cm, $course, $config);

            foreach ($parameters as $parse => $parameter) {
                if (isset($paramvalues[$parameter])) {
                    $parameters[$parse] = rawurlencode($parse) . '=' . rawurlencode($paramvalues[$parameter]);
                } else {
                    unset($parameters[$parse]);
                }
            }

            if (!empty($parameters)) {
                if (stripos($fullurl, 'teamspeak://') === 0) {
                    $fullurl = $fullurl . '?' . implode('?', $parameters);
                } else {
                    $join = (strpos($fullurl, '?') === false) ? '?' : '&';
                    $fullurl = $fullurl . $join . implode('&', $parameters);
                }
            }
        }

        // encode all & to &amp; entity
        $fullurl = str_replace('&', '&amp;', $fullurl);

        return $fullurl;
    }

    /**
     * mod/url/locallib.php url_filter_callback().
     *
     * @param array $matches
     * @return string
     */
    public static function url_filter_callback($matches) {
        return rawurlencode($matches[0]);
    }
}
