<?php

require_once __DIR__ . '/php-prepend-includes/pantheon.php';

$client = new Pantheon\Internal\CustomerSecrets\CustomerSecretsClientImplementationV1();

$secrets = $client->get();

var_dump($secrets);

