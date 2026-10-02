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

namespace local_coursegen\local;

/**
 * Languages the AI service can generate content in, and the normalisation of language codes.
 *
 * The course AI page, the activity AI footer hook and the activity generation
 * endpoint share this list. A code is normalised to its ISO 639-1 base (so
 * "pt_br", "pt-BR" and "PT" are all "pt"); a code that is not supported maps to
 * the default language, so an unsupported Moodle language never reaches the AI
 * service.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class language_options {
    /** @var string Language used when a code is empty or not supported (the first supported one). */
    public const DEFAULT_CODE = 'es';

    /** @var string[] Supported ISO 639-1 codes, in presentation order. */
    private const CODES = ['es', 'en', 'de', 'ru', 'pt', 'fr', 'id'];

    /**
     * Supported languages as code => label.
     *
     * The label is the language name of the string manager followed by the
     * upper-cased code, e.g. "English (EN)".
     *
     * @return array<string, string>
     */
    public static function supported(): array {
        $names = \get_string_manager()->get_list_of_languages(null, 'iso6391');

        $supported = [];
        foreach (self::CODES as $code) {
            $name = $names[$code] ?? \core_text::strtoupper($code);
            $supported[$code] = $name . ' (' . \core_text::strtoupper($code) . ')';
        }

        return $supported;
    }

    /**
     * Supported languages as a list of code/name pairs, for templates and JavaScript.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function options(): array {
        $options = [];
        foreach (self::supported() as $code => $name) {
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }

    /**
     * Normalise a language code to a supported ISO 639-1 code.
     *
     * The code is trimmed, lower-cased and reduced to its first segment ("pt_br"
     * and "pt-BR" give "pt"). An empty or unsupported code gives DEFAULT_CODE.
     *
     * @param string|null $code Language code, as Moodle or the client sends it.
     * @return string A supported code.
     */
    public static function normalize(?string $code): string {
        return self::canonical($code) ?? self::DEFAULT_CODE;
    }

    /**
     * The first candidate that normalises to a supported code, or DEFAULT_CODE.
     *
     * @param array<int, string|null> $candidates Codes in priority order.
     * @return string A supported code.
     */
    public static function resolve(array $candidates): string {
        foreach ($candidates as $candidate) {
            $code = self::canonical($candidate);
            if ($code !== null) {
                return $code;
            }
        }

        return self::DEFAULT_CODE;
    }

    /**
     * The supported code a raw code reduces to, or null when it is not supported.
     *
     * @param string|null $code Raw code.
     * @return string|null
     */
    private static function canonical(?string $code): ?string {
        $code = str_replace('-', '_', \core_text::strtolower(trim((string)$code)));
        $code = explode('_', $code)[0];

        return in_array($code, self::CODES, true) ? $code : null;
    }
}
