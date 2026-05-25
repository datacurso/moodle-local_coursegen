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
 * Stream output component.
 *
 * @module     local_coursegen/local/activityai/components/stream_output
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {BaseComponent} from 'core/reactive';

import * as markedModule from 'local_coursegen/marked';
import {regions, activityRegions} from 'local_coursegen/selectors';
import {loadActivityaiStrings} from 'local_coursegen/local/activityai/i18n';

export default class extends BaseComponent {
    create() {
        this.name = 'local_coursegen_activityai_stream_output';
        this.selectors = {
            OUTPUT: regions.output,
            STREAM_SECTION: activityRegions.streamingSection,
            USER_MESSAGES: activityRegions.userMessages,
            SELECTED_FILE: activityRegions.selectedFile,
            SELECTED_FILE_NAME: activityRegions.selectedFileName,
        };

        this.markedParser = markedModule.parse ? markedModule : markedModule.marked;
        this.statusHistoryByRunId = new Map();
        this.texts = {};
        this.textsLoadingPromise = null;
    }

    getWatchers() {
        return [
            {watch: 'runs:created', handler: this._renderRun},
            {watch: 'runs:updated', handler: this._renderRun},
            {watch: 'upload:updated', handler: this._renderUpload},
        ];
    }

    async stateReady() {
        await this._ensureTexts();
        this._renderUpload({element: this.reactive.state.upload});
    }

    async _ensureTexts() {
        if (Object.keys(this.texts).length) {
            return;
        }

        if (!this.textsLoadingPromise) {
            this.textsLoadingPromise = loadActivityaiStrings();
        }

        this.texts = await this.textsLoadingPromise;
    }

    _renderUpload({element}) {
        const selectedFileElement = this.getElement(this.selectors.SELECTED_FILE);
        const selectedFileNameElement = this.getElement(this.selectors.SELECTED_FILE_NAME);

        if (!selectedFileElement || !selectedFileNameElement) {
            return;
        }

        if (!element.draftitemid) {
            selectedFileElement.style.display = 'none';
            selectedFileNameElement.textContent = '';
            return;
        }

        selectedFileNameElement.textContent = String(element.filename || '');
        selectedFileElement.style.display = 'block';
    }

    async _renderRun({element}) {
        await this._ensureTexts();

        const root = this.element.closest(activityRegions.root) || this.element;
        const streamSection = root.querySelector(this.selectors.STREAM_SECTION);
        const output = root.querySelector(this.selectors.OUTPUT);

        if (streamSection) {
            streamSection.style.display = 'block';
        }

        if (!output) {
            return;
        }

        // Remove old review actions. They are only valid for the last review-needed state.
        const existingActions = output.querySelector('.local-coursegen-review-actions');
        if (existingActions) {
            existingActions.remove();
        }

        const runId = element.id;
        let wrapper = output.querySelector(`[data-run-id="${runId}"]`);
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.className = 'text-content mb-4 mt-3';
            wrapper.dataset.runId = String(runId);
            output.appendChild(wrapper);
        }

        const safeText = (value) => {
            return String(value || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        };

        const previousHistory = this.statusHistoryByRunId.get(runId) || [];
        const statusText = String(element.status || '');
        if (statusText && previousHistory[previousHistory.length - 1] !== statusText) {
            previousHistory.push(statusText);
        }
        this.statusHistoryByRunId.set(runId, previousHistory);

        const isWorking = Boolean(this.reactive.state?.session?.locked)
            && !element.error
            && !element.reviewneeded
            && !element.completed;
        const beforeStatuses = [];
        const afterStatuses = [];

        previousHistory.forEach((text, index) => {
            const isLast = index === previousHistory.length - 1;
            let icon;

            if (isLast && element.reviewneeded) {
                // Estado final de espera de revisión: icono de info, como setStatus(..., isWorking=false).
                icon = '<i class="fa fa-info-circle mr-2 text-info"></i>';
            } else if (isLast && isWorking) {
                // Estado actual mientras se está generando: spinner.
                icon = '<div class="spinner-border spinner-border-sm mr-2 text-primary" role="status"></div>';
            } else {
                // Estados anteriores: check verde.
                icon = '<i class="fa fa-check text-success mr-2"></i>';
            }

            const line = '' +
                '<div class="d-flex align-items-center text-muted my-2 small font-weight-bold text-uppercase tracking-wide">' +
                    '<span>' + icon + '</span>' +
                    '<span>' + safeText(text) + '</span>' +
                '</div>';

            // Cuando el run ya está en modo review, el último estado ("Waiting for your review.")
            // debe ir DESPUÉS del bloque de contenido, como en el legacy (appendAtEnd=true).
            if (isLast && element.reviewneeded) {
                afterStatuses.push(line);
            } else {
                beforeStatuses.push(line);
            }
        });

        const statusBeforeHtml = beforeStatuses.join('');
        const statusAfterHtml = afterStatuses.join('');

        const promptHtml = element.prompt
            ? '' +
                '<div class="d-flex justify-content-end my-3 border-top pt-3 mt-4">' +
                    '<span class="badge badge-light border border-secondary text-muted p-2" ' +
                        'style="font-size: 1rem; line-height: 1.4; font-weight: normal; max-width: 100%; ' +
                        'white-space: normal; word-break: break-word; text-align: left; display: inline-block;">' +
                        '<i class="fa fa-user mr-1"></i> '
                         + safeText(this.texts.activityai_prompt_prefix) + ' ' + safeText(element.prompt) +
                    '</span>' +
                '</div>'
            : '';

        let errorHtml = '';
        if (element.error) {
            const retriable = Boolean(element.retriable);
            if (retriable) {
                errorHtml = '' +
                    '<div class="activityai-retry-alert my-2" data-region="local_coursegen/activity/retry-alert">' +
                        '<div class="activityai-retry-alert-text">' +
                            '<i class="fa fa-exclamation-triangle mr-2" aria-hidden="true"></i>' +
                            '<span>' + safeText(this.texts.activityai_retry_slow_warning) + '</span>' +
                        '</div>' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm mt-2" ' +
                            'data-action="local_coursegen/activity/retry-run" data-run-id="' + safeText(runId) + '">' +
                            safeText(this.texts.activityai_retry_action) +
                        '</button>' +
                    '</div>';
            } else {
                errorHtml = '<div class="alert alert-danger my-2">' + safeText(element.error) + '</div>';
            }
        }

        const markdownHtml = this.markedParser.parse(element.markdown || '');

        wrapper.innerHTML = promptHtml + statusBeforeHtml + markdownHtml + statusAfterHtml + errorHtml;

        const retryButton = wrapper.querySelector('[data-action="local_coursegen/activity/retry-run"]');
        if (retryButton) {
            retryButton.addEventListener('click', () => {
                this.reactive.dispatch('retryRun', {runid: runId});
            });
        }

        if (element.reviewneeded) {
            this._renderReviewActions(output);
        }

        window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
    }

    _renderReviewActions(output) {
        const existing = output.querySelector('.local-coursegen-review-actions');
        if (existing) {
            return;
        }

        const container = document.createElement('div');
        container.className = 'local-coursegen-review-actions mt-4 mb-3 d-flex align-items-center';

        const btnAccept = document.createElement('button');
        btnAccept.type = 'button';
        btnAccept.className = 'btn btn-primary mr-2 shadow-sm';
        btnAccept.textContent = this.texts.accept_planning_create_activity;

        const btnAdjust = document.createElement('button');
        btnAdjust.type = 'button';
        btnAdjust.className = 'btn btn-outline-secondary shadow-sm';
        btnAdjust.textContent = this.texts.adjust_course_planning;

        container.appendChild(btnAccept);
        container.appendChild(btnAdjust);
        output.appendChild(container);

        btnAccept.addEventListener('click', () => {
            this.reactive.dispatch('acceptAndGenerate');
        });

        btnAdjust.addEventListener('click', () => {
            this.reactive.dispatch('requestAdjust');
        });
    }
}
