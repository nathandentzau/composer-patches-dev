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
        // Set a high priority to ensure this plugin's events are triggered
        // before cweagans/composer-patches.
        return [
            ScriptEvents::PRE_INSTALL_CMD => ['checkPatches', 100],
            ScriptEvents::PRE_UPDATE_CMD => ['checkPatches', 100],
            PackageEvents::POST_PACKAGE_INSTALL => ['postInstall', 100],
            PackageEvents::POST_PACKAGE_UPDATE => ['postInstall', 100],
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

        $packagesToUninstall = array_keys($this->patches);
        $localRepository = $this->composer
            ->getRepositoryManager()
            ->getLocalRepository();

        foreach ($localRepository->getPackages() as $package) {
            if (!in_array($package->getName(), $packagesToUninstall, true)) {
                continue;
            }

            $this->composer
                ->getInstallationManager()
                ->uninstall(
                    $localRepository,
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
        $extra = $this->composer->getPackage()->getExtra();
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
     * {@inheritdoc}
     */
    protected function isPatchingEnabled()
    {
        return !empty($this->patches);
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
