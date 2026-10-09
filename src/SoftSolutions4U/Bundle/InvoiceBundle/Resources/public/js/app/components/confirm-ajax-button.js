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
import __ from 'orotranslation/js/translator';
import mediator from 'oroui/js/mediator';
import Modal from 'oroui/js/modal';

const options = {};

function onClick(e) {
    e.preventDefault();

    const $el = $(e.currentTarget);

    if ($el.is('[disabled]') || $el.hasClass('disabled')) {
        return false;
    }

    const method = $el.data('method') || 'POST';
    const url = $el.data('url');
    const redirect = $el.data('redirect');
    const successMessage = $el.data('success-message');
    const errorMessage = $el.data('error-message');
    const message = $el.data('message') || __('softsolutions4u.invoice.actions.confirmation.message');

    const confirm = new Modal({
        title: $el.data('title') || __('softsolutions4u.invoice.actions.confirmation.title'),
        content: message,
        okText: $el.data('ok-text') || __('softsolutions4u.invoice.actions.proceed.label'),
        cancelText: $el.data('cancel-text') || __('softsolutions4u.invoice.actions.cancel.label')
    });

    confirm.on('ok', function() {
        mediator.execute('showLoading');

        $.ajax({
            url: url,
            type: method,
            success: function(data) {
                mediator.execute(
                    'showFlashMessage',
                    'success',
                    data && data.message ? data.message : __(successMessage)
                );

                if (redirect) {
                    mediator.execute('redirectTo', {url: redirect}, {redirect: true});
                }
            },
            errorHandlerMessage: function(event, response) {
                try {
                    const responseText = JSON.parse(response.responseText);
                    return responseText.message ? responseText.message : __(errorMessage);
                } catch (e) {
                    return __(errorMessage);
                }
            },
            complete: function() {
                mediator.execute('hideLoading');
            }
        });
    });

    confirm.open();

    return false;
}

export default function(additionalOptions) {
    _.extend(options, additionalOptions || {});
    const button = options._sourceElement;
    button.on('click', onClick);
};
