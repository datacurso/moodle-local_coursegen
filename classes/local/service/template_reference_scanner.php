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

namespace local_coursegen\local\service;

/**
 * Finds the reference markers of a template source activity and the file each one points at.
 *
 * A reference marker, "[[coursegen:reference: instruction]]", is plain text typed right above an element of any text
 * of the activity. Its target is the NEXT element in document order, and that element must carry exactly one file of
 * the template activity. Each marker is a slot, named "<activity uid>.<ordinal>": the ordinal is the marker's place
 * when the whole "parameters" of the activity's export are walked in key order and every text in document order.
 * The AI service walks the same value the same way, so both sides agree on what slot n is.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_reference_scanner {
    /** @var string One reference marker, in either dialect; the instruction is the "text" group. */
    private const MARKER = '/(?:\[\[|⟦)\s*coursegen:reference\s*(?::(?P<text>.*?))?\s*(?:\]\]|⟧)/su';

    /** @var string The word every marker holds, to skip the texts that cannot have one. */
    private const WORD = 'coursegen:reference';

    /** @var string[] Attributes that may carry a file. */
    private const FILE_ATTRIBUTES = ['src', 'href', 'data', 'poster'];

    /**
     * The reference slots of a template source activity, in the order the AI service counts them.
     *
     * @param array $parameters The "parameters" of the activity's export.
     * @param string $uid The activity's uid in the payload.
     * @return array[] Each: key, ordinal, instruction, filename, mimetype.
     * @throws \moodle_exception A marker has no usable target element.
     */
    public static function slots(array $parameters, string $uid): array {
        $markers = [];
        self::collect($parameters, $markers);

        $mimetypes = self::mimetypes_by_filename($parameters['files'] ?? []);
        $slots = [];
        foreach ($markers as $index => $marker) {
            $ordinal = $index + 1;
            $slots[] = [
                'key' => $uid . '.' . $ordinal,
                'ordinal' => $ordinal,
                'instruction' => $marker['instruction'],
                'filename' => $marker['filename'],
                'mimetype' => $mimetypes[$marker['filename']] ?? '',
            ];
        }
        return $slots;
    }

    /**
     * The name of the file an attribute value points at, or null when it is not a file of the template activity.
     *
     * @param string $value
     * @return string|null
     */
    public static function file_name_of(string $value): ?string {
        $prefix = '@@PLUGINFILE@@/';
        if (strpos($value, $prefix) === 0) {
            $path = substr($value, strlen($prefix));
        } else if (strpos($value, '/pluginfile.php/') !== false) {
            $path = $value;
        } else {
            return null;
        }
        $path = (string) parse_url($path, PHP_URL_PATH);
        $name = rawurldecode(basename($path));
        if ($name === '' || $name === '.' || $name === '/') {
            return null;
        }
        return $name;
    }

    /**
     * Add the markers of every text inside a value, walking arrays in key order.
     *
     * @param mixed $value
     * @param array $markers Appended to.
     */
    private static function collect($value, array &$markers): void {
        if (is_string($value)) {
            if (strpos($value, self::WORD) !== false) {
                array_push($markers, ...self::markers_of($value));
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::collect($item, $markers);
        }
    }

    /**
     * The markers of one text with the file each one points at, in document order.
     *
     * @param string $html
     * @return array[] Each: instruction, filename.
     */
    private static function markers_of(string $html): array {
        $nodes = self::document_order($html);
        $found = [];
        foreach ($nodes as $position => $node) {
            if ($node->nodeType !== XML_TEXT_NODE) {
                continue;
            }
            $count = preg_match_all(self::MARKER, $node->nodeValue, $matches);
            for ($number = 0; $number < $count; $number++) {
                $instruction = trim((string) $matches['text'][$number]);
                $last = $number === $count - 1;
                $found[] = self::marker($nodes, $position, $instruction, $last);
            }
        }
        return $found;
    }

    /**
     * One marker with the file of its target element.
     *
     * @param \DOMNode[] $nodes The text's nodes in document order.
     * @param int $position Where the marker's text node is.
     * @param string $instruction
     * @param bool $last Whether it is the last marker of its text node.
     * @return array instruction, filename.
     * @throws \moodle_exception
     */
    private static function marker(array $nodes, int $position, string $instruction, bool $last): array {
        $target = $last ? self::next_element($nodes, $position) : null;
        if ($target === null) {
            throw new \moodle_exception('template_reference_no_element', 'local_coursegen', '', $instruction);
        }
        $files = self::files_inside($target);
        if (!$files) {
            throw new \moodle_exception('template_reference_no_file', 'local_coursegen', '', $instruction);
        }
        if (count($files) > 1) {
            throw new \moodle_exception('template_reference_many_files', 'local_coursegen', '', $instruction);
        }
        if (self::holds_marker($target)) {
            throw new \moodle_exception('template_reference_nested', 'local_coursegen', '', $instruction);
        }
        return ['instruction' => $instruction, 'filename' => $files[0]];
    }

    /**
     * The first element after a position that no other marker comes before.
     *
     * @param \DOMNode[] $nodes
     * @param int $position
     * @return \DOMElement|null
     */
    private static function next_element(array $nodes, int $position): ?\DOMElement {
        $total = count($nodes);
        for ($next = $position + 1; $next < $total; $next++) {
            $node = $nodes[$next];
            if ($node->nodeType === XML_ELEMENT_NODE) {
                return $node;
            }
            if ($node->nodeType === XML_TEXT_NODE && preg_match(self::MARKER, $node->nodeValue)) {
                return null;
            }
        }
        return null;
    }

    /**
     * The names of the files an element and everything inside it carry.
     *
     * @param \DOMElement $element
     * @return string[]
     */
    private static function files_inside(\DOMElement $element): array {
        $names = [];
        $elements = [$element];
        foreach ($element->getElementsByTagName('*') as $inner) {
            $elements[] = $inner;
        }
        foreach ($elements as $candidate) {
            array_push($names, ...self::file_names_of($candidate));
        }
        return $names;
    }

    /**
     * The names of the files one element carries in its own attributes.
     *
     * @param \DOMElement $element
     * @return string[]
     */
    private static function file_names_of(\DOMElement $element): array {
        $names = [];
        foreach (self::FILE_ATTRIBUTES as $attribute) {
            if (!$element->hasAttribute($attribute)) {
                continue;
            }
            $name = self::file_name_of($element->getAttribute($attribute));
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Whether a marker is written anywhere inside an element.
     *
     * @param \DOMElement $element
     * @return bool
     */
    private static function holds_marker(\DOMElement $element): bool {
        return preg_match(self::MARKER, $element->textContent) === 1;
    }

    /**
     * The elements and texts of an HTML text in document order.
     *
     * @param string $html
     * @return \DOMNode[]
     */
    private static function document_order(string $html): array {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;
        $nodes = [];
        self::walk($root, $nodes);
        return array_slice($nodes, 1);
    }

    /**
     * Add a node and everything under it in document order.
     *
     * @param \DOMNode $node
     * @param \DOMNode[] $nodes Appended to.
     */
    private static function walk(\DOMNode $node, array &$nodes): void {
        $nodes[] = $node;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE || $child->nodeType === XML_TEXT_NODE) {
                self::walk($child, $nodes);
            }
        }
    }

    /**
     * The type of each file of the activity's export, by file name.
     *
     * @param array $files The export's "files" entries.
     * @return string[]
     */
    private static function mimetypes_by_filename(array $files): array {
        $types = [];
        foreach ($files as $entry) {
            if (!empty($entry['isdir'])) {
                continue;
            }
            $types[(string) ($entry['filename'] ?? '')] = (string) ($entry['mimetype'] ?? '');
        }
        return $types;
    }
}
