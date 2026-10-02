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

use local_coursegen\local\backup\activity_reader;

/**
 * Gives a new activity every file its texts reference, whatever module it is.
 *
 * Run once the activity exists, so that every row and its id are real. The
 * rows come from the module's own backup structure (see text_carrier_collector),
 * the text columns from the database itself, and the files from the sources
 * of the activity (the template's course, the teacher, the AI service). Nothing
 * here names a module: a text field added to any module tomorrow is covered
 * the moment it is in the module's backup structure.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_file_pass {
    /** @var string[] Text that may hold something to do: a file reference or a marker to remove. */
    private const SIGNS = ['@@PLUGINFILE@@', 'pluginfile.php/', '<img', '![', 'coursegen:image', '{{image'];

    /**
     * Modules with rows the module's backup structure does not hold, and who finds them: module => provider class.
     *
     * A quiz refers to its questions through the question bank, which a backup keeps apart from the quiz.
     */
    private const EXTRA_CARRIERS = [
        'quiz' => quiz_question_carriers::class,
    ];

    /** @var file_source Where the files are found. */
    private file_source $sources;

    /** @var text_file_rewriter */
    private text_file_rewriter $rewriter;

    /** @var file_area_chooser */
    private file_area_chooser $chooser;

    /** @var string[][] Table => its text columns. */
    private array $columns = [];

    /**
     * Constructor.
     *
     * @param file_source $sources
     */
    public function __construct(file_source $sources) {
        $this->sources = $sources;
        $this->rewriter = new text_file_rewriter($sources);
        $this->chooser = new file_area_chooser();
    }

    /**
     * The pass of a new activity with the sources it normally has.
     *
     * @param int|null $sourcecourseid The template's base course, or null.
     * @return self
     */
    public static function for_new_activity(?int $sourcecourseid): self {
        return new self(file_sources::for_new_activity($sourcecourseid));
    }

    /**
     * Give the activity the files its texts reference.
     *
     * @param \stdClass $cm The activity: id (course module), instance, modname and course.
     * @param string $activityname Its name, for the error.
     * @throws file_copy_exception When a text references a file that cannot be given to the activity.
     */
    public function run(\stdClass $cm, string $activityname): void {
        activity_reader::require_backup_api();
        $collector = new text_carrier_collector();
        $walked = activity_reader::walk($cm, $collector, true);
        $carriers = [];
        if ($walked) {
            $carriers = $collector->get_carriers();
        }
        $extra = $this->extra_carriers($cm);
        $all = array_merge($carriers, $extra);
        $this->process_carriers($all, $activityname);
    }

    /**
     * The rows the module keeps outside its backup structure.
     *
     * @param \stdClass $cm
     * @return text_carrier[]
     */
    private function extra_carriers(\stdClass $cm): array {
        $class = self::EXTRA_CARRIERS[$cm->modname] ?? null;
        if ($class === null) {
            return [];
        }
        $provider = new $class();
        return $provider->carriers($cm);
    }

    /**
     * Process each row found.
     *
     * @param text_carrier[] $carriers
     * @param string $activityname
     */
    private function process_carriers(array $carriers, string $activityname): void {
        foreach ($carriers as $carrier) {
            $this->process_carrier($carrier, $activityname);
        }
    }

    /**
     * Process the text columns of one row.
     *
     * @param text_carrier $carrier
     * @param string $activityname
     */
    private function process_carrier(text_carrier $carrier, string $activityname): void {
        global $DB;

        $columns = $this->text_columns($carrier->table);
        if (!$columns) {
            return;
        }
        $row = $DB->get_record($carrier->table, ['id' => $carrier->id]);
        if (!$row) {
            return;
        }
        foreach ($columns as $column) {
            $where = '"' . $activityname . '" (' . $carrier->table . '.' . $column . ')';
            $this->process_column($carrier, $column, (string) $row->$column, $where);
        }
    }

    /**
     * Rewrite one text and place the files it references.
     *
     * @param text_carrier $carrier
     * @param string $column
     * @param string $text
     * @param string $where
     */
    private function process_column(text_carrier $carrier, string $column, string $text, string $where): void {
        global $DB;

        if (!$this->worth_reading($text)) {
            return;
        }
        if (!$this->chooser->holds_files($carrier->table, $column)) {
            $this->refuse_placeholders($text, $where);
            return;
        }
        $rewritten = $this->rewriter->rewrite($text, $where, $carrier->areas);
        $paths = text_file_rewriter::placeholder_paths($rewritten->text);
        $this->place_files($carrier, $column, $paths, $rewritten, $where);
        if ($rewritten->text !== $text) {
            $DB->set_field($carrier->table, $column, $rewritten->text, ['id' => $carrier->id]);
        }
    }

    /**
     * Whether a text could hold anything this pass acts on.
     *
     * @param string $text
     * @return bool
     */
    private function worth_reading(string $text): bool {
        if ($text === '') {
            return false;
        }
        foreach (self::SIGNS as $sign) {
            if (str_contains($text, $sign)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A column the module shows as written cannot serve a file named by a placeholder.
     *
     * @param string $text
     * @param string $where
     * @throws file_copy_exception When the text holds a placeholder.
     */
    private function refuse_placeholders(string $text, string $where): void {
        $paths = text_file_rewriter::placeholder_paths($text);
        if ($paths) {
            throw file_copy_exception::area_unknown($where, ltrim($paths[0], '/'));
        }
    }

    /**
     * Place each file a text references in the area its column keeps files in.
     *
     * @param text_carrier $carrier
     * @param string $column
     * @param string[] $paths
     * @param rewritten_text $rewritten
     * @param string $where
     */
    private function place_files(
        text_carrier $carrier,
        string $column,
        array $paths,
        rewritten_text $rewritten,
        string $where
    ): void {
        foreach ($paths as $path) {
            $source = $rewritten->files[$path] ?? null;
            $area = $this->chooser->choose($carrier, $column, $source, $where, $path);
            $this->place_file($area, $path, $source, $where);
        }
    }

    /**
     * Put one file in an area, unless the area already holds it.
     *
     * @param file_area $area
     * @param string $path Decoded path after the placeholder.
     * @param \stored_file|null $source The file when the text pointed at it directly; null when only named.
     * @param string $where
     */
    private function place_file(file_area $area, string $path, ?\stored_file $source, string $where): void {
        $fs = get_file_storage();
        $filename = basename($path);
        $filepath = dirname($path);
        if ($filepath !== '/') {
            $filepath .= '/';
        }
        $existing = $fs->get_file($area->contextid, $area->component, $area->filearea, $area->itemid, $filepath, $filename);
        if ($existing) {
            $this->check_same_file($existing, $source, $where, $path);
            return;
        }
        if ($source === null) {
            $encoded = text_file_rewriter::encode_path($path);
            $reference = new file_reference(file_reference::KIND_PLACEHOLDER, $encoded);
            $source = $this->sources->find($reference);
        }
        if ($source === null) {
            throw file_copy_exception::missing($where, ltrim($path, '/'));
        }
        $record = [
            'contextid' => $area->contextid,
            'component' => $area->component,
            'filearea' => $area->filearea,
            'itemid' => $area->itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'author' => $source->get_author(),
            'license' => $source->get_license(),
        ];
        $fs->create_file_from_storedfile($record, $source);
    }

    /**
     * An area that already holds a file of that name must hold the same one.
     *
     * @param \stored_file $existing
     * @param \stored_file|null $source
     * @param string $where
     * @param string $path
     */
    private function check_same_file(\stored_file $existing, ?\stored_file $source, string $where, string $path): void {
        if ($source === null) {
            return;
        }
        if ($existing->get_contenthash() !== $source->get_contenthash()) {
            throw file_copy_exception::conflict($where, ltrim($path, '/'));
        }
    }

    /**
     * The text columns of a table, which are the ones that can hold a reference.
     *
     * @param string $table
     * @return string[]
     */
    private function text_columns(string $table): array {
        global $DB;

        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $definitions = $DB->get_columns($table);
        $this->columns[$table] = $this->text_column_names($definitions);
        return $this->columns[$table];
    }

    /**
     * The names, among column definitions, of those that hold text; none when the table has no id.
     *
     * @param \database_column_info[] $definitions
     * @return string[]
     */
    private function text_column_names(array $definitions): array {
        if (!isset($definitions['id'])) {
            return [];
        }
        $names = [];
        foreach ($definitions as $name => $definition) {
            if ($definition->meta_type === 'X') {
                $names[] = (string) $name;
            }
        }
        return $names;
    }
}
