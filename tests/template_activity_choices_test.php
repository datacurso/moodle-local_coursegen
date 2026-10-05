<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursegen\output;

/**
 * Tests the deliberately small set of activity choices in the template editor.
 *
 * @package local_coursegen
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\output\template_row_options
 */
final class template_activity_choices_test extends \advanced_testcase {
    /**
     * Every activity, including unsupported module types, offers only the two supported choices.
     */
    public function test_activity_actions_are_limited_to_keep_or_modify(): void {
        $page = template_row_options::activity_actions(1, 'page');
        $forum = template_row_options::activity_actions(2, 'forum');

        $this->assertSame(['keep', 'template'], array_column($page, 'value'));
        $this->assertSame(['keep', 'template'], array_column($forum, 'value'));
    }

    /**
     * Legacy persisted actions do not reappear in the simplified editor.
     *
     * @dataProvider legacy_action_provider
     * @param string $action Previously supported action.
     */
    public function test_legacy_actions_hydrate_as_keep(string $action): void {
        $options = template_row_options::activity_actions(1, 'resource', $action);

        $this->assertSame('keep', template_row_options::active_action($options));
        $this->assertSame(['keep', 'template'], array_column($options, 'value'));
    }

    /**
     * @return array
     */
    public static function legacy_action_provider(): array {
        return [
            'reference' => ['reference'],
            'exclude' => ['exclude'],
            'space' => ['space'],
        ];
    }
}
