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
 * Tests for the list of languages the AI service supports and its code normalisation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\language_options
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\language_options::class)]
final class language_options_test extends \advanced_testcase {
    /**
     * The supported codes are the ones the AI service accepts, in presentation order.
     */
    public function test_supported_codes_are_stable(): void {
        $this->assertSame(['es', 'en', 'de', 'ru', 'pt', 'fr', 'id'], array_keys(language_options::supported()));
        $this->assertSame(language_options::DEFAULT_CODE, array_key_first(language_options::supported()));
    }

    /**
     * Labels are the language names of the string manager followed by the upper-cased code.
     */
    public function test_labels_name_the_language_and_its_code(): void {
        $languages = get_string_manager()->get_list_of_languages(null, 'iso6391');

        $supported = language_options::supported();
        $this->assertSame($languages['en'] . ' (EN)', $supported['en']);
        $this->assertSame($languages['pt'] . ' (PT)', $supported['pt']);

        $options = language_options::options();
        $this->assertCount(count($supported), $options);
        $this->assertSame(['code' => 'es', 'name' => $supported['es']], $options[0]);
        $this->assertSame(array_keys($supported), array_column($options, 'code'));
    }

    /**
     * Normalisation cases: a supported code, in any case or with a region, maps to itself; anything
     * else maps to the default.
     *
     * @return array<string, array{?string, string}>
     */
    public static function normalize_provider(): array {
        return [
            'null' => [null, 'es'],
            'empty' => ['', 'es'],
            'blank' => ['   ', 'es'],
            'upper case' => ['ES', 'es'],
            'region with underscore' => ['pt_br', 'pt'],
            'region with dash' => ['pt-BR', 'pt'],
            'region and workplace suffix' => ['en_us_wp', 'en'],
            'surrounding spaces' => [' fr ', 'fr'],
            'unknown language' => ['ja', 'es'],
            'unknown with region' => ['zh_cn', 'es'],
        ];
    }

    /**
     * normalize() returns a supported code or the default.
     *
     * @dataProvider normalize_provider
     * @param string|null $code Input code.
     * @param string $expected Normalised code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('normalize_provider')]
    public function test_normalize(?string $code, string $expected): void {
        $this->assertSame($expected, language_options::normalize($code));
    }

    /**
     * resolve() takes the first candidate that is supported, and the default when none is.
     */
    public function test_resolve_takes_first_supported_candidate(): void {
        $this->assertSame('de', language_options::resolve(['ja', 'de_at', 'en']));
        $this->assertSame('en', language_options::resolve([null, '', 'EN-GB', 'es']));
        $this->assertSame('es', language_options::resolve([null, '', 'xx']));
        $this->assertSame('es', language_options::resolve([]));
    }
}
