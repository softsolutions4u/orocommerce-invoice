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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Migrations\Data\Demo\ORM;

use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\Demo\ORM\LoadInvoiceDemoConfiguration;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Unit tests for the demo Invoice Management configuration fixture.
 */
class LoadInvoiceDemoConfigurationTest extends TestCase
{
    /** @var ConfigManager&MockObject $configManager */
    private ConfigManager&MockObject $configManager;

    /** @var FileManager&MockObject $fileManager */
    private FileManager&MockObject $fileManager;

    /** @var LoadInvoiceDemoConfiguration $fixture */
    private LoadInvoiceDemoConfiguration $fixture;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->fileManager = $this->createMock(FileManager::class);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('locateResource')
            ->with(LoadInvoiceDemoConfiguration::LOGO_PATH)
            ->willReturn('/bundle/Resources/public/images/softsolutions4u-logo.png');

        $container = $this->createMock(ContainerInterface::class);
        $services = [
            'oro_config.global' => $this->configManager,
            'oro_attachment.file_manager' => $this->fileManager,
            'kernel' => $kernel,
        ];
        $container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

        $this->fixture = new LoadInvoiceDemoConfiguration();
        $this->fixture->setContainer($container);
    }

    /**
     * Tests that every empty setting is filled with a fictional demo value.
     */
    public function testFillsEmptySettings(): void
    {
        $this->configManager->method('get')->willReturn(null);

        $written = [];
        $this->configManager->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$written): void {
                $written[$key] = $value;
            }
        );
        $this->configManager->expects(self::once())->method('flush');

        $logo = $this->createMock(File::class);
        $logo->method('getId')->willReturn(42);
        $this->fileManager->expects(self::once())
            ->method('createFileEntity')
            ->with('/bundle/Resources/public/images/softsolutions4u-logo.png')
            ->willReturn($logo);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($logo);
        $manager->expects(self::once())->method('flush');

        $this->fixture->load($manager);

        self::assertCount(12, $written);
        self::assertSame(42, $written['softsolutions4u_invoice.company_logo']);
        self::assertSame('Example Seller Inc.', $written['softsolutions4u_invoice.company_name']);
        self::assertSame('US123456789', $written['softsolutions4u_invoice.company_vat_number']);
        self::assertSame('DE00123456780000000000', $written['softsolutions4u_invoice.bank_de_iban']);

        foreach ($written as $key => $value) {
            self::assertStringStartsWith('softsolutions4u_invoice.', $key);
            self::assertNotSame('', $value);
            self::assertNotNull($value);
        }
    }

    /**
     * Tests that settings which already have a value are not overwritten.
     */
    public function testKeepsExistingValues(): void
    {
        $this->configManager->method('get')->willReturnCallback(
            static fn (string $key): ?string => 'softsolutions4u_invoice.company_name' === $key
                ? 'Real Company Ltd'
                : null
        );

        $written = [];
        $this->configManager->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$written): void {
                $written[$key] = $value;
            }
        );

        $logo = $this->createMock(File::class);
        $logo->method('getId')->willReturn(7);
        $this->fileManager->method('createFileEntity')->willReturn($logo);

        $this->fixture->load($this->createMock(ObjectManager::class));

        self::assertArrayNotHasKey('softsolutions4u_invoice.company_name', $written);
        self::assertCount(11, $written);
    }

    /**
     * Tests that nothing is flushed when every setting already has a value.
     */
    public function testDoesNotFlushWhenNothingChanged(): void
    {
        $this->configManager->method('get')->willReturn('already set');
        $this->configManager->expects(self::never())->method('set');
        $this->configManager->expects(self::never())->method('flush');
        $this->fileManager->expects(self::never())->method('createFileEntity');

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('persist');

        $this->fixture->load($manager);
    }

    /**
     * Tests the fixture version.
     */
    public function testVersion(): void
    {
        self::assertSame('1.0', $this->fixture->getVersion());
    }
}
