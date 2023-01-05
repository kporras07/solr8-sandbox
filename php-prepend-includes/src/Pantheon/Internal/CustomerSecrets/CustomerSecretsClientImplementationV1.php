<?php

namespace Pantheon\Internal\CustomerSecrets;

/**
 * Class CustomerSecretsClientImplementationV1.
 */
class CustomerSecretsClientImplementationV1 implements CustomerSecretsClientInterface
{
    const LIVE_BASE_URI = 'https://customer-secrets.svc.pantheon.io';
    const SANDBOX_BASE_URI = 'https://customer-secrets.sandbox-eco.sbx01.pantheon.io';
    const SECRETS_CACHE_FILE = '/tmp/pantheon-secrets/secrets.txt';
    const SECRETS_TTL = 60 * 5;

    /**
     * @inheritdoc
     *
     * @throws \Exception
     */
    public function get(string $hostname = ''): array
    {
        if ($data = $this->getFromCache()) {
            $data = json_decode($data, true);
            if (!json_last_error()) {
                return $data;
            }
        }
        $verify_host = 0;
        if (!$hostname) {
            $hostname = $this->getCustomerSecretsBaseUri();
            $verify_host = 0;
        }
        [$ch, $opts] = pantheon_curl_setup(
            sprintf("%s/site/secrets", $hostname)
        );

        // @todo Remove once everything is ok regarding certs.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_host);
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
        $this->setCache($result['body']);

        return $body;
    }

    /**
     * Get data into cache.
     */
    protected function setCache(string $data)
    {
        $pem = pantheon_binding_pem_path();
        $certificate_contents = file_get_contents($pem);
        $cert = openssl_pkey_get_public($certificate_contents);

        $result = openssl_public_encrypt(
            $data,
            $encrypted_data,
            $cert,
        );

        if (!$result) {
            return null;
        }

        if (!is_dir(dirname(self::SECRETS_CACHE_FILE))) {
            mkdir(dirname(self::SECRETS_CACHE_FILE), 0755, true);
        }
        file_put_contents(self::SECRETS_CACHE_FILE, $encrypted_data);
    }

    /**
     * Get data from cache.
     */
    protected function getFromCache(): ?string
    {
        if (!file_exists(self::SECRETS_CACHE_FILE)) {
            return null;
        }

        $stat = stat(self::SECRETS_CACHE_FILE);
        if ($stat['mtime'] + self::SECRETS_TTL < time()) {
            // Cache is expired.
            return null;
        }

        $data = file_get_contents(self::SECRETS_CACHE_FILE);
        if (!$data) {
            return null;
        }

        $pem = pantheon_binding_pem_path();
        $certificate_contents = file_get_contents($pem);
        $key = openssl_pkey_get_private($certificate_contents);

        $result = openssl_private_decrypt(
            $data,
            $decrypted_data,
            $key,
        );

        if (!$result) {
            return null;
        }

        return $decrypted_data;
    }

    /**
     * Returns customer secrets service base URI.
     *
     * @return string
     */
    protected function getCustomerSecretsBaseUri(): string
    {
        return self::SANDBOX_BASE_URI;
        return 'live' === PANTHEON_INFRASTRUCTURE_ENVIRONMENT ? self::LIVE_BASE_URI : self::SANDBOX_BASE_URI;
    }
}
