import notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {prefetchStrings} from 'core/prefetch';
import {watchForm, resetFormDirtyState, markFormAsDirty} from 'core_form/changechecker';

import {imageGenerationRegions} from 'local_coursegen/selectors';
import {saveImageGenerationSettings} from 'local_coursegen/repository/image_generation';

export const init = () => {
    const form = document.querySelector(imageGenerationRegions.form);
    if (!form) {
        return;
    }

    watchForm(form);

    prefetchStrings('local_coursegen', [
        'settings_saved',
        'settings_error',
    ]);

    const modeCards = form.querySelectorAll(imageGenerationRegions.modeCard);
    const manualSettings = form.querySelector(imageGenerationRegions.manualSettings);
    const inputMode = form.querySelector(imageGenerationRegions.inputMode);
    const toggleSwitches = form.querySelectorAll(imageGenerationRegions.toggleActivity);
    const submitBtn = form.querySelector(imageGenerationRegions.saveButton);
    if (!submitBtn) {
        return;
    }

    // Handle mode card clicks.
    modeCards.forEach((card) => {
        card.addEventListener('click', (e) => {
            const selectedMode = e.currentTarget.dataset.mode;

            // Reset card styles and radio state.
            modeCards.forEach((currentCard) => {
                currentCard.classList.remove('border-primary', 'border-danger', 'bg-light');
                const radio = currentCard.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = false;
                }
            });

            // Activate selected card.
            if (selectedMode === 'disabled') {
                e.currentTarget.classList.add('border-danger', 'bg-light');
            } else {
                e.currentTarget.classList.add('border-primary', 'bg-light');
            }

            const activeRadio = e.currentTarget.querySelector('input[type="radio"]');
            if (activeRadio) {
                activeRadio.checked = true;
            }

            // Keep hidden input in sync.
            if (inputMode && inputMode.value !== selectedMode) {
                inputMode.value = selectedMode;
                markFormAsDirty(form);
            }

            // Toggle manual settings.
            if (manualSettings) {
                if (selectedMode === 'manual') {
                    manualSettings.classList.remove('d-none');
                } else {
                    manualSettings.classList.add('d-none');
                }
            }
        });
    });

    // Handle activity switches.
    toggleSwitches.forEach((toggle) => {
        toggle.addEventListener('change', (e) => {
            const targetId = e.target.dataset.target;
            const contentDiv = form.querySelector(
                `${imageGenerationRegions.activityContent}[data-content="${targetId}"]`
            );

            if (contentDiv) {
                if (e.target.checked) {
                    contentDiv.classList.add('show');
                } else {
                    contentDiv.classList.remove('show');
                }
            }
        });
    });

    // Submit handler.
    form.addEventListener('submit', async(e) => {
        e.preventDefault();

        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

        try {
            const formData = new FormData(form);
            const payload = {
                overridecourse: formData.get('overridecourse') ? 1 : 0,
                overrideactivity: formData.get('overrideactivity') ? 1 : 0,
                generationmode: String(formData.get('generationmode') || ''),
                enableimgbook: formData.get('enableimgbook') ? 1 : 0,
                promptimgbook: String(formData.get('promptimgbook') || ''),
                enableimgquiz: formData.get('enableimgquiz') ? 1 : 0,
                promptimgquiz: String(formData.get('promptimgquiz') || ''),
            };

            const response = await saveImageGenerationSettings(payload);
            if (response.success) {
                resetFormDirtyState(form);
                const savedStr = await getString('settings_saved', 'local_coursegen');
                notification.addNotification({message: savedStr, type: 'success'});
            }
        } catch (error) {
            notification.exception(error);

            const errorStr = await getString('settings_error', 'local_coursegen');
            notification.addNotification({message: errorStr, type: 'error'});

        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    });
};
