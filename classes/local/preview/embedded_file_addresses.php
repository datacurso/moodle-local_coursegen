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

use local_coursegen\utils\preview_draft_store;

/**
 * Where a page of the preview shows the file a run gave to a resource.
 *
 * The AI points an embedded file of a page to the resource that now holds the file of the run, by a token with the
 * uid of the resource. The preview has no resource yet, so the embedded file is shown from the draft area of the
 * reviewer, where the file of that resource is kept under its uid.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embedded_file_addresses {
    /** @var string[] The columns of a page row whose text may point to a resource. */
    private const COLUMNS = ['content', 'intro'];

    /**
     * The address of the file of every resource the text of a page points to.
     *
     * Only a resource the page names is downloaded: the other files of the run are not needed to draw this page.
     *
     * @param array[] $activities Every activity of the result.
     * @param array $parameters The parameters of the page, with the text the AI wrote.
     * @param preview_draft_store $store Draft store of the generation session.
     * @return array<string,string> Uid of the resource => address its file is served from.
     */
    public static function for_page(array $activities, array $parameters, preview_draft_store $store): array {
        $text = self::text_of($parameters);
        $addresses = [];
        foreach ($activities as $activity) {
            $uid = self::uid_of_resource_with_file($activity);
            if ($uid === '' || !str_contains($text, $uid)) {
                continue;
            }
            $addresses[$uid] = self::address_of($activity, $uid, $store);
        }
        return $addresses;
    }

    /**
     * The texts of a page that may hold a token, joined.
     *
     * @param array $parameters
     * @return string
     */
    private static function text_of(array $parameters): string {
        $texts = [];
        foreach (self::COLUMNS as $column) {
            $value = $parameters[$column] ?? '';
            if (is_string($value)) {
                $texts[] = $value;
            }
        }
        return implode("\n", $texts);
    }

    /**
     * The uid of an activity that is a resource holding a file the run made, or an empty text when it is not.
     *
     * @param array $activity One activity of the result.
     * @return string
     */
    private static function uid_of_resource_with_file(array $activity): string {
        if (($activity['resource_type'] ?? '') !== 'resource') {
            return '';
        }
        $files = $activity['generated_files'] ?? [];
        if (!is_array($files) || !$files) {
            return '';
        }
        return (string) ($activity['uid'] ?? '');
    }

    /**
     * Store the first file of a resource in the draft area and give the address it is served from.
     *
     * @param array $activity One activity of the result.
     * @param string $uid Opaque uid of the resource.
     * @param preview_draft_store $store
     * @return string
     */
    private static function address_of(array $activity, string $uid, preview_draft_store $store): string {
        $entry = reset($activity['generated_files']);
        $stored = $store->get($uid, (array) $entry);
        $filename = $stored->get_filename();
        return $store->address($uid, $filename);
    }
}
