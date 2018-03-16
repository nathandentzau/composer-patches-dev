<?php

declare(strict_types=1);

namespace NathanDentzau\ComposerPatchesDev;

use Composer\Composer;
use Composer\Script\Event;
use Composer\IO\IOInterface;
use Composer\Script\ScriptEvents;
use Composer\Package\RootPackage;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use cweagans\Composer\Patches as PatchesPluginBase;
use Composer\Repository\RepositoryInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;

class Plugin extends PatchesPluginBase
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        // Set a low priority to ensure this plugin's events are triggered after
        // cweagans/composer-patches.
        return [
            ScriptEvents::PRE_INSTALL_CMD => ['checkPatches', -100],
            ScriptEvents::PRE_UPDATE_CMD => ['checkPatches', -100],
            PackageEvents::POST_PACKAGE_INSTALL => ['postInstall', -100],
            PackageEvents::POST_PACKAGE_UPDATE => ['postInstall', -100],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        parent::activate($composer, $io);
        $this->patches = $this->grabPatches();
    }

    /**
     * {@inheritdoc}
     */
    public function checkPatches(Event $event)
    {
        if (!$event->isDevMode() || !$this->isPatchingEnabled()) {
            return;
        }

        foreach ($this->getPackagesToUninstall() as $package) {
            $this->composer
                ->getInstallationManager()
                ->uninstall(
                    $this->getLocalRepository(),
                    new UninstallOperation($package)
                );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function postInstall(PackageEvent $event) 
    {
        if (!$event->isDevMode()) {
            return;
        }

        parent::postInstall($event);
    }

    /**
     * {@inheritdoc}
     */
    public function grabPatches()
    {
        $extra = $this->getRootPackage()->getExtra();
        $patches = [];

        if (!empty($extra['patches-dev'])) {
            $this->io->write(
                '<info>Gathering dev patches for root package.</info>'
            );

            $patches = $extra['patches-dev'];
        } elseif (!empty($extra['patches-file'])) {
            $patchesFile = $this->loadPatchesFromFile($extra['patches-file']);
            $patches = $patchesFile['patches-dev'] ?? [];
        }

        return $patches;
    }

    /**
     * Get a list of packages to uninstall.
     * 
     * The list of packages is built from the patches defined in the 
     * patches-dev array. If cweagans/composer-patches is installed, only the
     * packages not defined in the patches array will be uninstalled.
     *
     * @return array
     */
    public function getPackagesToUninstall(): array
    {
        if ($this->isComposerPatchesInstalled()) {
            $packagesToUninstall = array_diff(
                array_keys($this->patches),
                array_keys(parent::grabPatches())
            );
        } else {
            $packagesToUninstall = array_keys($this->patches);
        }

        $packages = [];

        foreach ($this->getLocalRepository()->getPackages() as $package) {
            if (!in_array($package->getName(), $packagesToUninstall, true)) {
                continue;
            }

            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * {@inheritdoc}
     */
    protected function isPatchingEnabled()
    {
        $extra = $this->getRootPackage()->getExtra();
        return !empty($extra['patches-dev']);
    }

    /**
     * Get Composer package repository.
     *
     * @return RepositoryInterface
     */
    protected function getLocalRepository(): RepositoryInterface
    {
        return $this->composer
            ->getRepositoryManager()
            ->getLocalRepository();
    }

    /**
     * Get the composer root package object.
     *
     * @return RootPackage
     */
    protected function getRootPackage(): RootPackage
    {
        return $this->composer->getPackage();
    }

    /**
     * Determine if cweagans/composer-patches package is installed.
     *
     * @return bool Whether cweagans/composer-patches is required by the root
     *              compose file.
     */
    protected function isComposerPatchesInstalled(): bool 
    {
        $packages = array_merge(
            array_keys($this->getRootPackage()->getRequires()), 
            array_keys($this->getRootPackage()->getDevRequires())
        );

        return in_array('cweagans/composer-patches', $packages, true);
    }

    /**
     * Retrieve patches from a JSON file and deserialize its contents.
     *
     * @param string $filename The path to the patches json file.
     * @return array
     * @throws \Exception;
     */
    protected function loadPatchesFromFile(string $filename): array
    {
        $patchesFileContents = file_get_contents($filename);
        $patches = json_decode($patchesFileContents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(
                sprintf(
                    'There was an error in the supplied patches file: %s',
                    json_last_error_msg()
                )
            );
        }

        return $patches;
    }
}
