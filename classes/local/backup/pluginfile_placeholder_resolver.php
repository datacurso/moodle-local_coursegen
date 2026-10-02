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

namespace local_coursegen\local\backup;

/**
 * Names each "@@PLUGINFILE@@/file" of a text after the file area that holds the file.
 *
 * A module element declares every file area its texts may refer to, but not
 * which text refers to which: a page declares "intro" and "content" for the
 * element as a whole. A placeholder is therefore resolved one by one, to the
 * declared area that really stores a file of that name, so the address is the
 * same one the module would serve whichever of its texts carries the image.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pluginfile_placeholder_resolver {
    /** @var string A placeholder with the path of the file it names (captured). */
    private const PLACEHOLDER = '~@@PLUGINFILE@@((?:/[^/"\'<>\s?#)]+)+)~';

    /** @var array[] Declared areas: {component, filearea, contextid, itemid} in declaration order. */
    private array $areas = [];

    /**
     * Constructor.
     *
     * @param array $annotations component => filearea => info, as the element declares them.
     * @param int $fallbackcontextid Used for an area that names no context of its own.
     */
    public function __construct(array $annotations, int $fallbackcontextid) {
        foreach ($annotations as $component => $fileareas) {
            $component = (string) $component;
            $this->add_areas($component, $fileareas, $fallbackcontextid);
        }
    }

    /**
     * The text with every placeholder replaced by the address of its file.
     *
     * @param string $text
     * @return string
     */
    public function resolve(string $text): string {
        $callback = [$this, 'address_of'];
        return preg_replace_callback(self::PLACEHOLDER, $callback, $text);
    }

    /**
     * The address of the file one placeholder names.
     *
     * A file stored in none of the declared areas takes the first one, the
     * address it had before files were looked up.
     *
     * @param array $match The placeholder and the path after it.
     * @return string
     */
    public function address_of(array $match): string {
        $area = $this->area_holding($match[1]);
        if ($area === null) {
            $area = $this->areas[0];
        }
        return file_rewrite_pluginfile_urls(
            $match[0],
            'pluginfile.php',
            $area['contextid'],
            $area['component'],
            $area['filearea'],
            $area['itemid']
        );
    }

    /**
     * Whether any area was declared, which is what makes a placeholder resolvable.
     *
     * @return bool
     */
    public function has_areas(): bool {
        return $this->areas !== [];
    }

    /**
     * Record the areas of one component.
     *
     * @param string $component
     * @param array $fileareas filearea => info.
     * @param int $fallbackcontextid
     */
    private function add_areas(string $component, array $fileareas, int $fallbackcontextid): void {
        foreach ($fileareas as $filearea => $info) {
            $contextid = $this->context_of($info, $fallbackcontextid);
            $itemid = $this->itemid_of($info);
            $this->areas[] = [
                'component' => $component,
                'filearea' => (string) $filearea,
                'contextid' => $contextid,
                'itemid' => $itemid,
            ];
        }
    }

    /**
     * The first declared area that stores a file at this path.
     *
     * @param string $path The path after the placeholder, URL-encoded.
     * @return array|null
     */
    private function area_holding(string $path): ?array {
        $decoded = rawurldecode($path);
        $filename = basename($decoded);
        $filepath = dirname($decoded);
        if ($filepath !== '/') {
            $filepath .= '/';
        }
        $storage = get_file_storage();
        foreach ($this->areas as $area) {
            $itemid = $area['itemid'];
            if ($itemid === null) {
                $itemid = 0;
            }
            if ($storage->file_exists($area['contextid'], $area['component'], $area['filearea'], $itemid, $filepath, $filename)) {
                return $area;
            }
        }
        return null;
    }

    /**
     * The context an area is stored in.
     *
     * @param \stdClass $info
     * @param int $fallbackcontextid
     * @return int
     */
    private function context_of($info, int $fallbackcontextid): int {
        $contextid = $info->contextid ?? null;
        if ($contextid === null) {
            return $fallbackcontextid;
        }
        return (int) $contextid;
    }

    /**
     * The item id an annotation names, or null when it names none.
     *
     * @param \stdClass $info
     * @return int|null
     */
    private function itemid_of($info): ?int {
        if (!isset($info->element) || $info->element === null) {
            return null;
        }
        return (int) $info->element->get_value();
    }
}
