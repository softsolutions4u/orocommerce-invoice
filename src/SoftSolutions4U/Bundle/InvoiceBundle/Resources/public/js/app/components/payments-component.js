/*
 * This file is part of the SoftSolutions4U OroCommerce Invoice bundle.
 *
 * @category  SoftSolutions4U
 * @package   SoftSolutions4U\Bundle\InvoiceBundle
 * @author    Pradeep Elayaraja
 * @author    Ganesh
 * @copyright 2026 SoftSolutions4U
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-only
 * @link      https://www.softsolutions4u.com/
 */

import $ from 'jquery';
import _ from 'underscore';
import routing from 'routing';
import mediator from 'oroui/js/mediator';
import __ from 'orotranslation/js/translator';
import BaseComponent from 'oroui/js/app/components/base/component';
import PaymentsFormView from 'softsolutions4uinvoice/js/app/views/payments-form-view';

const PaymentsComponent = BaseComponent.extend({
    /**
     * @property {Object}
     */
    options: {
        saveStateRoute: null,
    },

    /**
     * @inheritDoc
     */
    constructor: function PaymentsComponent(options) {
        PaymentsComponent.__super__.constructor.call(this, options);
    },

    /**
     * @inheritDoc
     */
    initialize: function(options) {
        this.options = _.extend({}, this.options, options || {});

        this.$form = this.options._sourceElement.find('form');
        this.formView = new PaymentsFormView({
            el: this.$form
        });

        this.formView.on('submit-form', _.bind(this.onSubmit, this));

        this.formView.on('after-check-form', _.bind(this.onAfterCheckForm, this));

        PaymentsComponent.__super__.initialize.call(this, this.options);

        /**
         * Fire the OnChange event so we trigger a SaveState, which will assign the
         * currently selected Payment Method to the InvoicePayment,
         * and trigger an accurate Recalculation of the Subtotals (which can apply
         * processing fees etc if configured)
         */
        this.$form.trigger('change');
    },

    /**
     * @param {string} serializedData
     * @param {jQuery} $field
     */
    onAfterCheckForm: function(serializedData, $field) {
        let data = this.$form.serialize();

        let paymentId = this.$form.find('[name$="[id]').val();
        $.ajax({
            url: routing.generate(this.options.saveStateRoute, {id: paymentId}),
            method: 'POST',
            data: data
        })
        .done(function() {
            mediator.trigger('softsolutions4u:invoice:payment:changed');
        })
        .fail(_.bind(function(data) {
            mediator.execute('hideLoading');
            let errorMessage = '';

            if (data && data.responseJSON && data.responseJSON.message) {
                errorMessage = __(String(data.responseJSON.message)).trim();
            }

            // Never raise an empty flash message: fall back to the generic text.
            if (!errorMessage) {
                errorMessage = __('softsolutions4u.invoice.frontend.payment.save_state_failed.message');
            }

            this.showFlash('error', errorMessage);
        }, this));
    },

    /**
     * Shows a flash message and removes it after a few seconds.
     *
     * Oro leaves storefront flash messages on screen until dismissed, so the
     * timer is scheduled here rather than relying on the message container.
     */
    showFlash: function(type, message) {
        mediator.execute('showFlashMessage', type, message);

        _.delay(function() {
            $('#flash-messages .notification-flash').each(function() {
                const $message = $(this);

                if ($message.data('commerceAutoHide')) {
                    return;
                }

                $message.data('commerceAutoHide', true);

                _.delay(function() {
                    $message.fadeOut(200, function() {
                        $(this).remove();
                    });
                }, 5000);
            });
        }, 50);
    },

    onSubmit: function(data) {
        mediator.execute('showLoading');

        var url = this.formView.$el.prop('action');

        $.ajax({
            url: url,
            method: 'POST',
            data: data
        })
            .done(this.onSuccess.bind(this))
            .fail(_.bind(function() {
                mediator.execute('hideLoading');
                this.showFlash('error', __('softsolutions4u.invoice.frontend.payment.action_failed.message'));
            }, this));
    },

    onSuccess: function(response) {
        if (response.hasOwnProperty('responseData')) {
            var eventData = {stopped: false, responseData: response.responseData};
            mediator.trigger('checkout:place-order:response', eventData);
            if (eventData.stopped) {
                return;
            }
        }

        if (response.hasOwnProperty('redirectUrl')) {
            mediator.execute('redirectTo', {url: response.redirectUrl}, {redirect: true});
        } else if (response.hasOwnProperty('errors')) {
            // Display form validation errors as a flash message
            var errorMessage = '';

            _.each(
                response.errors,
                function(message) {
                    errorMessage += message + '</br>';
                }
            );

            _.delay(_.bind(function() {
                this.showFlash('error', errorMessage);
            }, this), 100);
        } else {
            var message = __('softsolutions4u.invoice.frontend.payment.processing_failed.message');

            _.delay(_.bind(function() {
                this.showFlash('error', message);
            }, this), 100);
        }

        mediator.execute('hideLoading');
    }
});

export default PaymentsComponent;
