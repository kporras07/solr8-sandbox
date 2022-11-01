<?php

/**
 * @file
 * Contains \DrupalComposerManaged\ComposerScripts.
 */

namespace DrupalComposerManaged;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;
use Composer\Semver\Comparator;
use Drupal\Core\Site\Settings;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

class ComposerScripts {

  /**
   * Prepare for Composer to update dependencies.
   *
   * Composer will attempt to guess the version to use when evaluating
   * dependencies for path repositories. This has the undesirable effect
   * of producing different results in the composer.lock file depending on
   * which branch was active when the update was executed. This can lead to
   * unnecessary changes, and potentially merge conflicts when working with
   * path repositories on Pantheon multidevs.
   *
   * To work around this problem, it is possible to define an environment
   * variable that contains the version to use whenever Composer would normally
   * "guess" the version from the git repository branch. We set this invariantly
   * to "dev-main" so that the composer.lock file will not change if the same
   * update is later ran on a different branch.
   *
   * @see https://github.com/composer/composer/blob/main/doc/articles/troubleshooting.md#dependencies-on-the-root-package
   */
  public static function postInstall(Event $event) {
    var_dump($event->getName()); exit();

  }

  public static function postInstallPackage(PackageEvent $event) {
    var_dump($event->getName());
    var_dump($event->getArguments());
    var_dump($event->getOperation()->getPackage()->getName());
    if ($event->getOperation()->getPackage()->getName() === 'phpro/grumphp') {
        $event->stopPropagation();
    }
  }
}