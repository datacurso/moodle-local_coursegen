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

namespace local_coursegen\local\preview;

use local_coursegen\utils\generated_file_cache;

/**
 * Makes the files the AI service made for an activity visible in its review preview.
 *
 * The finished activity names them as "@@PLUGINFILE@@/name", which only means something once the activity exists.
 * The preview stores each generated file (downloaded once, see generated_file_cache) and writes the address it is
 * served from in place of the placeholder. The stored result is left as it is: creating the course needs the
 * placeholders.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generated_file_preview {
    /** @var generated_file_cache Where the files are read through. */
    private generated_file_cache $cache;

    /**
     * Constructor.
     *
     * @param generated_file_cache|null $cache Replaces the default cache; tests pass their own.
     */
    public function __construct(?generated_file_cache $cache = null) {
        $this->cache = $cache ?? new generated_file_cache();
    }

    /**
     * The parameters of an activity with its generated files addressed.
     *
     * @param array $parameters The activity's parameters.
     * @param array[] $entries The activity's generated_files.
     * @return array The same parameters, every "@@PLUGINFILE@@/name" of a generated file written as its address.
     */
    public function addressed(array $parameters, array $entries): array {
        $addresses = [];
        foreach ($entries as $entry) {
            $this->cache->get($entry);
            $addresses[(string) $entry['filename']] = $this->address_of($entry);
        }
        if (!$addresses) {
            return $parameters;
        }
        return $this->walk($parameters, $addresses);
    }

    /**
     * The address a stored generated file is served from.
     *
     * @param array $entry
     * @return string
     */
    private function address_of(array $entry): string {
        $record = generated_file_cache::file_record($entry);
        $url = \moodle_url::make_pluginfile_url(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        return $url->out(false);
    }

    /**
     * A value with the placeholders of every text inside it replaced.
     *
     * @param mixed $value
     * @param string[] $addresses File name => address.
     * @return mixed
     */
    private function walk($value, array $addresses) {
        if (is_string($value)) {
            return $this->replace($value, $addresses);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->walk($item, $addresses);
        }
        return $value;
    }

    /**
     * A text with the placeholders of the generated files replaced by their addresses.
     *
     * @param string $text
     * @param string[] $addresses File name => address.
     * @return string
     */
    private function replace(string $text, array $addresses): string {
        if (!str_contains($text, '@@PLUGINFILE@@/')) {
            return $text;
        }
        $callback = static function (array $found) use ($addresses): string {
            $name = rawurldecode($found[1]);
            return $addresses[$name] ?? $found[0];
        };
        return preg_replace_callback('~@@PLUGINFILE@@/([^"\'<>\s?#)]+)~u', $callback, $text) ?? $text;
    }
}
