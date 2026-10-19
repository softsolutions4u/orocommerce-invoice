# Changelog

All notable changes to `softsolutions4u/orocommerce-invoice` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-15

First public release for OroCommerce Community Edition 7.0.3 (PHP 8.5, Symfony 7.4).

### Added

#### Backoffice

- Invoice list under **Sales → Invoices** with invoice status and payment status filters.
- Create, view and edit invoices with line items, taxes, discounts, shipping and totals.
- Deletion of draft invoices that have no recorded payments.
- **Create Invoice** button and **Invoices** section on the order view and edit pages, added through `oro_ui.scroll_data.before.*` events without overriding OroCommerce templates.
- Invoice lifecycle: Draft → Posted → Open → Partially Paid / Overdue → Paid, plus Cancelled.
- Post, cancel, payment reminder and payment confirmation actions.
- PDF preview and download (Dompdf) with company name, logo and VAT number.
- Automatic application of order payments to the order's invoices.
- Optional automatic invoice creation for Payment Term orders.
- Offline payment confirmation: storefront payments made with Payment Term or Money Order are stored as awaiting confirmation until an administrator uses **Confirm Payment**. The method list is configurable through the `softsolutions4u_invoice.offline_payment_method_prefixes` parameter.
- Automatic Overdue status after the due date, via an Oro process triggered on invoice changes and hourly by cron.
- Sequential invoice numbers backed by a dedicated sequence table.
- Role-based ACL permissions for every action (category Invoice).

#### Storefront

- **My Account → Invoices** with filtering and sorting.
- Invoice detail page and PDF download.
- Online payment of the full balance or a partial amount.
- Bank-transfer details (US account for USD, SEPA account for EUR) with a unique payment reference per invoice.

#### Configuration

- **System → Configuration → Commerce → Orders → Invoice Management**, configurable: feature toggle, allowed payment methods, seller details, bank accounts and auto-creation for Payment Term orders.

#### Emails and translations

- Email templates for new invoice, payment reminder, payment confirmation and invoice cancelled.
- English and German translations for the UI and email templates.

#### Database

- Schema migrations `v1_0` (invoice, line item, payment and payment line item tables), `v1_1` (payment confirmation flag and payment transaction foreign key) and `v1_2` (invoice-number sequence table), plus an installer for fresh installs.

#### Development

- Demo data fixtures: six sample invoices across two customers and fictional seller and bank details.
- PHPUnit unit test suite and PSR-12 coding standard configuration.

## Troubleshooting

After installing the bundle, complete the following steps to check that the installation was successful.

### 1. Run the database migration

The bundle's database tables must exist before you access the invoice features or load the demo data fixtures.

```bash
php bin/console oro:platform:update --force
```

### 2. Clear the cache

Clear the application cache so the bundle's routes, configuration and translations are loaded:

```bash
php bin/console cache:clear
```

In production, add `--env=prod`.

### 3. Check the database tables

Check that the bundle's tables have been created:

```bash
psql -d <databasename> -c '\dt *softsolutions4u_invoice*'
```

You can also check directly in the database that all the tables are present.

Five tables should be listed: `softsolutions4u_invoice`, `softsolutions4u_invoice_line_item`, `softsolutions4u_invoice_payment`, `softsolutions4u_invoice_payment_line_item` and `softsolutions4u_invoice_sequence`.

### 4. Check the Invoices menu and configuration

Log in to the OroCommerce back-office and go to **Sales → Invoices**. The page should load without errors.

The configuration is under **System → Configuration → Commerce → Orders → Invoice Management**.

[Unreleased]: https://github.com/softsolutions4u/orocommerce-invoice/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/softsolutions4u/orocommerce-invoice/releases/tag/v1.0.0
