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

import BaseComponent from 'oroui/js/app/components/base/component';

export default class LineItemPendingDeleteComponent extends BaseComponent {
    initialize(options) {
        this.options = Object.assign({}, this.options, options || {});
        this.$el = this.options._sourceElement;
        this.pendingIds = [];
        this.$hiddenField = document.querySelector('[name="invoice[lineItemIdsToDelete]"]');

        this.$el.on('click.pendingDelete', '.pending-remove-line-item', this.onRemoveClick.bind(this));

        super.initialize(this.options);
    }

    onRemoveClick(e) {
        e.preventDefault();
        const $link = e.currentTarget;
        const id = $link.getAttribute('data-line-item-id');
        const $row = $link.closest('tr');

        if (!this.pendingIds.includes(id)) {
            this.pendingIds.push(id);
        }

        if ($row) {
            $row.style.textDecoration = 'line-through';
            $row.style.opacity = '0.4';
            $link.style.pointerEvents = 'none';
        }

        if (this.$hiddenField) {
            this.$hiddenField.value = this.pendingIds.join(',');
        }
    }

    dispose() {
        if (this.disposed) {
            return;
        }
        this.$el.off('click.pendingDelete', '.pending-remove-line-item');
        super.dispose();
    }
}
