<?php

declare(strict_types=1);

namespace NathanDentzau\ComposerPatchesDev\Tests;

use Composer\Composer;
use Composer\Script\Event;
use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Composer\Package\RootPackage;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use NathanDentzau\ComposerPatchesDev\Plugin;
use Composer\Repository\WritableRepositoryInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

class PluginTest extends TestCase
{
    protected $composer;

    protected $io;

    public function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);

        $localRepository = $this
            ->createMock(WritableRepositoryInterface::class);

        $repositoryManager = $this->createMock(RepositoryManager::class);
        $repositoryManager
            ->method('getLocalRepository')
            ->willReturn($localRepository);
        $this->composer
            ->method('getRepositoryManager')
            ->willReturn($repositoryManager);

        $rootPackage = $this->createMock(RootPackage::class);
        $this->composer
            ->method('getPackage')
            ->willReturn($rootPackage);

        $this->io = $this->createMock(IOInterface::class);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = [
            ScriptEvents::PRE_INSTALL_CMD => ['checkPatches', 100],
            ScriptEvents::PRE_UPDATE_CMD => ['checkPatches', 100],
            PackageEvents::POST_PACKAGE_INSTALL => ['postInstall', 100],
            PackageEvents::POST_PACKAGE_UPDATE => ['postInstall', 100],
        ];

        $this->assertEquals(
            array_keys($events),
            array_keys(Plugin::getSubscribedEvents())
        );

        foreach ($events as $event => $callback) {
            $this->assertEquals(
                $callback,
                Plugin::getSubscribedEvents()[$event]
            );
        }
    }

    /**
     * @dataProvider checkPatchesDataProvider
     */
    public function testCheckPatches(bool $isDevMode, array $extra): void {
        $this->composer
            ->getPackage()
            ->expects($this->once())
            ->method('getExtra')
            ->willReturn($extra);

        if ($isDevMode) {
            $localRepository = $this->composer
                ->getRepositoryManager()
                ->getLocalRepository();
            $localRepository
                ->method('getPackages')
                ->will($this->returnCallback(function (): array {
                    $packages = [];

                    foreach (['test/package', 'another/package'] as $name) {
                        $package = $this->createMock(PackageInterface::class);
                        $package
                            ->method('getName')
                            ->willReturn($name);

                        $packages[] = $package;
                    }

                    return $packages;
                }));

            $packages = $extra['patches-dev'] ?? [];
            $installationManager = $this->createMock(InstallationManager::class);
            $position = 0;

            foreach ($localRepository->getPackages() as $package) {
                if (!in_array($package->getName(), $packages, true)) {
                    continue;
                }

                $installationManager
                    ->expects($this->at($position))
                    ->method('uninstall')
                    ->with(
                        $composer->getRepositoryManager()->getLocalRepository(),
                        new UninstallOperation($package)
                    );
            }

            $this->composer
                ->method('getInstallationManager')
                ->willReturn($installationManager);
        }

        $event = $this->createMock(Event::class);
        $event
            ->expects($this->once())
            ->method('isDevMode')
            ->willReturn($isDevMode);

        $plugin = new Plugin();
        $plugin->activate($this->composer, $this->io);
        $plugin->checkPatches($event);
    }

    public function checkPatchesDataProvider(): array
    {
        return [
            [
                true,
                [
                    'patches-dev' => [
                        'test/package' => '',
                    ],
                ],
            ],
            [
                true,
                [],
            ],
            [
                false,
                [],
            ],
        ];
    }

    /**
     * @dataProvider postInstallDataProvider
     */
    public function testPostInstall(bool $isDevMode): void
    {
        $event = $this->createMock(PackageEvent::class);
        $event
            ->expects($this->once())
            ->method('isDevMode')
            ->willReturn($isDevMode);

        $operation = $this->createMock(InstallOperation::class);
        $operation
            ->method('getPackage')
            ->willReturn(new class {
                public function getName(): string
                {
                    return'test/package';
                }
            });
        $event
            ->expects($this->exactly($isDevMode ? 1 : 0))
            ->method('getOperation')
            ->willReturn($operation);

        $plugin = new Plugin();
        $plugin->activate($this->composer, $this->io);
        $plugin->postInstall($event);
    }

    public function postInstallDataProvider(): array
    {
        return [[true], [false]];
    }

    /**
     * @dataProvider grabPatchesDataProvider
     */
    public function testGrabPatches(array $extra, array $expected): void
    {
        $this->composer
            ->getPackage()
            ->expects($this->once())
            ->method('getExtra')
            ->willReturn($extra);

        if (isset($extra['patches-dev'])) {
            $this->io
                ->expects($this->once())
                ->method('write')
                ->with(
                    '<info>Gathering dev patches for root package.</info>'
                );
        }

        if (!empty($expected['exception'])) {
            $this->expectException($expected['exception']);
        }

        $plugin = new class extends Plugin {
            public function setComposer(Composer $composer): self {
                $this->composer = $composer;
                return $this;
            }

            public function setIo(IOInterface $io): self {
                $this->io = $io;
                return $this;
            }
        };

        $plugin
            ->setComposer($this->composer)
            ->setIo($this->io);
        $patches = $plugin->grabPatches();

        if (empty($expected['exception'])) {
            $this->assertEquals($expected, $patches);
        }
    }

    public function grabPatchesDataProvider(): array
    {
        return [
            [
                [
                    'patches-dev' => [
                        'test/package' => 'test.patch',
                    ],
                ],
                [
                    'test/package' => 'test.patch',
                ],
            ],
            [
                [],
                [],
            ],
            [
                [
                    'patches-file' => __DIR__ . '/../patches.json',
                ],
                [
                    'test/package' => 'test.patch',
                ],
            ],
            [
                [
                    'patches-file' => __DIR__ . '/../empty.json',
                ],
                [],
            ],
            [
                [
                    'patches-file' => __DIR__ . '/../error.json',
                ],
                [
                    'exception' => \Exception::class,
                ],
            ],
        ];
    }
}
