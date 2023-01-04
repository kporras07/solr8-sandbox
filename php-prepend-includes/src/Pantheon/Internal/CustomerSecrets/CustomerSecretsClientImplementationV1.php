<?php

namespace Pantheon\Internal\CustomerSecrets;

/**
 * Class CustomerSecretsClientImplementationV1.
 */
class CustomerSecretsClientImplementationV1 implements CustomerSecretsClientInterface
{
    const LIVE_BASE_URI = 'https://customer-secrets.svc.pantheon.io';
    const SANDBOX_BASE_URI = 'https://customer-secrets';

    /**
     * @inheritdoc
     *
     * @throws \Exception
     */
    public function get(string $hostname = ''): array
    {
        $verify_host = 0;
        if (!$hostname) {
            $hostname = $this->getCustomerSecretsBaseUri();
            $verify_host = 2;
        }
        [$ch, $opts] = pantheon_curl_setup(
            sprintf("%s/site/secrets", $hostname)
        );

        // @todo Remove once everything is ok regarding certs.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);
        $result = pantheon_curl_result($ch, $opts, null, $result);
        if ($result === false || $result['status-code'] != 200) {
            throw new \Exception('Request to Customer Secrets API has failed');
        }

        $body = json_decode($result['body'], true);
        if (json_last_error()) {
            throw new \Exception(
              sprintf(
                'Failed decoding Customer Secrets JSON response: code %d',
                json_last_error()
              )
            );
        }

        return $body;
    }

    /**
     * Returns customer secrets service base URI.
     *
     * @return string
     */
    protected function getCustomerSecretsBaseUri(): string
    {
        return 'live' === PANTHEON_INFRASTRUCTURE_ENVIRONMENT ? self::LIVE_BASE_URI : self::SANDBOX_BASE_URI;
    }
}
