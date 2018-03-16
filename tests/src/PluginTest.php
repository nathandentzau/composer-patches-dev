<?php

declare(strict_types=1);

namespace NathanDentzau\ComposerPatchesDev\Tests;

use Composer\Composer;
use Composer\Script\Event;
use Composer\IO\IOInterface;
use PHPUnit\Framework\TestCase;
use Composer\Script\ScriptEvents;
use Composer\Package\RootPackage;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use NathanDentzau\ComposerPatchesDev\Plugin;
use Composer\Repository\WritableRepositoryInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;

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
            ScriptEvents::PRE_INSTALL_CMD => ['checkPatches', -100],
            ScriptEvents::PRE_UPDATE_CMD => ['checkPatches', -100],
            PackageEvents::POST_PACKAGE_INSTALL => ['postInstall', -100],
            PackageEvents::POST_PACKAGE_UPDATE => ['postInstall', -100],
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
    public function testCheckPatches(
        bool $isDevMode,
        bool $patchingEnabled,
        array $packagesToUninstall
    ): void {
        $installationManager = $this->createMock(InstallationManager::class);
        $localRepository = $this
            ->createMock(WritableRepositoryInterface::class);

        foreach ($packagesToUninstall as $position => $package) {
            $installationManager->expects($this->at($position))
                ->method('uninstall')
                ->with($localRepository, new UninstallOperation($package));
        }

        $expectedCallCount = $isDevMode && $patchingEnabled
            ? count($packagesToUninstall)
            : 0;

        $this->composer->expects($this->exactly($expectedCallCount))
            ->method('getInstallationManager')
            ->willReturn($installationManager);

        $event = $this->createMock(Event::class);

        $event->expects($this->once())
            ->method('isDevMode')
            ->willReturn($isDevMode);

        $plugin = $this
            ->createPluginMock()
            ->setPackagesToUninstall($packagesToUninstall)
            ->setComposerPatchesInstalled(true)
            ->setPatchingEnabled(true);
        $plugin->activate($this->composer, $this->io);
        $plugin->checkPatches($event);
    }

    /**
     * @dataProvider postInstallDataProvider
     */
    public function testPostInstall(bool $isDevMode): void
    {
        $event = $this->createMock(PackageEvent::class);
        $event->expects($this->once())
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
        $event->expects($this->exactly($isDevMode ? 1 : 0))
            ->method('getOperation')
            ->willReturn($operation);

        $plugin = $this
            ->createPluginMock()
            ->setPatchingEnabled(false);
        $plugin->activate($this->composer, $this->io);
        $plugin->postInstall($event);
    }

    /**
     * @dataProvider grabPatchesDataProvider
     */
    public function testGrabPatches(array $extra, array $expected): void 
    {
        $this->composer
            ->getPackage()
            //->expects($this->exactly(2))
            ->method('getExtra')
            ->willReturn($extra);

        if (isset($extra['patches-dev'])) {
            $this->io/*->expects($this->exactly(2))*/
                ->method('write')
                ->with(
                    '<info>Gathering dev patches for root package.</info>'
                );
        }

        if (!empty($expected['exception'])) {
            $this->expectException(\Exception::class);
        }

        $plugin = $this->createPluginMock();
        $plugin->activate($this->composer, $this->io);

        if (empty($expected['exception'])) {
            $this->assertEquals($expected, $plugin->grabPatches());
        }
    }

    /**
     * @dataProvider getPackagesToUninstallDataProvider
     */
    public function testGetPackagesToUninstall(
        bool $composerPatchesInstalled,
        array $expects,
        array $extra,
        array $packages
    ): void {
        $this->composer
            ->getPackage()
            ->method('getExtra')
            ->willReturn($extra);

        $this->composer
            ->getRepositoryManager()
            ->getLocalRepository()
            ->expects($this->once())
            ->method('getPackages')
            ->willReturn($packages);
        
        $plugin = new class extends Plugin {
            protected $composerPatchesInstalled = true;

            public function setComposerPatchesInstalled(
                bool $composerPatchesInstalled
            ): self {
                $this->composerPatchesInstalled = $composerPatchesInstalled;
                return $this;
            }

            protected function isComposerPatchesInstalled(): bool 
            {
                return $this->composerPatchesInstalled;
            }
        };
        $plugin->setComposerPatchesInstalled($composerPatchesInstalled);
        $plugin->activate($this->composer, $this->io);

        $packagesToUninstall = $plugin->getPackagesToUninstall();
        $this->assertEquals($expects, $packagesToUninstall);
    }

    /**
     * @dataProvider isPatchingEnabledDataProvider
     */
    public function testIsPatchingEnabled(bool $expects, array $extra): void
    {
        $this->composer
            ->getPackage()
            ->expects($this->exactly(2))
            ->method('getExtra')
            ->willReturn($extra);

        $plugin = new Plugin();
        $plugin->activate($this->composer, $this->io);

        $reflection = new \ReflectionMethod($plugin, 'isPatchingEnabled');
        $reflection->setAccessible(true);

        $isPatchingEnabled = $reflection->invoke($plugin, 'isPatchingEnabled');

        $this->assertEquals($expects, $isPatchingEnabled);
    }

    public function testIsComposerPatchesInstall(): void
    {
        $this->composer
            ->getPackage()
            ->expects($this->once())
            ->method('getRequires')
            ->willReturn([
                'cweagans/composer-patches' => '',
                'drupal/core' => '',
            ]);

        $this->composer
            ->getPackage()
            ->expects($this->once())
            ->method('getDevRequires')
            ->willReturn([
                'nathandentzau/composer-patches-dev',
            ]);

        $plugin = new Plugin();
        $plugin->activate($this->composer, $this->io);

        $reflection = new \ReflectionMethod(
            $plugin,
            'isComposerPatchesInstalled'
        );
        $reflection->setAccessible(true);

        $isComposerPatchesInstalled = $reflection->invoke(
            $plugin,
            'isComposerPatchesInstalled'
        );
        $this->assertTrue($isComposerPatchesInstalled);
    }

    public function checkPatchesDataProvider(): array
    {
        return [
            [
                true, // Dev mode.
                true, // Patching enabled.
                // Packages to uninstall.
                [
                    $this->createMock(PackageInterface::class),
                ],
            ],
            [
                true, // Dev mode.
                true, // Patching enabled.
                [], // Packages to uninstall.
            ],
            [
                false, // Dev mode.
                true, // Patching enabled.
                [], // Packages to uninstall.
            ],
            [
                true, // Dev mode.
                false, // Patching enabled.
                [], // Packages to uninstall.
            ],
        ];
    }

    public function postInstallDataProvider(): array
    {
        return [[true], [false]];
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

    public function getPackagesToUninstallDataProvider(): array
    {
        $testPackage = $this->createMock(PackageInterface::class);
        $testPackage
            ->method('getName')
            ->willReturn('test/package');
        $anotherPackage = $this->createMock(PackageInterface::class);
        $anotherPackage
            ->method('getName')
            ->willReturn('another/package');
        
        return [
            [
                true,
                [
                    $testPackage,
                ],
                [
                    'patches-dev' => [
                        'test/package' => '',
                    ],
                ],
                [
                    $testPackage,
                    $anotherPackage,
                ],
            ],
            [
                true,
                [],
                [
                    'patches' => [
                        'test/package' => '',
                    ],
                    'patches-dev' => [
                        'test/package' => '',
                    ],
                ],
                [
                    $testPackage,
                    $anotherPackage,
                ],
            ],
            [
                true,
                [],
                [],
                [],
            ],
            [
                false,
                [
                    $testPackage,
                    $anotherPackage,
                ],
                [
                    'patches-dev' => [
                        'test/package' => '',
                        'another/package' => '',
                    ],
                ],
                [
                    $testPackage,
                    $anotherPackage,
                ],
            ],
            [
                false,
                [],
                [],
                [],
            ]
        ];
    }

    public function isPatchingEnabledDataProvider(): array
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
                false,
                [],
            ],
        ];
    }
    
    protected function createPluginMock(): Plugin
    {
        return new class extends Plugin {
            protected $packages = [];
            protected $patchingEnabled = true;
            protected $composerPatchesInstalled = true;

            public function getPackagesToUninstall(): array
            {
                return $this->packages;
            }

            public function setPackagesToUninstall(array $packages): self
            {
                $this->packages = $packages;
                return $this;
            }

            public function setPatchingEnabled(bool $patchingEnabled): self
            {
                $this->patchingEnabled = $patchingEnabled;
                return $this;
            }

            public function setComposerPatchesInstalled(
                bool $composerPatchesInstalled
            ): self {
                $this->composerPatchesInstalled = $composerPatchesInstalled;
                return $this;
            }

            protected function isPatchingEnabled() 
            {
                return $this->patchingEnabled;
            }

            protected function isComposerPatchesInstalled(): bool 
            {
                return $this->composerPatchesInstalled;
            }
        };
    }
}
