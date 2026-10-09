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
import Modal from 'oroui/js/modal';


const FRAME_STYLE = [
    'width: 100%',
    'height: 80vh',
    'min-height: 520px',
    'border: 0',
    'display: block',
    'background: #fff'
].join(';');

const DIALOG_STYLE = {
    'width': '92vw',
    'max-width': '1180px'
};


const VIEWER_PARAMS = '#view=FitH&navpanes=0';


function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function buildContent(url, title) {
    return '<div class="invoice-pdf-preview">' +
        '<iframe class="invoice-pdf-preview__frame"' +
        ' src="' + escapeAttribute(url + VIEWER_PARAMS) + '"' +
        ' title="' + escapeAttribute(title) + '"' +
        ' style="' + FRAME_STYLE + '"></iframe>' +
        '</div>';
}

function triggerDownload(url) {
    const link = document.createElement('a');

    link.href = url;
    link.rel = 'noopener';
    link.className = 'no-hash';
    link.setAttribute('data-nohash', 'true');
    // Empty value = let the server's Content-Disposition filename win.
    link.setAttribute('download', '');
    link.style.display = 'none';

    link.addEventListener('click', function(e) {
        e.stopPropagation();
    }, true);

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function openPreview(settings) {
    const title = settings.title || __('softsolutions4u.invoice.actions.preview_pdf.title');

    const modal = new Modal({
        title: title,
        content: buildContent(settings.previewUrl, title),
        okText: settings.downloadText || __('softsolutions4u.invoice.actions.download_pdf.label'),
        cancelText: settings.closeText || __('softsolutions4u.invoice.actions.preview_pdf.close'),
        className: 'modal oro-modal-normal invoice-pdf-preview-modal',
        allowCancel: true,
        okCloses: true
    });

    if (settings.downloadUrl) {
        modal.on('ok', triggerDownload.bind(null, settings.downloadUrl));
    }

    modal.open();

    const $dialog = (modal.$el && modal.$el.length ? modal.$el : $('.invoice-pdf-preview-modal'))
        .find('.modal-dialog');

    $dialog.css(DIALOG_STYLE);

    $dialog.find('.modal-body').css({'padding': '0', 'overflow': 'hidden'});
}

export default function(options) {
    const settings = _.extend({}, options || {});
    const $button = settings._sourceElement;

    if (!$button || !$button.length) {
        return;
    }

    const fromData = {
        previewUrl: $button.data('preview-url'),
        downloadUrl: $button.data('download-url'),
        title: $button.data('title'),
        downloadText: $button.data('download-text'),
        closeText: $button.data('close-text')
    };

    _.each(fromData, function(value, key) {
        if (settings[key] === undefined && value !== undefined) {
            settings[key] = value;
        }
    });

    if (!settings.previewUrl) {
        return;
    }

    $button.on('click', function(e) {
        e.preventDefault();

        if ($(this).is('[disabled]') || $(this).hasClass('disabled')) {
            return false;
        }

        openPreview(settings);

        return false;
    });
}
