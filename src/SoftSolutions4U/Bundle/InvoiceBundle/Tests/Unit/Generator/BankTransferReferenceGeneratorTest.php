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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Generator;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\BankTransferReferenceGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the bank transfer payment reference.
 */
class BankTransferReferenceGeneratorTest extends TestCase
{
    /** @var BankTransferReferenceGenerator $generator */
    private BankTransferReferenceGenerator $generator;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->generator = new BankTransferReferenceGenerator();
    }

    /**
     * Tests generates the reference.
     *
     * @param int $invoiceId
     * @param string $expectedReference
     * @dataProvider referencesDataProvider
     */
    #[DataProvider('referencesDataProvider')]
    public function testGeneratesTheReference(int $invoiceId, string $expectedReference): void
    {
        self::assertSame($expectedReference, $this->generator->generateForId($invoiceId));
    }

    /**
     * Provides the data sets for references data.
     *
     * @return \Generator<string, array{invoiceId: int, expectedReference: string}>
     */
    public static function referencesDataProvider(): \Generator
    {
        yield 'first invoice' => ['invoiceId' => 1, 'expectedReference' => 'BT-0000001-95'];
        yield 'invoice 19' => ['invoiceId' => 19, 'expectedReference' => 'BT-0000019-41'];
        yield 'id equal to the modulus' => ['invoiceId' => 97, 'expectedReference' => 'BT-0000097-98'];
        yield 'seven-digit id' => ['invoiceId' => 1234567, 'expectedReference' => 'BT-1234567-51'];
        yield 'ten-digit id grows, never truncated' => [
            'invoiceId' => 1234567890,
            'expectedReference' => 'BT-1234567890-92',
        ];
    }

    /**
     * Tests that the reference is not the invoice number.
     */
    public function testReferenceIsSeparateFromTheInvoiceNumber(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        self::setId($invoice, 19);

        $reference = $this->generator->generate($invoice);

        self::assertSame('BT-0000019-41', $reference);
        self::assertNotSame($invoice->getInvoiceNo(), $reference);
    }

    /**
     * Tests that an unsaved invoice has no reference yet.
     */
    public function testUnsavedInvoiceHasNoReference(): void
    {
        self::assertNull($this->generator->generate(new Invoice()));
    }

    /**
     * Tests that ids that cannot identify an invoice, or cannot be read back safely, are rejected.
     */
    public function testRejectsNonPositiveIds(): void
    {
        try {
            $this->generator->generateForId(PHP_INT_MAX); // 19 digits: beyond MAX_ID_LENGTH
            self::fail('A 19-digit id must be refused');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generateForId(0);
    }

    /**
     * Tests that every generated reference parses back to its invoice id, however long the id is.
     */
    public function testRoundTrip(): void
    {
        $ids = [1, 2, 19, 20, 96, 97, 98, 500, 99999, 1234567,
            9999999,            // last 7-digit id
            10000000,           // first 8-digit id: previously unreadable
            1234567890,         // 10 digits
            999999999999999999, // 18 digits: the maximum
        ];

        foreach ($ids as $id) {
            $reference = $this->generator->generateForId($id);

            self::assertSame($id, $this->generator->parseInvoiceId($reference), $reference);
            // Leaving out the hyphens must not confuse the id with the check digits.
            self::assertSame($id, $this->generator->parseInvoiceId(str_replace('-', '', $reference)), $reference);
        }

        // Existing references are unchanged by the longer-id support.
        self::assertSame('BT-0000020-38', $this->generator->generateForId(20));
    }

    /**
     * Tests tolerates how customers type the reference.
     *
     * @param string $typed
     * @dataProvider customerTypedReferencesDataProvider
     */
    #[DataProvider('customerTypedReferencesDataProvider')]
    public function testToleratesHowCustomersTypeTheReference(string $typed): void
    {
        self::assertSame(19, $this->generator->parseInvoiceId($typed));
    }

    /**
     * Provides the data sets for customer typed references data.
     *
     * @return \Generator<string, array{typed: string}>
     */
    public static function customerTypedReferencesDataProvider(): \Generator
    {
        yield 'exact' => ['typed' => 'BT-0000019-41'];
        yield 'lower case with spaces' => ['typed' => '  bt-0000019-41 '];
        yield 'spaces instead of hyphens' => ['typed' => 'BT 0000019 41'];
        yield 'slashes' => ['typed' => 'BT/0000019/41'];
        yield 'no separators' => ['typed' => 'BT000001941'];
        yield 'without leading zeros' => ['typed' => 'BT-19-41'];
    }

    /**
     * Tests rejects mistyped references.
     *
     * @param string $typed
     * @dataProvider invalidReferencesDataProvider
     */
    #[DataProvider('invalidReferencesDataProvider')]
    public function testRejectsMistypedReferences(string $typed): void
    {
        self::assertNull($this->generator->parseInvoiceId($typed));
        self::assertFalse($this->generator->isValid($typed));
    }

    /**
     * Provides the data sets for invalid references data.
     *
     * @return \Generator<string, array{typed: string}>
     */
    public static function invalidReferencesDataProvider(): \Generator
    {
        yield 'wrong check digits' => ['typed' => 'BT-0000019-42'];
        yield 'transposed digits' => ['typed' => 'BT-0000091-41'];
        yield 'one digit wrong' => ['typed' => 'BT-0000018-41'];
        yield 'invoice number instead' => ['typed' => 'INV-2026-09-00019'];
        yield 'missing check digits' => ['typed' => 'BT-0000019'];
        yield 'wrong prefix' => ['typed' => 'XX-0000019-41'];
        yield 'zero id' => ['typed' => 'BT-0000000-98'];
        yield 'empty' => ['typed' => ''];
        yield 'mistyped ten-digit id' => ['typed' => 'BT-1234567809-92'];
        yield 'id longer than 18 digits' => ['typed' => 'BT-1234567890123456789-00'];
    }

    /**
     * Sets the id.
     *
     * @param object $entity
     * @param int|null $id
     */
    private static function setId(object $entity, ?int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
