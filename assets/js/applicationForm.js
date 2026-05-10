(function (Drupal, once) {
    'use strict';

    Drupal.behaviors.esnMembershipManagerDisableSubmit = {
        attach: function (context) {
            const forms = once('esn-membership-manager-disable-submit', '.esn-membership-manager-form', context);

            forms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    if (!form.checkValidity || form.checkValidity()) {
                        const submitButtons = form.querySelectorAll('input[type="submit"], button[type="submit"]');

                        submitButtons.forEach(function (btn) {
                            setTimeout(function () {
                                btn.classList.add('is-disabled');
                                btn.style.pointerEvents = 'none';
                                btn.style.opacity = '0.5';

                                if (btn.tagName.toLowerCase() === 'input') {
                                    btn.value = Drupal.t('Submitting...');
                                } else {
                                    btn.textContent = Drupal.t('Submitting...');
                                }
                            }, 10);
                        });
                    }
                });
            });
        }
    };
})(Drupal, once);
