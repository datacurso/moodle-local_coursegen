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

namespace local_coursegen;

/**
 * The capabilities the plugin defines agree with the code and the language packs.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class capabilities_definition_test extends \basic_testcase {
    /** The broad capabilities that remain only as a source of permissions; no code may check them. */
    private const CLONE_SOURCES_ONLY = [
        'local/coursegen:managetemplates',
        'local/coursegen:managesysteminstructions',
        'local/coursegen:manageimagegeneration',
        'local/coursegen:createcoursewithai',
    ];

    /**
     * The capabilities db/access.php defines.
     *
     * @return array
     */
    private function defined_capabilities(): array {
        global $CFG;

        $capabilities = [];
        include($CFG->dirroot . '/local/coursegen/db/access.php');
        return $capabilities;
    }

    /**
     * The strings of a language pack.
     *
     * @param string $lang
     * @return array
     */
    private function language_strings(string $lang): array {
        global $CFG;

        $string = [];
        include($CFG->dirroot . '/local/coursegen/lang/' . $lang . '/local_coursegen.php');
        return $string;
    }

    /**
     * The key of a capability in the language packs.
     *
     * @param string $capability
     * @return string
     */
    private function string_key(string $capability): string {
        $prefixlength = strlen('local/');
        return substr($capability, $prefixlength);
    }

    /**
     * Every capability has a name in English and in Spanish.
     */
    public function test_every_capability_has_a_string_in_english_and_spanish(): void {
        $definitions = $this->defined_capabilities();
        $english = $this->language_strings('en');
        $spanish = $this->language_strings('es');

        $missing = [];
        foreach (array_keys($definitions) as $capability) {
            $key = $this->string_key($capability);
            if (!isset($english[$key]) || !isset($spanish[$key])) {
                $missing[] = $capability;
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Each capability that copies its permissions copies them from a capability that exists.
     */
    public function test_every_clone_source_is_defined(): void {
        $definitions = $this->defined_capabilities();

        $broken = [];
        foreach ($definitions as $name => $definition) {
            $source = $definition['clonepermissionsfrom'] ?? null;
            if ($source !== null && strpos($source, 'local/coursegen:') === 0 && !isset($definitions[$source])) {
                $broken[] = $name;
            }
        }

        $this->assertSame([], $broken);
    }

    /**
     * On a new install nothing is copied, so a capability must start with the roles, the
     * context level and the type of the one it copies from.
     */
    public function test_a_capability_starts_like_its_source(): void {
        $definitions = $this->defined_capabilities();

        $different = [];
        foreach ($definitions as $name => $definition) {
            $source = $definition['clonepermissionsfrom'] ?? null;
            if ($source === null || !isset($definitions[$source])) {
                continue;
            }
            $sourcedefinition = $definitions[$source];
            $same = $definition['archetypes'] === $sourcedefinition['archetypes']
                && $definition['contextlevel'] === $sourcedefinition['contextlevel'];
            if (!$same) {
                $different[] = $name;
            }
        }

        $this->assertSame([], $different);
    }

    /**
     * The capabilities that are only a source are still defined, because roles refer to them.
     */
    public function test_the_clone_sources_are_still_defined(): void {
        $definitions = $this->defined_capabilities();

        $missing = array_diff(self::CLONE_SOURCES_ONLY, array_keys($definitions));

        $this->assertSame([], array_values($missing));
    }

    /**
     * The files of the plugin that can check a capability.
     *
     * @return string[]
     */
    private function source_files(): array {
        global $CFG;

        $directory = new \RecursiveDirectoryIterator($CFG->dirroot . '/local/coursegen', \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = [];
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        return $files;
    }

    /**
     * Whether a file may name a capability that is only a source: the definitions,
     * the language packs, the tests, the built scripts and the documents.
     *
     * @param string $path
     * @return bool
     */
    private function may_name_clone_sources(string $path): bool {
        $excluded = ['/db/access.php', '/lang/', '/tests/', '/amd/build/', '/_docs/', '.md'];
        foreach ($excluded as $part) {
            if (str_contains($path, $part)) {
                return true;
            }
        }
        return preg_match('/\.(php|mustache|js)$/', $path) !== 1;
    }

    /**
     * The capabilities that are only a source which a file names.
     *
     * @param string $path
     * @return string[]
     */
    private function clone_sources_named_in(string $path): array {
        $contents = file_get_contents($path);
        $named = [];
        foreach (self::CLONE_SOURCES_ONLY as $source) {
            if (str_contains($contents, $source)) {
                $named[] = basename($path) . ' ' . $source;
            }
        }
        return $named;
    }

    /**
     * No code checks a capability that is only a source.
     */
    public function test_no_code_checks_a_capability_that_is_only_a_source(): void {
        $files = $this->source_files();

        $offenders = [];
        foreach ($files as $path) {
            if ($this->may_name_clone_sources($path)) {
                continue;
            }
            $named = $this->clone_sources_named_in($path);
            $offenders = array_merge($offenders, $named);
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The plugin's capabilities a web service declares.
     *
     * @param array $function One entry of db/services.php.
     * @return string[]
     */
    private function declared_plugin_capabilities(array $function): array {
        $capabilities = $function['capabilities'] ?? '';
        $declared = explode(',', $capabilities);
        $ours = preg_grep('~^local/coursegen:~', $declared);
        return array_values($ours);
    }

    /**
     * The web services declare only capabilities that are defined.
     */
    public function test_services_declare_defined_capabilities_only(): void {
        global $CFG;

        $functions = [];
        include($CFG->dirroot . '/local/coursegen/db/services.php');
        $defined = array_keys($this->defined_capabilities());

        $unknown = [];
        foreach ($functions as $function) {
            $ours = $this->declared_plugin_capabilities($function);
            $missing = array_diff($ours, $defined);
            $unknown = array_merge($unknown, $missing);
        }

        $this->assertSame([], $unknown);
    }
}
