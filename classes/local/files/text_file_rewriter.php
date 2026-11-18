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

use local_coursegen\utils\mold_file_copier;

/**
 * Turns every way a text can point at a file into the one way Moodle stores it, "@@PLUGINFILE@@/name".
 *
 * A text reaches a new activity pointing at files in three ways: the absolute
 * address of a file of the template's course or one the teacher brought, a
 * placeholder naming a file the AI service made, and the path of an image the
 * AI service made. The first and the last are rewritten to the placeholder
 * and the file they name is handed back, so that placing the files of a text
 * is the same work whichever way it spoke of them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_file_rewriter {
    /** @var string What every reference to a file of the activity starts with once rewritten. */
    public const PLACEHOLDER = '@@PLUGINFILE@@';

    /** @var file_source Where the files the references name are found. */
    private file_source $sources;

    /** @var string The activity and field being rewritten, for the error. */
    private string $where = '';

    /** @var \stored_file[] The files found while rewriting, by the path the text gives them. */
    private array $found = [];

    /** @var file_area[] The areas the row being rewritten declares. */
    private array $areas = [];

    /**
     * Constructor.
     *
     * @param file_source $sources
     */
    public function __construct(file_source $sources) {
        $this->sources = $sources;
    }

    /**
     * Rewrite one text.
     *
     * @param string $text
     * @param string $where The activity and field the text is in, for the error.
     * @param file_area[] $areas The areas the row of the text declares: an address is only taken for a file of one
     *     of them (or of this plugin's own storage), because any other address is a link to somewhere else.
     * @return rewritten_text
     * @throws file_copy_exception When a reference names a file that no source holds.
     */
    public function rewrite(string $text, string $where, array $areas = []): rewritten_text {
        $this->where = $where;
        $this->areas = $areas;
        $this->found = [];
        $text = $this->rewrite_addresses($text);
        $text = $this->rewrite_html_images($text);
        $text = $this->rewrite_markdown_images($text);
        $text = $this->strip_markers($text);
        return new rewritten_text($text, $this->found);
    }

    /**
     * The placeholders a text holds, as the (decoded) path of the file each names.
     *
     * @param string $text
     * @return string[]
     */
    public static function placeholder_paths(string $text): array {
        if (!str_contains($text, self::PLACEHOLDER . '/')) {
            return [];
        }
        preg_match_all('~@@PLUGINFILE@@((?:/[^/"\'<>\s?#)]+)+)~u', $text, $matches);
        $decoded = array_map('rawurldecode', $matches[1]);
        $unique = array_unique($decoded);
        return array_values($unique);
    }

    /**
     * Replace the absolute pluginfile.php address of a file of this site by a placeholder.
     *
     * @param string $text
     * @return string
     */
    private function rewrite_addresses(string $text): string {
        global $CFG;

        if (!str_contains($text, 'pluginfile.php/')) {
            return $text;
        }
        $prefix = preg_quote($CFG->wwwroot . '/pluginfile.php/', '#');
        $pattern = '#\b(src|href|data|poster)\s*=\s*(["\'])(' . $prefix . '[^"\']+)\2#iu';
        $rewritten = preg_replace_callback($pattern, [$this, 'address_to_placeholder'], $text);
        return $rewritten ?? $text;
    }

    /**
     * The attribute holding an address, with the address replaced.
     *
     * @param array $matches The attribute, its name, its quote and the address.
     * @return string
     */
    public function address_to_placeholder(array $matches): string {
        $address = html_entity_decode($matches[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reference = new file_reference(file_reference::KIND_URL, $address);
        $file = $this->file_or_fail($reference, $address);
        if (!$this->belongs_to_row($file)) {
            return $matches[0];
        }
        $path = $this->remember($file);
        $encoded = self::encode_path($path);
        return $matches[1] . '=' . $matches[2] . self::PLACEHOLDER . $encoded . $matches[2];
    }

    /**
     * Replace the src of an img tag that points at an image the AI service made.
     *
     * @param string $text
     * @return string
     */
    private function rewrite_html_images(string $text): string {
        if (!str_contains($text, '<img')) {
            return $text;
        }
        $rewritten = preg_replace_callback('/<img\b[^>]*>/iu', [$this, 'html_image_to_placeholder'], $text);
        return $rewritten ?? $text;
    }

    /**
     * An img tag whose src is replaced when it is an image of the AI service.
     *
     * @param array $matches The tag.
     * @return string
     */
    public function html_image_to_placeholder(array $matches): string {
        $tag = $matches[0];
        if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/iu', $tag, $source)) {
            return $tag;
        }
        $written = trim($source[2]);
        $path = html_entity_decode($written, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!generated_image_source::is_image_path($path)) {
            return $tag;
        }
        $placeholder = $this->image_placeholder($path);
        $replaced = preg_replace('/\bsrc\s*=\s*(["\']).*?\1/iu', 'src="' . $placeholder . '"', $tag, 1);
        return $replaced ?? $tag;
    }

    /**
     * Replace a markdown image that points at an image the AI service made by an img tag.
     *
     * @param string $text
     * @return string
     */
    private function rewrite_markdown_images(string $text): string {
        if (!str_contains($text, '![')) {
            return $text;
        }
        $pattern = '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u';
        $rewritten = preg_replace_callback($pattern, [$this, 'markdown_image_to_tag'], $text);
        return $rewritten ?? $text;
    }

    /**
     * An img tag for a markdown image, or the markdown as it was when it is not an image of the AI service.
     *
     * @param array $matches The markdown, its alt text and its path.
     * @return string
     */
    public function markdown_image_to_tag(array $matches): string {
        $path = trim($matches[2], '<>');
        if (!generated_image_source::is_image_path($path)) {
            return $matches[0];
        }
        $placeholder = $this->image_placeholder($path);
        $written = trim($matches[1]);
        $alt = htmlspecialchars($written, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<img src="' . $placeholder . '" alt="' . $alt . '" style="max-width:100%;height:auto;" />';
    }

    /**
     * The placeholder that names the image the AI service holds at a path.
     *
     * @param string $path
     * @return string
     */
    private function image_placeholder(string $path): string {
        $reference = new file_reference(file_reference::KIND_IMAGE_PATH, $path);
        $file = $this->file_or_fail($reference, $path);
        $placed = $this->remember($file);
        $encoded = self::encode_path($placed);
        return self::PLACEHOLDER . $encoded;
    }

    /**
     * Whether a file is one the row of the text keeps files of.
     *
     * @param \stored_file $file
     * @return bool
     */
    private function belongs_to_row(\stored_file $file): bool {
        if ($file->get_component() === 'local_coursegen') {
            return true;
        }
        foreach ($this->areas as $area) {
            if ($area->component === $file->get_component() && $area->filearea === $file->get_filearea()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove the markers of the AI service that were not turned into a file.
     *
     * @param string $text
     * @return string
     */
    private function strip_markers(string $text): string {
        $text = mold_file_copier::strip_image_markers($text);
        $text = preg_replace('/^\s*\{\{image:\s*.*?\s*\}\}\s*$/imu', '', $text) ?? $text;
        $text = preg_replace('/\{\{image:\s*.*?\s*\}\}/iu', '', $text) ?? $text;
        return $text;
    }

    /**
     * The file a reference names.
     *
     * @param file_reference $reference
     * @param string $shown The reference as the text wrote it.
     * @return \stored_file
     */
    private function file_or_fail(file_reference $reference, string $shown): \stored_file {
        $file = $this->sources->find($reference);
        if ($file === null) {
            throw file_copy_exception::missing($this->where, $shown);
        }
        return $file;
    }

    /**
     * Keep a file under the path the text will give it, which is unique among the files of the text.
     *
     * Two different files that share a name get different paths, so the text
     * can name each of them.
     *
     * @param \stored_file $file
     * @return string The decoded path after the placeholder.
     */
    private function remember(\stored_file $file): string {
        $filepath = $this->filepath_of($file);
        $path = $filepath . $file->get_filename();
        $filename = $file->get_filename();
        $counter = 1;
        $clashes = $this->clashes($path, $file);
        while ($clashes) {
            $counter++;
            $filename = $this->numbered($file->get_filename(), $counter);
            $path = $filepath . $filename;
            $clashes = $this->clashes($path, $file);
        }
        $this->found[$path] = $file;
        return $path;
    }

    /**
     * Whether another file, with other content, is already kept under that path.
     *
     * @param string $path
     * @param \stored_file $file
     * @return bool
     */
    private function clashes(string $path, \stored_file $file): bool {
        if (!isset($this->found[$path])) {
            return false;
        }
        return $this->found[$path]->get_contenthash() !== $file->get_contenthash();
    }

    /**
     * The folder a file keeps in the activity.
     *
     * The folders of this plugin's own storage only organise its copies, so a
     * file from there lands at the root; any other file keeps its own folder.
     *
     * @param \stored_file $file
     * @return string
     */
    private function filepath_of(\stored_file $file): string {
        if ($file->get_component() === 'local_coursegen') {
            return '/';
        }
        return $file->get_filepath();
    }

    /**
     * A file name with a number before its extension.
     *
     * @param string $filename
     * @param int $number
     * @return string
     */
    private function numbered(string $filename, int $number): string {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if ($extension === '') {
            return $base . '-' . $number;
        }
        return $base . '-' . $number . '.' . $extension;
    }

    /**
     * A path as it is written after the placeholder.
     *
     * @param string $path
     * @return string
     */
    public static function encode_path(string $path): string {
        $segments = explode('/', $path);
        $encoded = array_map('rawurlencode', $segments);
        return implode('/', $encoded);
    }
}
