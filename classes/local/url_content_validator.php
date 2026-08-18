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
 * Validates that a generated URL still points to current, usable web content.
 *
 * The check combines three layers:
 * - HTTP response: the address resolves and returns a successful status after
 *   following redirects.
 * - Metadata: the content type is a web page and, when present, the
 *   Last-Modified date is recent enough relative to the topic match.
 * - Real content: the page has meaningful readable text and, when a topic is
 *   supplied, that text still relates to the topic.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_content_validator {

    /** @var int Seconds allowed for the whole request. */
    const REQUEST_TIMEOUT = 10;

    /** @var int Seconds allowed for the connection phase. */
    const CONNECT_TIMEOUT = 5;

    /** @var int Maximum redirects followed before giving up. */
    const MAX_REDIRECTS = 5;

    /** @var int Minimum number of readable characters for a page to be usable. */
    const MIN_CONTENT_CHARS = 200;

    /** @var float Minimum topic match fraction for the content to be related. */
    const MIN_TOPIC_MATCH = 0.3;

    /** @var float Topic match fraction above which old content is still trusted. */
    const STRONG_TOPIC_MATCH = 0.6;

    /** @var int Maximum age in days for content to be considered current. */
    const FRESHNESS_MAX_DAYS = 730;

    /** @var string User agent sent when fetching the page. */
    const USER_AGENT = 'MoodleCourseGenUrlValidator/1.0';

    /** @var string[] Tokens ignored when computing the topic match. */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'de', 'del', 'e', 'el', 'en', 'es',
        'for', 'from', 'in', 'is', 'it', 'la', 'las', 'le', 'los', 'of', 'on', 'or', 'para',
        'per', 'por', 'que', 'se', 'su', 'the', 'this', 'to', 'un', 'una', 'with', 'y',
    ];

    /**
     * Validate the content behind a URL.
     *
     * @param string $url The URL to validate.
     * @param string|null $expectedtopic Optional activity topic to check the content against.
     * @return url_validation_result
     */
    public function validate(string $url, ?string $expectedtopic = null): url_validation_result {
        $url = trim($url);
        if (!self::is_valid_http_url($url)) {
            return new url_validation_result(false, 'urlvalidation_invalid_url', null, ['url' => $url]);
        }

        $fetch = $this->fetch($url);
        $body = $fetch['body'];
        $info = $fetch['info'];
        $headers = $fetch['headers'];
        $errno = $fetch['errno'];

        $details = ['url' => $url];

        // HTTP response.
        $httpstatus = (int)($info['http_code'] ?? 0);
        $details['http_status'] = $httpstatus;
        $details['final_url'] = trim((string)($info['url'] ?? $url));
        $details['redirect_count'] = (int)($info['redirect_count'] ?? 0);
        if ($errno !== 0 || $httpstatus === 0 || $httpstatus >= 400) {
            $code = $httpstatus > 0 ? (string)$httpstatus : '0';
            return new url_validation_result(false, 'urlvalidation_http_error', [$code], $details);
        }

        // Metadata: content type.
        $contenttype = trim((string)($info['content_type'] ?? ''));
        $details['content_type'] = $contenttype;
        $mimetype = strtolower(trim(explode(';', $contenttype, 2)[0] ?? ''));
        if ($mimetype !== '' && !self::is_html_content_type($mimetype)) {
            return new url_validation_result(false, 'urlvalidation_bad_content_type', [$mimetype], $details);
        }

        // Metadata: last modified.
        $lastmodified = self::get_header($headers, 'Last-Modified');
        $details['last_modified'] = $lastmodified;

        // Real content.
        $title = self::extract_title($body);
        $text = self::extract_text($body);
        $textlength = \core_text::strlen(trim($text));
        $details['title'] = $title;
        $details['text_length'] = $textlength;
        if ($textlength < self::MIN_CONTENT_CHARS) {
            return new url_validation_result(false, 'urlvalidation_empty_content', null, $details);
        }

        // Content topic match.
        $topic = $expectedtopic !== null ? trim($expectedtopic) : '';
        $topicmatch = 0.0;
        if ($topic !== '') {
            $topicmatch = self::compute_topic_match($topic, $text);
        }
        $details['topic_match'] = $topic !== '' ? round($topicmatch, 4) : null;
        if ($topic !== '' && $topicmatch < self::MIN_TOPIC_MATCH) {
            return new url_validation_result(false, 'urlvalidation_topic_mismatch', null, $details);
        }

        // Freshness: not updated recently AND content no longer matches the topic.
        if ($lastmodified !== null) {
            $age = time() - strtotime($lastmodified);
            $details['age_days'] = (int)floor($age / 86400);
            if ($age > self::FRESHNESS_MAX_DAYS * 86400 && $topicmatch < self::STRONG_TOPIC_MATCH) {
                return new url_validation_result(false, 'urlvalidation_stale_content', null, $details);
            }
        }

        return new url_validation_result(true, '', null, $details);
    }

    /**
     * Check that the address is a valid http(s) URL with a host.
     *
     * @param string $url The URL to check.
     * @return bool
     */
    public static function is_valid_http_url(string $url): bool {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Fetch the page behind the URL using Moodle's curl class.
     *
     * @param string $url The URL to fetch.
     * @return array Body, curl info, response headers and curl error code.
     */
    private function fetch(string $url): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $options = [
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS' => self::MAX_REDIRECTS,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
            'CURLOPT_USERAGENT' => self::USER_AGENT,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        $body = $curl->get($url, [], $options);

        return [
            'body' => is_string($body) ? $body : '',
            'info' => $curl->get_info(),
            'headers' => (array)($curl->response ?? []),
            'errno' => $curl->get_errno(),
        ];
    }

    /**
     * Check that a MIME type corresponds to a readable web page.
     *
     * @param string $mimetype Lower-cased MIME type without parameters.
     * @return bool
     */
    private static function is_html_content_type(string $mimetype): bool {
        return in_array($mimetype, ['text/html', 'application/xhtml+xml'], true)
            || strpos($mimetype, 'text/') === 0;
    }

    /**
     * Read a response header by name, case-insensitively.
     *
     * @param array $headers Headers as returned by Moodle's curl class.
     * @param string $name Header name (e.g. 'Last-Modified').
     * @return string|null Header value, or null when absent.
     */
    private static function get_header(array $headers, string $name): ?string {
        $lowername = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === $lowername) {
                $value = is_array($value) ? end($value) : $value;
                $value = trim((string)$value);
                return $value === '' ? null : $value;
            }
        }
        return null;
    }

    /**
     * Extract the page title from the raw HTML.
     *
     * @param string $body Raw page body.
     * @return string|null The title, or null when absent or empty.
     */
    public static function extract_title(string $body): ?string {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches)) {
            return null;
        }
        $title = trim(strip_tags($matches[1]));
        return $title === '' ? null : $title;
    }

    /**
     * Extract the readable text of the page, stripping markup and scripts.
     *
     * @param string $body Raw page body.
     * @return string Normalised text, or '' when the page has no readable content.
     */
    public static function extract_text(string $body): string {
        $text = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $body);
        $text = preg_replace('/<[^>]+>/', ' ', (string)$text);
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', (string)$text);
        return trim((string)$text);
    }

    /**
     * Compute the fraction of significant topic tokens found in the body text.
     *
     * A value of 1.0 means every significant word of the topic appears in the
     * content; 0.0 means none do. When the topic has no significant tokens the
     * content is considered a match (1.0).
     *
     * @param string $topic The activity topic.
     * @param string $bodytext Readable text of the page.
     * @return float Fraction between 0.0 and 1.0.
     */
    public static function compute_topic_match(string $topic, string $bodytext): float {
        $topicwords = self::significant_tokens($topic);
        $total = count($topicwords);
        if ($total === 0) {
            return 1.0;
        }

        $body = ' ' . strtolower($bodytext) . ' ';
        $found = 0;
        foreach ($topicwords as $word) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($word, '/') . '(?![a-z0-9])/', $body)) {
                $found++;
            }
        }

        return $found / $total;
    }

    /**
     * Split a text into lower-cased significant tokens, dropping stopwords.
     *
     * @param string $text The text to tokenize.
     * @return string[] Significant tokens.
     */
    private static function significant_tokens(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }

        return array_values(array_filter($words, static function (string $word): bool {
            return !in_array($word, self::STOPWORDS, true);
        }));
    }
}