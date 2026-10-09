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

namespace local_coursegen\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/file_scenarios_more.php');

/**
 * What a result of the AI service looks like for each module that is created from one, and where each file a text of
 * it references has to end up.
 *
 * A scenario is: the module, a builder of the result given the texts to put in it, and its slots. A slot is a text
 * field with the table and column that store it, the rows of that table for an activity, the number of the row
 * the text is in, and the file area (and the kind of item id) the module serves its files from.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_scenarios {
    /** @var string[] The modules that are intro-only scenarios, created without the service (they need a package). */
    public const PACKAGE_MODULES = ['url', 'resource', 'folder', 'imscp', 'scorm', 'h5pactivity'];

    /**
     * The parameters every module form takes.
     *
     * @param string $mod
     * @param array $extra
     * @param string $intro
     * @return array
     */
    public static function base_params(string $mod, array $extra = [], string $intro = '<p>intro</p>'): array {
        return ['modulename' => $mod, 'visible' => 1, 'visibleoncoursepage' => 1, 'name' => 'x', 'section' => 1,
            'introeditor' => ['text' => $intro, 'format' => 1]] + $extra;
    }

    /**
     * A result with the module settings inside the parameters, where the service puts them.
     *
     * @param string $module
     * @param array $parameters
     * @param array $settings
     * @return array
     */
    public static function result(string $module, array $parameters, array $settings = []): array {
        if ($settings) {
            $parameters['mod_settings'] = $settings;
        }
        return ['resource_type' => $module, 'parameters' => $parameters];
    }

    /**
     * A text editor value.
     *
     * @param string $text
     * @return array
     */
    public static function editor(string $text): array {
        return ['text' => $text, 'format' => 1];
    }

    /**
     * A slot: a text field of a module.
     *
     * @param string $table
     * @param string $column
     * @param callable $rows The rows of the table for an activity (instance id => rows).
     * @param string $area Component and file area, "component/area".
     * @param mixed $item 0, 'row', 'qid' (the question of the row) or 'sub' (the subwiki of the page).
     * @param int $n The number of the row, from 0.
     * @param bool $aswritten Whether the module shows the field as written, without serving files from it.
     * @return array
     */
    public static function slot(
        string $table,
        string $column,
        callable $rows,
        string $area,
        $item = 0,
        int $n = 0,
        bool $aswritten = false
    ): array {
        [$component, $filearea] = explode('/', $area);
        return [
            'table' => $table, 'column' => $column, 'rows' => $rows, 'n' => $n, 'component' => $component,
            'area' => $filearea, 'item' => $item, 'asis' => $aswritten,
        ];
    }

    /**
     * The rows of a table that belong to an activity through a column.
     *
     * @param string $table
     * @param string $column
     * @return callable
     */
    public static function rows_by(string $table, string $column): callable {
        return static function (int $instance) use ($table, $column): array {
            global $DB;
            return $DB->get_records($table, [$column => $instance], 'id');
        };
    }

    /**
     * The row of the activity itself.
     *
     * @param string $table
     * @return callable
     */
    public static function root(string $table): callable {
        return static function (int $instance) use ($table): array {
            global $DB;
            return $DB->get_records($table, ['id' => $instance]);
        };
    }

    /**
     * The rows of a table found through a query that takes the activity's instance id.
     *
     * @param string $sql
     * @return callable
     */
    public static function rows_of(string $sql): callable {
        return static function (int $instance) use ($sql): array {
            global $DB;
            return $DB->get_records_sql($sql, [$instance]);
        };
    }

    /**
     * The slot of the introduction every module has.
     *
     * @param string $module
     * @return array
     */
    public static function intro(string $module): array {
        return self::slot($module, 'intro', self::root($module), 'mod_' . $module . '/intro');
    }

    /**
     * Every scenario.
     *
     * @return array[]
     */
    public static function all(): array {
        $first = [
            self::page(), self::label(), self::forum(), self::lesson(), self::book(), self::glossary(), self::wiki(),
        ];
        return array_merge($first, file_scenarios_more::all());
    }

    /**
     * A page.
     *
     * @return array
     */
    private static function page(): array {
        $build = static fn(array $t) => self::result('page', self::base_params('page', [
            'page' => self::editor($t['content']), 'display' => 5, 'printintro' => 0, 'printlastmodified' => 1,
            'printheading' => 1,
        ], $t['intro']));
        return ['module' => 'page', 'build' => $build, 'slots' => [
            'intro' => self::intro('page'),
            'content' => self::slot('page', 'content', self::root('page'), 'mod_page/content'),
        ]];
    }

    /**
     * A label.
     *
     * @return array
     */
    private static function label(): array {
        $build = static fn(array $t) => self::result('label', self::base_params('label', [], $t['intro']));
        return ['module' => 'label', 'build' => $build, 'slots' => ['intro' => self::intro('label')]];
    }

    /**
     * A forum with a discussion.
     *
     * @return array
     */
    private static function forum(): array {
        $build = static fn(array $t) => self::result(
            'forum',
            self::base_params('forum', ['type' => 'general', 'forcesubscribe' => 0], $t['intro']),
            ['discussions' => [['subject' => 'Debate', 'message' => $t['message']]]]
        );
        $posts = self::rows_of('SELECT p.* FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion
                                 WHERE d.forum = ? ORDER BY p.id');
        return ['module' => 'forum', 'build' => $build, 'slots' => [
            'intro' => self::intro('forum'),
            'message' => self::slot('forum_posts', 'message', $posts, 'mod_forum/post', 'row'),
        ]];
    }

    /**
     * A lesson with a content page and a question page.
     *
     * @return array
     */
    private static function lesson(): array {
        $build = static fn(array $t) => self::result('lesson', self::base_params('lesson', [], $t['intro']), ['pages' => [
            ['page_type' => 'content', 'title' => 'P1', 'content_html' => $t['pagecontent'], 'button_text' => 'Next'],
            ['page_type' => 'multi_choice', 'title' => 'Q1', 'content_html' => '<p>Question</p>', 'options' => [
                ['text' => $t['answer'], 'correct' => true, 'feedback' => $t['response']],
                ['text' => 'Other', 'correct' => false, 'feedback' => 'No'],
            ]],
        ]]);
        $pages = self::rows_by('lesson_pages', 'lessonid');
        $answers = self::rows_by('lesson_answers', 'lessonid');
        return ['module' => 'lesson', 'build' => $build, 'slots' => [
            'intro' => self::intro('lesson'),
            'pagecontent' => self::slot('lesson_pages', 'contents', $pages, 'mod_lesson/page_contents', 'row'),
            // The first answer is the content page's button, the second the first option of the question.
            'answer' => self::slot('lesson_answers', 'answer', $answers, 'mod_lesson/page_answers', 'row', 1),
            'response' => self::slot('lesson_answers', 'response', $answers, 'mod_lesson/page_responses', 'row', 1),
        ]];
    }

    /**
     * A book with a chapter.
     *
     * @return array
     */
    private static function book(): array {
        $build = static fn(array $t) => self::result(
            'book',
            self::base_params('book', ['numbering' => 1, 'navstyle' => 1, 'customtitles' => 0], $t['intro']),
            ['chapters' => [['title' => 'C1', 'content_editor' => self::editor($t['chapter'])]]]
        );
        $chapters = self::rows_by('book_chapters', 'bookid');
        return ['module' => 'book', 'build' => $build, 'slots' => [
            'intro' => self::intro('book'),
            'chapter' => self::slot('book_chapters', 'content', $chapters, 'mod_book/chapter', 'row'),
        ]];
    }

    /**
     * A glossary with an entry.
     *
     * @return array
     */
    private static function glossary(): array {
        $build = static fn(array $t) => self::result(
            'glossary',
            self::base_params('glossary', ['displayformat' => 'dictionary'], $t['intro']),
            ['entries' => [['concept' => 'Term', 'definition_editor' => self::editor($t['definition'])]]]
        );
        $entries = self::rows_by('glossary_entries', 'glossaryid');
        return ['module' => 'glossary', 'build' => $build, 'slots' => [
            'intro' => self::intro('glossary'),
            'definition' => self::slot('glossary_entries', 'definition', $entries, 'mod_glossary/entry', 'row'),
        ]];
    }

    /**
     * A wiki with its first page, which has an empty version before the one with the text.
     *
     * @return array
     */
    private static function wiki(): array {
        $build = static fn(array $t) => self::result(
            'wiki',
            self::base_params(
                'wiki',
                ['wikimode' => 'collaborative', 'firstpagetitle' => 'Home', 'defaultformat' => 'html'],
                $t['intro']
            ),
            ['pages' => [['title' => 'Home', 'newcontent_editor' => ['text' => $t['page'], 'format' => 'html']]]]
        );
        $versions = self::rows_of('SELECT v.* FROM {wiki_versions} v JOIN {wiki_pages} p ON p.id = v.pageid
                                    JOIN {wiki_subwikis} s ON s.id = p.subwikiid WHERE s.wikiid = ? ORDER BY v.id');
        return ['module' => 'wiki', 'build' => $build, 'slots' => [
            'intro' => self::intro('wiki'),
            'page' => self::slot('wiki_versions', 'content', $versions, 'mod_wiki/attachments', 'sub', 1),
        ]];
    }
}
