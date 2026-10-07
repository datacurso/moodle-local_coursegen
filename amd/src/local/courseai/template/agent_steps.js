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

/**
 * Shows the steps the AI takes in the conversation of a template generation: one line when a step starts. A step
 * the AI can recover from by itself is never shown as a failure. The lines are added in the order the events
 * arrive, even though the labels are fetched asynchronously.
 *
 * @module     local_coursegen/local/courseai/template/agent_steps
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import {turn} from 'local_coursegen/local/courseai/template/thread';
import {toolLabelKey} from 'local_coursegen/local/courseai/template/agent_events';

let queue = Promise.resolve();

const enqueue = (task) => {
    queue = queue.then(task).catch(() => undefined);
};

const showCall = async(data) => {
    const label = await getString(toolLabelKey(data.name), 'local_coursegen');
    turn('ai', 'ai', label);
};

/**
 * Show that a tool starts.
 *
 * @param {Object} data The tool_call event.
 */
export const showToolCall = (data) => {
    enqueue(() => showCall(data));
};
