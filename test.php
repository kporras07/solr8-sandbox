<?php

$filepath = __DIR__ . '/endpoint.pem';

$cert = file_get_contents($filepath);

$private = openssl_pkey_get_private($cert);
$public = openssl_pkey_get_public($cert);

if (!$private || !$public) {
    echo "Failed to load key pair.\n";
    exit(1);
}

$data = 'lorem ipsum';

$result = openssl_public_encrypt(
    $data,
    $encrypted_data,
    $public,
);
echo "Result:\n";
var_dump($result);

echo "Encrypted:\n";
var_dump($encrypted_data);

$result = openssl_private_decrypt(
    $encrypted_data,
    $decrypted_data,
    $private,
);

echo "Result:\n";
var_dump($result);

echo "Decrypted:\n";
var_dump($decrypted_data);
