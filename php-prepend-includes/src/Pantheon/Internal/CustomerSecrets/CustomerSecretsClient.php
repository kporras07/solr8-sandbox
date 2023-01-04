<?php

namespace Pantheon\Internal\CustomerSecrets;

/**
 * Interface CustomerSecretsClientInterface.
 */
class CustomerSecretsClient
{
  /**
   * Returns Customer Secrets client.
   *
   * @param array $parameters
   *   "version" - API version constraint.
   *
   * @return \Pantheon\Internal\CustomerSecrets\CustomerSecretsClientInterface
   *
   * @throws \Exception
   */
    public static function create(array $parameters = []): CustomerSecretsClientInterface
    {
        $version = $parameters['version'] ?? '1';
        if ('1' === $version) {
            return new CustomerSecretsClientImplementationV1();
        }

        throw new \Exception(sprintf('Unknown Customer Secrets API version "%s"', $version));
    }
}
