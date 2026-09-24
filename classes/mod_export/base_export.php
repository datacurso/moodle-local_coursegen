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

namespace local_coursegen\mod_export;

use cm_info;
use stored_file;

/**
 * Class base_export
 *
 * One activity type's export, resolved by name from its module. The pieces
 * gathered here are the ones every type - or several of them - reproduce
 * identically; everything a type owns alone stays in that type's own class.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_export {
    /** @var cm_info The mold activity being exported. */
    protected cm_info $cm;

    /**
     * Constructor.
     *
     * @param cm_info $cm The mold activity.
     */
    public function __construct(cm_info $cm) {
        $this->cm = $cm;
    }

    /**
     * Build this activity's parameters.
     *
     * @return array
     */
    abstract public function parameters(): array;

    /**
     * The minimal pair every activity ships, whatever its type.
     *
     * It is also what a type whose instance row has gone missing falls back to:
     * there is nothing else left to describe, and losing the whole template
     * export over one broken activity would be worse.
     *
     * @return array
     */
    protected function minimal_parameters(): array {
        return ['name' => $this->cm->name, 'section' => (int) $this->cm->sectionnum];
    }

    /**
     * The whitelisted columns of one instance row, the ones it really has.
     *
     * Every type names its own list: identity and derived columns are left out
     * of it on purpose, because they describe THIS activity, never the new one.
     *
     * @param \stdClass $record The module's instance row.
     * @param string[] $fields The columns worth reproducing.
     * @return array
     */
    protected function whitelisted_settings($record, array $fields): array {
        $settings = [];
        foreach ($fields as $field) {
            if (isset($record->$field)) {
                $settings[$field] = $record->$field;
            }
        }
        return $settings;
    }

    /**
     * The mold's grade to pass, or 0.0 when it has none.
     *
     * gradepass is not a column of quiz, h5pactivity or scorm. It lives in
     * grade_items, which is where mod/quiz/view.php reads the pass mark from,
     * so it travels through the grades API; read off the instance row it would
     * always have been lost.
     *
     * @return float
     */
    protected function grade_pass(): float {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $this->cm->modname,
            'iteminstance' => (int) $this->cm->instance,
            'itemnumber' => 0,
            'courseid' => (int) $this->cm->course,
        ]);

        return $item ? (float) $item->gradepass : 0.0;
    }

    /**
     * One stored text plus its format, in the editor shape.
     *
     * @param \stdClass|null $record The row holding it, or null.
     * @param string $field The text column; its format is $field . 'format'.
     * @return array ['text' => raw, 'format' => int]
     */
    protected function editor_field($record, string $field): array {
        return [
            'text' => (string) ($record->$field ?? ''),
            'format' => (int) ($record->{$field . 'format'} ?? FORMAT_HTML),
        ];
    }

    /**
     * The package file this activity carries, or null when it has none.
     *
     * @param string $component The file component, as the module declares it.
     * @return stored_file|null
     */
    protected function mold_package_file(string $component): ?stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->cm->context->id, $component, 'package', 0, 'id', false);
        $file = reset($files);

        return $file ?: null;
    }

    /**
     * The named entries of one package, or null when it cannot be opened.
     *
     * An entry the package does not hold comes back as false, exactly as
     * ZipArchive::getFromName() reports it: whether that is fatal for the mold
     * is the caller's decision, not this helper's.
     *
     * @param stored_file $file The stored package.
     * @param string[] $names The zip entries to read, in reading order.
     * @return array<string, string|false>|null Entry name => its raw text.
     */
    protected function package_entries(stored_file $file, array $names): ?array {
        // The same primitive h5pactivity_parameters::validate_package() uses:
        // a stored file has to be on disk before ZipArchive can read it.
        $temppath = $file->copy_content_to_temp();
        try {
            $zip = new \ZipArchive();
            if ($zip->open($temppath) !== true) {
                return null;
            }
            $entries = [];
            foreach ($names as $name) {
                $entries[$name] = $zip->getFromName($name);
            }
            $zip->close();
        } finally {
            @unlink($temppath);
        }

        return $entries;
    }
}
