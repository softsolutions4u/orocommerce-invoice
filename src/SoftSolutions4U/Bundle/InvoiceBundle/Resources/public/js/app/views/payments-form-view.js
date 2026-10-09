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
import BaseView from 'oroui/js/app/views/base/view';

const PaymentsFormView = BaseView.extend({
    /**
     * @property {Object}
     */
    options: {
        formPaymentMethodSelector: '[name$="[payment_method]"]',
        originPaymentMethodSelector: '[name="paymentMethod"]'
    },

    /**
     * @inheritDoc
     */
    events: {
        change: 'onChange',
        submit: 'onSubmit'
    },

    /**
     * @inheritDoc
     */
    listen: {

    },

    /**
     * @property {number}
     */
    timeout: 50,

    /**
     * @property {string}
     */
    lastComparableData: null,

    /**
     * @inheritDoc
     */
    constructor: function PaymentsFormView() {
        this.onChange = _.debounce(this.onChange, this.timeout);
        PaymentsFormView.__super__.constructor.apply(this, arguments);
    },

    initialize: function (options) {
        this.options = _.extend({}, this.options, options || {});

        this._changePaymentMethod();

        PaymentsFormView.__super__.initialize.call(this, arguments);
    },

    onChange: function(event) {
        // Do not execute logic when hidden element (form) is refreshed
        if (!$(event.target).is(':visible')) {
            return;
        }

        this._changePaymentMethod();
        this.afterCheck($(event.target), false);
    },

    afterCheck: function($el, force) {
        if (!$el) {
            $el = this.$el;
        }
        const comparableData = this._getComparableData();

        if (this.lastComparableData === comparableData && !force) {
            return;
        }

        this.trigger('after-check-form', this.getSerializedData(), $el);
        this.lastComparableData = comparableData;
    },

    onSubmit: function (event) {
        event.preventDefault();

        var validate = this.$el.validate();
        if (!validate.form()) {
            return;
        }

        var paymentMethod = this.$el.find(this.options.formPaymentMethodSelector).val();
        var eventData = {
            stopped: false,
            resume: _.bind(this.transit, this),
            data: {paymentMethod: paymentMethod}
        };

        mediator.trigger('checkout:payment:before-transit', eventData);

        if (eventData.stopped) {
            return;
        }

        this.transit();
    },

    transit: function() {
        this._changePaymentMethod();

        var paymentMethod = this.$el.find(this.options.formPaymentMethodSelector).val();
        var eventData = {paymentMethod: paymentMethod};
        mediator.trigger('checkout:payment:before-form-serialization', eventData);

        this.trigger('submit-form', this.getSerializedData());
    },

    getSerializedData: function() {
        var $form = this.$el.closest('form');
        return $form.serialize();
    },

    /**
     * Like getSerializedData(), but excludes the CSRF token field.
     *
     * The token is regenerated fresh every time the containing subtree
     * reloads (see __payment_subtree_update's reloadEvents), so a
     * dedup check based on the full serialized string never matches the
     * previous value even when nothing the user actually controls has
     * changed. That defeated the check in afterCheck() and caused an
     * infinite save -> reload -> new token -> save -> ... loop. This is
     * used only for that comparison - the real submission still uses
     * getSerializedData() (with the token) via transit()/onSubmit.
     */
    _getComparableData: function() {
        var $form = this.$el.closest('form');
        return $form.find(':input').not('[name$="[_token]"]').serialize();
    },

    _changePaymentMethod: function() {
        var $selectedMethodVal = this.$el.find(this.options.originPaymentMethodSelector).filter(':checked').val();

        if (!$selectedMethodVal) {
            return;
        }

        this.$el.find(this.options.formPaymentMethodSelector).val($selectedMethodVal);
    }
});

export default PaymentsFormView;
