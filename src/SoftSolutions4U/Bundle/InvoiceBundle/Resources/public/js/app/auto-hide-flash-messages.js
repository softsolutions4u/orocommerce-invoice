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

const HIDE_AFTER_MS = 5000;
const FLASH_MESSAGE_SELECTOR = '.messages .alert, [role="alert"].alert, .flash-messages .alert,' +
    ' #flash-messages .notification-flash';

function scheduleHide(el) {
    if (el.dataset.commerceAutoHideScheduled) {
        return;
    }

    el.dataset.commerceAutoHideScheduled = 'true';

    setTimeout(() => {
        $(el).fadeOut(200, function() {
            $(this).remove();
        });
    }, HIDE_AFTER_MS);
}

function hideExisting() {
    document.querySelectorAll(FLASH_MESSAGE_SELECTOR).forEach(scheduleHide);
}

hideExisting();

if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches(FLASH_MESSAGE_SELECTOR)) {
                    scheduleHide(node);
                }

                node.querySelectorAll?.(FLASH_MESSAGE_SELECTOR).forEach(scheduleHide);
            });
        });
    });

    observer.observe(document.body, {childList: true, subtree: true});
}
