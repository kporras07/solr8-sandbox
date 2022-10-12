<pre>

<?php

require_once '/srv/includes/pantheon.php';

$client = Pantheon\Internal\CustomerSecrets\CustomerSecretsClient::create();
$secrets = $client->get();

print_r($secrets);

?>
</pre>
