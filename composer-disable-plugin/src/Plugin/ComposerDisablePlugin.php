<?php

namespace Kporras07\ComposerDisablePlugin\Plugin;

use Composer\Plugin\PluginInterface;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Kporras07\ComposerDisablePlugin\RulesEvaluator;
use Composer\Installer\PackageEvents;
use Composer\Installer\PackageEvent;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;

class ComposerDisablePlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer\Composer;
     */
    private $composer;

    /**
     * @var Composer\IO\IOInterface;
     */
    private $io;

    /**
     * @var array
     */
    private $config;

    /**
     * @var RulesEvaluator
     */
    private $rulesEvaluator;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->config = $composer->getPackage()->getExtra()['composer-disable-plugin'] ?? [];
        $this->rulesEvaluator = new RulesEvaluator();

        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = $repo->getPackages();
        $packagesToDisable = $this->getPackagesToDisable();

        foreach ($packages as $package) {
            if (in_array($package->getName(), $packagesToDisable)) {
                $this->io->write('ComposerDisablePlugin: Disabling plugin: ' . $package->getName());
                $this->composer->getPluginManager()->deactivatePackage($package);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

    /**
     * Event handler for post package install.
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            return;
        }

        $packagesToDisable = $this->getPackagesToDisable();
        if (in_array($package->getName(), $packagesToDisable)) {
            $this->io->write('ComposerDisablePlugin: Disabling plugin: ' . $package->getName());
            $this->composer->getPluginManager()->deactivatePackage($package);
        }
    }

    /**
     * Get a list of packages to disable.
     */
    public function getPackagesToDisable()
    {
        $packagesToDisable = [];
        foreach ($this->config['disablePlugins'] ?? [] as $config) {
            $packageName = $config['packageName'];
            $rules = $config['rules'] ?? [];
            $rulesConjunction = $config['rulesConjunction'] ?? 'and';
            $result = $this->rulesEvaluator->evaluate($rules, $rulesConjunction);
            if ($result) {
                $packagesToDisable[] = $packageName;
            }
        }
        return $packagesToDisable;
    }
}
