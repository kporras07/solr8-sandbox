<?php
/**
 * Verify the database is online and accessible. Return
 * array of errors.
 */
function pantheon_healthcheck_database() {
  try {
    $dsn = 'mysql:host='. $_ENV['DB_HOST']. ';port='. $_ENV['DB_PORT'] .';dbname='. $_ENV['DB_NAME'];
    $options = array(PDO::ATTR_TIMEOUT => 2);
    $dbh = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
    $result = $dbh->exec("SELECT 1;");
    // Handling case when proxysql enabled which don't raise exception
    if ($result === FALSE) {
      $error_info = $dbh->errorInfo();
      $dbh = NULL;
      return array($error_info[2]);
    }
    $dbh = NULL;
  }
  catch (Exception $e) {
    return array($e->getMessage());
  }
  return array();
}

/**
 * If a cacheserver exists, verify it is online and accessible.
 * Return an array of errors.
 */
function pantheon_healthcheck_redis() {
  $has_redis = !empty($_ENV['CACHE_HOST']);
  if (! $has_redis) {
    return array();
  }

  try {
    $redis = new Redis();
    $timeout_seconds = 10;
    $redis->connect($_ENV['CACHE_HOST'], $_ENV['CACHE_PORT'], $timeout_seconds);
    $redis->auth($_ENV['CACHE_PASSWORD']);
    if (! $redis->ping()) {
      // Authentication failures manifest here in the Redis extension used in PHP < 7 only.
      throw new RedisException('Bad redis protocol ping. May be invalid redis password.');
    }
    $redis->close();
  }
  catch (RedisException $e) {
    return array("Cache server availability check failed: " . $e->getMessage());
  }

  return array();
}

/**
* Run healthcheck
**/
// Our VCL with detect 'private' and rewrite the header. Adding 'no-store' defensively.
header("Cache-Control: private, no-store");
if (isset($_REQUEST['fail'])) {
  echo "Simulating failure\n";
  exit();
}

$errors = pantheon_healthcheck_database();
if (count($errors) > 0) {
  echo "Initial database check failed: " . join(",", $errors) . "\n";

  // Attempts to resurrect
  echo "Attempting resurrection\n";
  apc_delete('skip_resurrection_check');
  resurrect_database();

  $errors = pantheon_healthcheck_database();
  if (count($errors) > 0) {
    header("HTTP/1.0 500 Internal Error");
    echo "Post-resurrection database check failed: " . join(",", $errors) . "\n";
    exit();
  }

  echo "Resurrection complete, database accessible.\n";
}

$errors = pantheon_healthcheck_redis();
if (count($errors) > 0) {
  header("HTTP/1.0 500 Internal Error");
  echo join("\n", $errors) . "\n";
  exit();
}

echo "OK\n";
