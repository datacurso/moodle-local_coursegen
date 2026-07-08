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

/**
 * Pre-planning subsections decision card.
 *
 * Rendered when the service pauses BEFORE generating the plan because the
 * user's prompt asks for subsections while the per-course toggle is off
 * (`subsections_decision` interrupt). Reuses the proposals-card look:
 *
 * - Site can materialize subsections (`data.available`): "Enable subsections
 *   and plan" or "Continue with regular sections" — both answer through the
 *   feedback WS and resume the planning stream.
 * - Site cannot: "Continue with regular sections" (WS) or "Leave the chat"
 *   (client-side exit back to the context form; the abandoned session simply
 *   stays interrupted server-side).
 *
 * @module     local_coursegen/local/courseai/ui/subsections-decision
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {sendPlanningFeedback} from 'local_coursegen/repository/course';
import {getDecisionOverlay} from 'local_coursegen/local/courseai/ui/decision-overlay';
import {localizeMessage} from 'local_coursegen/local/courseai/i18n';

const BLOCK_ID = 'cgSubsectionsDecision';

/**
 * Remove any previously rendered decision card.
 */
const removeBlock = () => {
    const existing = document.getElementById(BLOCK_ID);
    if (existing) {
        existing.remove();
    }
};

/**
 * Build one full-width option button styled like a proposal card.
 *
 * @param {string} label
 * @param {Function} onClick
 * @returns {HTMLButtonElement}
 */
const buildOption = (label, onClick) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'plan-proposal-card cg-subsections-option';
    const text = document.createElement('span');
    text.className = 'plan-proposal-summary';
    text.textContent = label;
    btn.appendChild(text);
    btn.addEventListener('click', onClick);
    return btn;
};

/**
 * Render the subsections decision card and wire its options.
 *
 * @param {Object} args
 * @param {Object} args.data - Interrupt payload: {available, message}.
 * @param {Object} args.ctx  - Stream handler context (or a resume-built
 *     equivalent): state, texts, emitLog, stepsUi, openSSEStream.
 * @returns {Promise<void>}
 */
export const renderSubsectionsDecision = async({data, ctx}) => {
    const {state, texts, emitLog, stepsUi, openSSEStream} = ctx;

    removeBlock();

    // The question as a permanent AI turn (the service also persists it as an
    // ai_notice row, so reload replays the same line).
    const question = await localizeMessage(data.message);

    const block = document.createElement('div');
    block.id = BLOCK_ID;
    block.className = 'cg-feed-proposals cg-subsections-decision';

    const title = document.createElement('p');
    title.className = 'plan-proposals-title';
    title.textContent = question || texts.courseai_subsections_decision_title || '';
    block.appendChild(title);

    const group = document.createElement('div');
    group.className = 'plan-proposals-group';

    const finish = (logMessage, pendingAction) => {
        // One user turn per choice; the service persists the same gesture as a
        // user_action row, so reload replays it identically.
        if (typeof emitLog === 'function' && logMessage) {
            emitLog({actor: 'user', kind: 'user', message: logMessage});
        }
        removeBlock();
        getDecisionOverlay().hide();
        if (pendingAction) {
            sendPlanningFeedback({recordid: state.sessionid, pendingAction})
                .then(() => {
                    // Resume planning in NORMAL mode (no keepPlan: nothing is
                    // rendered yet — the initial plan must stream fresh).
                    openSSEStream(state.streamingurl, 0, 'planning');
                    return null;
                })
                .catch(() => {
                    // The next reload resumes from the snapshot; nothing to undo.
                });
        }
    };

    // The service flips with_subsections in the session config; mirror it in
    // the client so the "+" menu toggle reads ON. Dispatching change on the
    // hidden checkbox reuses the existing listeners (state + both toolbars +
    // aria-checked); the direct state write is the fallback when the toggle
    // is not rendered.
    const syncClientToggleOn = () => {
        state.withSubsections = true;
        const checkbox = document.getElementById('btnWithSubsections')
            || document.getElementById('btnCompactWithSubsections');
        if (checkbox && !checkbox.checked) {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change', {bubbles: true}));
        }
    };

    if (data.available) {
        group.appendChild(buildOption(
            texts.courseai_subsections_decision_enable || 'Enable subsections and plan',
            () => {
                syncClientToggleOn();
                finish(
                    texts.courseai_subsections_decision_enable || 'Enable subsections and plan',
                    {action: 'enable_subsections'}
                );
            }
        ));
        group.appendChild(buildOption(
            texts.courseai_subsections_decision_continue || 'Continue with regular sections',
            () => finish(
                texts.courseai_subsections_decision_continue || 'Continue with regular sections',
                {action: 'continue_without_subsections'}
            )
        ));
    } else {
        group.appendChild(buildOption(
            texts.courseai_subsections_decision_continue || 'Continue with regular sections',
            () => finish(
                texts.courseai_subsections_decision_continue || 'Continue with regular sections',
                {action: 'continue_without_subsections'}
            )
        ));
        group.appendChild(buildOption(
            texts.courseai_subsections_decision_exit || 'Leave the chat',
            () => {
                if (typeof emitLog === 'function') {
                    emitLog({
                        actor: 'user', kind: 'user',
                        message: texts.courseai_subsections_decision_exit || 'Leave the chat',
                    });
                }
                removeBlock();
                getDecisionOverlay().hide();
                // Purely client-side exit: back to the context form. The
                // abandoned session stays interrupted server-side, like any
                // other abandoned planning session.
                if (stepsUi && typeof stepsUi.backToContext === 'function') {
                    stepsUi.backToContext();
                }
            }
        ));
    }

    block.appendChild(group);

    // Same placement strategy as the proposals card: decision overlay body
    // when available, left feed otherwise.
    const overlay = getDecisionOverlay(texts);
    const body = overlay.getBody();
    if (body) {
        body.appendChild(block);
        overlay.show();
        return;
    }
    const feed = document.getElementById('cgLogAfter') || document.getElementById('cgLog');
    if (feed) {
        feed.appendChild(block);
    }
};
