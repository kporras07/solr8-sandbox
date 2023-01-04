<?php

namespace Pantheon\Internal\CustomerSecrets;

/**
 * Interface CustomerSecretsClientInterface.
 */
interface CustomerSecretsClientInterface
{
    /**
     * Returns Customer Secrets for the site associated with
     * the current binding, as identified by the binding cert.
     *
     * @return array
     */
    public function get(): array;
}
