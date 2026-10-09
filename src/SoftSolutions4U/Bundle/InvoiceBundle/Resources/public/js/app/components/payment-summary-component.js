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
import mediator from 'oroui/js/mediator';
import BaseComponent from 'oroui/js/app/components/base/component';
import NumberFormatter from 'orolocale/js/formatter/number';

const PaymentSummaryComponent = BaseComponent.extend({
    /**
     * @property {Object}
     */
    options: {
        currency: 'USD',
        grandTotal: 0,
        amountPaid: 0,
        amountSelector: '.invoice-payment-form__amount-input, [name*="payment_amount"]',
    },

    /**
     * @inheritDoc
     */
    constructor: function PaymentSummaryComponent(options) {
        PaymentSummaryComponent.__super__.constructor.call(this, options);
    },

    /**
     * @inheritDoc
     */
    initialize: function(options) {
        this.options = _.extend({}, this.options, options || {});

        this.$el = this.options._sourceElement;
        this.namespace = '.paymentSummary' + this.cid;

        this.lastAmount = parseFloat(this.options.paymentAmount) || 0;
        this.onAmountChange = _.bind(this.render, this);

        // The amount input lives in __form_fields, which sits inside the
        // layout subtree that reloads after every save. A direct binding is
        // lost the moment that subtree re-renders, so delegate from document.
        $(document).on(
            'input' + this.namespace + ' change' + this.namespace +
            ' keyup' + this.namespace + ' blur' + this.namespace + ' paste' + this.namespace,
            this.options.amountSelector,
            this.onAmountChange
        );

        this.listenTo(mediator, 'softsolutions4u:invoice:payment:changed', this.onAmountChange);
        this.startFlashAutoHide();
        this.render();

        PaymentSummaryComponent.__super__.initialize.call(this, options);
    },

    /**
     * Returns the amount currently entered.
     *
     * While the layout reloads the form subtree the input is briefly absent
     * from the DOM. Falling back to zero there would blank the payment amount
     * mid-reload, so the last known value is kept until the input returns.
     */
    getEnteredAmount: function() {
        const $input = $(this.options.amountSelector).filter(':visible').last();

        if (!$input.length) {
            return this.lastAmount;
        }

        const raw = $.trim($input.val());

        // An input the user has actually cleared should read as zero.
        if (raw === '') {
            this.lastAmount = 0;

            return 0;
        }

        const value = parseFloat(raw);

        if (isNaN(value) || value < 0) {
            return this.lastAmount;
        }

        this.lastAmount = value;

        return value;
    },

    /**
     * Recomputes the summary from the amount currently entered.
     */
    render: function() {
        const grandTotal = parseFloat(this.options.grandTotal) || 0;
        const amountPaid = parseFloat(this.options.amountPaid) || 0;
        const balance = this.round(grandTotal - amountPaid);
        const payment = this.getEnteredAmount();

        // Never show a negative outstanding figure when more than the balance is typed.
        const pending = Math.max(this.round(balance - payment), 0);

        this.setValue('payment-amount', payment);
        this.setValue('amount-paid', amountPaid);
        this.setValue('pending-amount', pending);
        this.setValue('grand-total', grandTotal);

        this.$el.toggleClass('invoice-payment-summary--exceeds-balance', this.round(payment) > balance);
    },

    /**
     * Writes a formatted currency value into the row with the given role.
     */
    setValue: function(role, value) {
        const $target = this.$el.find('[data-role="' + role + '"]');

        if (!$target.length) {
            return;
        }

        let text;

        try {
            text = NumberFormatter.formatCurrency(value, this.options.currency);
        } catch (e) {
            text = this.options.currency + ' ' + value.toFixed(2);
        }

        $target.text(text);
    },

    /**
     * Rounds to cents, avoiding floating point drift.
     */
    round: function(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    },

    /**
     * @inheritDoc
     */
    dispose: function() {
        if (this.disposed) {
            return;
        }

        $(document).off(this.namespace);

        if (this.flashObserver) {
            this.flashObserver.disconnect();
        }

        PaymentSummaryComponent.__super__.dispose.call(this);
    },

    startFlashAutoHide: function() {
        const HIDE_AFTER_MS = 5000;
        const SELECTOR = '#flash-messages .notification-flash, .flash-messages .alert, [role="alert"].alert';

        const scheduleHide = function(el) {
            if (el.dataset.commerceAutoHideScheduled) {
                return;
            }

            el.dataset.commerceAutoHideScheduled = 'true';

            setTimeout(function() {
                $(el).fadeOut(200, function() {
                    $(this).remove();
                });
            }, HIDE_AFTER_MS);
        };

        document.querySelectorAll(SELECTOR).forEach(scheduleHide);

        if (typeof MutationObserver === 'undefined') {
            return;
        }

        this.flashObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (!(node instanceof HTMLElement)) {
                        return;
                    }

                    if (node.matches(SELECTOR)) {
                        scheduleHide(node);
                    }

                    if (node.querySelectorAll) {
                        node.querySelectorAll(SELECTOR).forEach(scheduleHide);
                    }
                });
            });
        });

        this.flashObserver.observe(document.body, {childList: true, subtree: true});
    },
});

export default PaymentSummaryComponent;
