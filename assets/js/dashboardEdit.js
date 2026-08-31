document.addEventListener("DOMContentLoaded", () => {
    const editButtons = document.querySelectorAll(".panel-button-text");

    editButtons.forEach(button => {
        button.addEventListener("click", (event) => {
            event.preventDefault();

            const targetGrid = button.closest('.panel').querySelector('.info-grid');
            const inputs = targetGrid.querySelectorAll('.info-value');
            const state = button.innerHTML.toLowerCase();

            if (state === "edit") {
                inputs.forEach(input => {
                    input.removeAttribute("readonly");
                    input.removeAttribute("tabindex");

                    input.dataset.originalValue = input.value;
                });

                if (inputs.length > 0) inputs[0].focus();

                button.innerHTML = "Save";

            } else if (state === "save") {
                let hasChanges = false;
                inputs.forEach(input => {
                    if (input.value !== input.dataset.originalValue) {
                        hasChanges = true;
                    }
                });

                if (hasChanges) {
                    button.innerHTML = "Saving...";

                    const submitId = button.getAttribute("data-submit-id");
                    if (submitId) {
                        const hiddenSubmit = document.getElementById(submitId);
                        if (hiddenSubmit) {
                            hiddenSubmit.click();
                        }
                    }
                } else {
                    inputs.forEach(input => {
                        input.setAttribute("readonly", "readonly");
                        if (input.tagName.toLowerCase() === "select") {
                            input.setAttribute("tabindex", "-1");
                        }
                    });
                    button.innerHTML = "Edit";
                }
            }
        });
    });
});

(function (Drupal, once) {
    "use strict";

    Drupal.behaviors.autoSubmitManagedFile = {
        attach: function (context, settings) {
            const wrappers = once('auto-submit-watcher', '.auto-submit-file', context);

            wrappers.forEach(wrapper => {
                const removeButton = wrapper.querySelector('input[name*="remove_button"]');

                if (removeButton) {
                    const targetButtonId = (wrapper.id + '-submit').replace(/--[A-Za-z0-9]*?-/gm, "-");

                    const targetButton = document.getElementById(targetButtonId);

                    if (targetButton) {
                        wrapper.style.opacity = '0.5';
                        wrapper.style.pointerEvents = 'none';

                        targetButton.click();
                    }
                }
            });
        }
    };

})(Drupal, once);