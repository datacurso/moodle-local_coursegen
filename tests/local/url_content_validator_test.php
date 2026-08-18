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

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for url_content_validator — URL parsing, content extraction and
 * topic matching. The HTTP fetch itself depends on the network and is covered
 * by E2E.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\local\url_content_validator
 */
final class url_content_validator_test extends \advanced_testcase {

    /**
     * Only http(s) URLs with a host are accepted.
     *
     * @dataProvider url_provider
     * @param string $input
     * @param bool $expected
     */
    public function test_is_valid_http_url(string $input, bool $expected): void {
        $this->assertSame($expected, url_content_validator::is_valid_http_url($input));
    }

    /**
     * @return array<string, array{0:string,1:bool}>
     */
    public static function url_provider(): array {
        return [
            'https'            => ['https://example.com/page', true],
            'http'             => ['http://example.com', true],
            'no scheme'        => ['example.com', false],
            'ftp'              => ['ftp://example.com/file', false],
            'no host'          => ['https://', false],
            'javascript'       => ['javascript:alert(1)', false],
            'empty'            => ['', false],
            'query string'     => ['https://example.com/p?a=1&b=2', true],
        ];
    }

    /**
     * A URL that is not http(s) is rejected without any network access.
     */
    public function test_validate_rejects_non_http_url(): void {
        $result = (new url_content_validator())->validate('ftp://example.com/file');
        $this->assertFalse($result->is_valid());
        $this->assertSame('urlvalidation_invalid_url', $result->get_reason());
    }

    /**
     * The page title is extracted from the raw HTML.
     *
     * @dataProvider title_provider
     * @param string $body
     * @param string|null $expected
     */
    public function test_extract_title(string $body, ?string $expected): void {
        $this->assertSame($expected, url_content_validator::extract_title($body));
    }

    /**
     * @return array<string, array{0:string,1:string|null}>
     */
    public static function title_provider(): array {
        return [
            'simple'       => ['<html><head><title>Introducción a la IA</title></head></html>', 'Introducción a la IA'],
            'with markup'  => ['<title>Hola <b>mundo</b></title>', 'Hola mundo'],
            'no title'     => ['<html><body>Content</body></html>', null],
            'empty title'  => ['<title>  </title>', null],
            'multiline'    => ["<title>\n  Curso 2026\n</title>", 'Curso 2026'],
        ];
    }

    /**
     * Scripts, styles and markup are removed and whitespace collapsed.
     */
    public function test_extract_text_strips_markup_and_scripts(): void {
        $body = '<html><head><style>.x{display:none}</style></head><body>'
            . '<h1>Título</h1><p>Primer   párrafo</p>'
            . '<script>var x = "no debería aparecer";</script></body></html>';
        $text = url_content_validator::extract_text($body);
        $this->assertStringContainsString('Título', $text);
        $this->assertStringContainsString('Primer párrafo', $text);
        $this->assertStringNotContainsString('no debería aparecer', $text);
        $this->assertStringNotContainsString('<p>', $text);
    }

    /**
     * Topic match reflects how many significant topic tokens appear in the content.
     *
     * @dataProvider topic_provider
     * @param string $topic
     * @param string $bodytext
     * @param float $expected
     */
    public function test_compute_topic_match(string $topic, string $bodytext, float $expected): void {
        $this->assertEqualsWithDelta($expected, url_content_validator::compute_topic_match($topic, $bodytext), 0.0001);
    }

    /**
     * @return array<string, array{0:string,1:string,2:float}>
     */
    public static function topic_provider(): array {
        return [
            'full match'       => ['Historia del arte moderno', 'El arte moderno y su historia', 1.0],
            'partial match'    => ['Historia del arte moderno', 'El arte moderno en el museo', 0.6667],
            'no match'         => ['Historia del arte moderno', 'Recetas de cocina italiana', 0.0],
            'stopwords only'   => ['De la y el', 'Cualquier contenido', 1.0],
            'case insensitive' => ['ARTE Moderno', 'el arte moderno', 1.0],
            'partial word'     => ['arte', 'carta de invitación', 0.0],
        ];
    }
}