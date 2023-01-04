<?php

namespace Pantheon\Internal;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * The PantheonServiceProvider class is made available via the autoloader
 * installed by pantheon.php.
 *
 * In settings.pantheon.php, the service provider is loaded via:
 *
 *    $GLOBALS['conf']['container_service_providers']['PantheonServiceProvider'] = '\Pantheon\Internal\PantheonServiceProvider';
 *
 * See: https://www.drupal.org/node/2183323
 */
class PantheonServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container)
    {
        $container
            ->register('cache.pantheon', 'Pantheon\Internal\PantheonCacheListener')
            ->addTag('cache.bin');
    }
}
