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

namespace local_coursegen\local\files;

/**
 * Every place the files of a new activity come from, asked in turn.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_sources implements file_source {
    /** @var file_source[] */
    private array $sources;

    /**
     * Constructor.
     *
     * @param file_source[] $sources In the order they are asked.
     */
    public function __construct(array $sources) {
        $this->sources = $sources;
    }

    /**
     * The sources of a new activity: the template's, the AI service's files and its images.
     *
     * @param int|null $sourcecourseid The template's base course, or null.
     * @return self
     */
    public static function for_new_activity(?int $sourcecourseid): self {
        $addresses = new pluginfile_url_source($sourcecourseid);
        $generated = new generated_file_source();
        $images = new generated_image_source();
        return new self([$addresses, $generated, $images]);
    }

    #[\Override]
    public function find(file_reference $reference): ?\stored_file {
        foreach ($this->sources as $source) {
            $file = $source->find($reference);
            if ($file !== null) {
                return $file;
            }
        }
        return null;
    }
}
