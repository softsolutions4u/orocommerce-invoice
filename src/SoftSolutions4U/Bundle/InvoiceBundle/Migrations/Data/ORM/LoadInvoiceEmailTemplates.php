<?php

/**
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

declare(strict_types=1);

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\ORM;

use Oro\Bundle\EmailBundle\Migrations\Data\ORM\AbstractHashEmailMigration;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;

/**
 * Loads the "softsolutions4u_invoice_notification" email template used when an invoice is posted and.
 */
class LoadInvoiceEmailTemplates extends AbstractHashEmailMigration implements VersionedFixtureInterface
{
    /**
     * Returns the emails dir.
     *
     * @return string
     */
    #[\Override]
    public function getEmailsDir(): string
    {
        return $this->container
            ->get('kernel')
            ->locateResource('@SoftSolutions4UInvoiceBundle/Migrations/Data/ORM/data/emails/invoice');
    }

    /**
     * Returns the fixture version.
     *
     * @return string
     */
    #[\Override]
    public function getVersion(): string
    {
        return '1.2';
    }

    /**
     * Returns the email hashes to update.
     *
     * @return array
     */
    #[\Override]
    protected function getEmailHashesToUpdate(): array
    {
        return [

            'softsolutions4u_invoice_notification' => true,
            'softsolutions4u_invoice_paid_notification' => true,
            'softsolutions4u_invoice_reminder_notification' => true,
            'softsolutions4u_invoice_cancelled_notification' => true,
        ];
    }
}
