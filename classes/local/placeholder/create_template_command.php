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

namespace local_coursegen\local\placeholder;

/**
 * The command that makes the template of a course from the placeholders of its activities: what it accepts, how it
 * acts and what it prints. The script in cli/ only reads the parameters and prints the result.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_template_command {
    /** @var int The exit code of a wrong use of the command. */
    public const EXIT_USAGE = 1;

    /**
     * The options of the command for cli_get_params.
     *
     * @return array {long options with their defaults, short names}
     */
    public static function definition(): array {
        $long = [
            'courseid' => null,
            'name' => null,
            'scope' => template_plan_builder::SCOPE_COURSE,
            'dry-run' => false,
            'json' => false,
            'replace' => false,
            'no-replace' => false,
            'allow-empty' => false,
            'help' => false,
        ];
        $short = ['h' => 'help'];
        return [$long, $short];
    }

    /**
     * What the command prints for --help.
     *
     * @return string
     */
    public static function usage(): string {
        return <<<'TEXT'
Make the template of a course whose activities carry placeholders.

The template gets the name of the course. Every activity with placeholders is marked "use as template" and gets one
instance right after it; the other activities are kept. Running it again for the same course and name replaces the
template that exists.

Options:
  --courseid=N     The course to read (required), e.g. 646.
  --name=TEXT      Name of the template; by default the full name of the course.
  --scope=SCOPE    Who may use each mold: course (default) or section.
  --dry-run        Print what would be saved and write nothing.
  --json           Print the result, and the errors, as JSON.
  --replace        Replace the template with the same name and course (default).
  --no-replace     Refuse when a template with the same name and course exists.
  --allow-empty    Make the template even when no activity has placeholders.
  -h, --help       Print this help.

Exit codes: 0 done, 1 wrong use, 2 no such course, 3 no activities, 4 no placeholders, 5 template tables missing,
6 template already exists, 7 several templates share the name, 8 empty name, 9 no administrator.
TEXT;
    }

    /**
     * Run the command.
     *
     * @param array $options The options as cli_get_params returns them.
     * @return array {code: int, output: string}
     */
    public static function run(array $options): array {
        if (!empty($options['help'])) {
            $usage = self::usage();
            return self::outcome(0, $usage);
        }
        $problem = self::option_problem($options);
        if ($problem !== null) {
            return self::failure($problem, self::EXIT_USAGE, !empty($options['json']));
        }
        $admin = get_admin();
        if (!$admin) {
            return self::failure(
                'There is no administrator user to save the template as.',
                template_creation_exception::NO_ADMIN,
                !empty($options['json'])
            );
        }
        \core\session\manager::set_user($admin);
        return self::execute($options);
    }

    /**
     * What is wrong with the options, if anything.
     *
     * @param array $options The options.
     * @return string|null The message, or null when they are fine.
     */
    private static function option_problem(array $options): ?string {
        $courseid = $options['courseid'] ?? null;
        if ($courseid === null || !ctype_digit((string) $courseid) || (int) $courseid < 1) {
            return 'The option --courseid is required and must be a positive whole number.';
        }
        $scopes = [template_plan_builder::SCOPE_COURSE, template_plan_builder::SCOPE_SECTION];
        $scope = $options['scope'] ?? '';
        if (!in_array($scope, $scopes, true)) {
            return 'The option --scope must be course or section.';
        }
        if (!empty($options['replace']) && !empty($options['no-replace'])) {
            return 'The options --replace and --no-replace cannot be used together.';
        }
        return null;
    }

    /**
     * Make or plan the template and print the result.
     *
     * @param array $options The options, already checked.
     * @return array {code: int, output: string}
     */
    private static function execute(array $options): array {
        $json = !empty($options['json']);
        $dryrun = !empty($options['dry-run']);
        $courseid = (int) $options['courseid'];
        $name = $options['name'] ?? null;
        $settings = [
            'name' => $name,
            'scope' => $options['scope'],
            'replace' => empty($options['no-replace']),
            'allowempty' => !empty($options['allow-empty']),
        ];
        try {
            if ($dryrun) {
                $outcome = course_template_creator::plan($courseid, $settings);
            } else {
                $outcome = course_template_creator::create($courseid, $settings);
            }
        } catch (template_creation_exception $error) {
            $message = $error->getMessage();
            $reason = $error->reason();
            return self::failure($message, $reason, $json);
        }
        $output = self::render($outcome, $dryrun, $json);
        return self::outcome(0, $output);
    }

    /**
     * What the command prints on success.
     *
     * @param array $outcome The plan, plus what was saved when it was saved.
     * @param bool $dryrun Whether nothing was written.
     * @param bool $json Whether to print JSON.
     * @return string
     */
    private static function render(array $outcome, bool $dryrun, bool $json): string {
        $summary = self::summary($outcome, $dryrun);
        if ($json) {
            $document = $summary;
            if ($dryrun) {
                $document['payload'] = ['sections' => $outcome['sections']];
            }
            return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $text = self::summary_text($summary);
        if ($dryrun) {
            $payload = json_encode(['sections' => $outcome['sections']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $text .= "\nPayload that would be saved:\n" . $payload;
        }
        return $text;
    }

    /**
     * The numbers and names of the result, without the payload.
     *
     * @param array $outcome The plan, plus what was saved when it was saved.
     * @param bool $dryrun Whether nothing was written.
     * @return array
     */
    private static function summary(array $outcome, bool $dryrun): array {
        $sections = count($outcome['sections']);
        $activities = 0;
        foreach ($outcome['sections'] as $section) {
            $activities += count($section['activities']);
        }
        $templateid = $outcome['templateid'] ?? null;
        $replaced = $outcome['replaced'] ?? null;
        return [
            'dryrun' => $dryrun,
            'saved' => !$dryrun,
            'templateid' => $templateid,
            'replaced' => $replaced,
            'name' => $outcome['name'],
            'courseid' => $outcome['courseid'],
            'scope' => $outcome['scope'],
            'sections' => $sections,
            'activities' => $activities,
            'molds' => $outcome['molds'],
            'instances' => $outcome['instances'],
            'kept' => $outcome['kept'],
            'unsupported' => $outcome['unsupported'],
            'warnings' => $outcome['warnings'],
        ];
    }

    /**
     * The summary as lines for a person.
     *
     * @param array $summary The summary.
     * @return string
     */
    private static function summary_text(array $summary): string {
        $lines = ['Template "' . $summary['name'] . '" from course ' . $summary['courseid']];
        $lines[] = '  ' . self::action_text($summary);
        $lines[] = '  sections: ' . $summary['sections'] . ', activities: ' . $summary['activities'];
        $lines[] = '  used as template: ' . count($summary['molds']) . ' (one instance each), kept: ' . $summary['kept'];
        foreach ($summary['warnings'] as $warning) {
            $lines[] = '  warning: ' . $warning;
        }
        return implode("\n", $lines);
    }

    /**
     * What was done with the template, in words.
     *
     * @param array $summary The summary.
     * @return string
     */
    private static function action_text(array $summary): string {
        if ($summary['dryrun']) {
            return 'dry run: nothing was written';
        }
        if ($summary['replaced']) {
            return 'replaced the template with id ' . $summary['templateid'];
        }
        return 'created the template with id ' . $summary['templateid'];
    }

    /**
     * A failure: the message, or the message as JSON, and the exit code.
     *
     * @param string $message What went wrong.
     * @param int $code The exit code.
     * @param bool $json Whether to print JSON.
     * @return array {code: int, output: string}
     */
    private static function failure(string $message, int $code, bool $json): array {
        if ($json) {
            $document = json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return self::outcome($code, $document);
        }
        return self::outcome($code, 'Error: ' . $message);
    }

    /**
     * An exit code with what to print.
     *
     * @param int $code The exit code.
     * @param string $output What to print.
     * @return array {code, output}
     */
    private static function outcome(int $code, string $output): array {
        return ['code' => $code, 'output' => $output];
    }
}
