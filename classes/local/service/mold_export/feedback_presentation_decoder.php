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

/**
 * Decodes a feedback_item.presentation string into the fields mod_feedback's item forms use.
 *
 * This is the inverse of \local_coursegen\mod_settings\feedback\presentation_builder:
 * whatever this produces, that builder turns back into the same presentation
 * string when the generated activity is created.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_presentation_decoder {
    /** @var string Separator between the subtype and the option list. */
    private const TYPE_SEP = '>>>>>';

    /** @var string Separator between options. */
    private const LINE_SEP = '|';

    /** @var string Separator before the "horizontal" flag. */
    private const ADJUST_SEP = '<<<<<';

    /** @var string Separator between a rated option's value and its text, as stored. */
    private const RATED_STORED_SEP = '####';

    /** @var string Separator between a rated option's value and its text, as the item form posts it. */
    private const RATED_FORM_SEP = '/';

    /** @var string Option flag: ignore empty answers. */
    private const FLAG_IGNOREEMPTY = 'i';

    /** @var string Option flag: hide the "not selected" choice. */
    private const FLAG_HIDENOSELECT = 'h';

    /**
     * The per-type fields encoded in one item's presentation/options.
     *
     * @param string $typ Item type.
     * @param string $presentation feedback_item.presentation.
     * @param string $options feedback_item.options.
     * @return array Empty for types that need no decoding.
     */
    public static function decode(string $typ, string $presentation, string $options): array {
        switch ($typ) {
            case 'multichoice':
            case 'multichoicerated':
                return self::decode_multichoice($presentation, $options, $typ === 'multichoicerated');
            case 'numeric':
                return self::decode_pair($presentation, 'rangefrom', 'rangeto', true);
            case 'textarea':
                return self::decode_pair($presentation, 'itemwidth', 'itemheight', false);
            case 'textfield':
                return self::decode_pair($presentation, 'itemsize', 'itemmaxlength', false);
            default:
                return [];
        }
    }

    /**
     * subtype, horizontal, the option lines and the two flags of a (rated) multichoice item.
     *
     * @param string $presentation
     * @param string $options
     * @param bool $rated Whether option lines carry a "value####text" weight.
     * @return array
     */
    private static function decode_multichoice(string $presentation, string $options, bool $rated): array {
        $subtype = 'r';
        $rest = $presentation;
        if (strpos($presentation, self::TYPE_SEP) !== false) {
            [$subtype, $rest] = explode(self::TYPE_SEP, $presentation, 2);
        }
        $horizontal = 0;
        if (strpos($rest, self::ADJUST_SEP) !== false) {
            [$rest, $adjust] = explode(self::ADJUST_SEP, $rest, 2);
            $horizontal = (int) ((int) $adjust === 1);
        }
        $lines = [];
        foreach (explode(self::LINE_SEP, $rest) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($rated && strpos($line, self::RATED_STORED_SEP) !== false) {
                [$value, $text] = explode(self::RATED_STORED_SEP, $line, 2);
                $line = trim($value) . self::RATED_FORM_SEP . trim($text);
            }
            $lines[] = $line;
        }
        return [
            'subtype' => substr($subtype, 0, 1),
            'horizontal' => $horizontal,
            'values' => implode("\n", $lines),
            'hidenoselect' => (int) (strpos($options, self::FLAG_HIDENOSELECT) !== false),
            'ignoreempty' => (int) (strpos($options, self::FLAG_IGNOREEMPTY) !== false),
        ];
    }

    /**
     * A "left|right" presentation as two named fields; a "-" side is left out.
     *
     * @param string $presentation
     * @param string $leftkey
     * @param string $rightkey
     * @param bool $float Whether the values are floats (ranges) or ints (sizes).
     * @return array
     */
    private static function decode_pair(string $presentation, string $leftkey, string $rightkey, bool $float): array {
        $parts = explode('|', $presentation, 2);
        $decoded = [];
        foreach ([$leftkey => $parts[0] ?? '', $rightkey => $parts[1] ?? ''] as $key => $raw) {
            $raw = trim($raw);
            if ($raw === '' || $raw === '-' || !is_numeric($raw)) {
                continue;
            }
            $decoded[$key] = $float ? (float) $raw : (int) $raw;
        }
        return $decoded;
    }
}
