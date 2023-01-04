<?php
/**
 * Global Pantheon PHP prepend file.
 *
 * This file is sourced on ever PHP request after the binding-specific prepend
 * file. It has access to binding data.
 *
 * This is used to set global constants, provide library functions, and run
 * logic that should happen every request (e.g. insuring the database is up,
 * screening for malicious POSTs, etc).
 */

/**
 * Global constants and $_SERVER superglobals.
 */

// Autoload our available endpoint classes.
function pantheon_autoloader($className) {
  if (class_exists($className)) {
    return;
  }
  $classFilePath = __DIR__ . '/src/' . strtr($className, '\\', '/') . '.php';

  if(file_exists($classFilePath) && is_readable($classFilePath)) { // guardrails-disable-line
    require($classFilePath);
  }
}

spl_autoload_register('pantheon_autoloader');

/**
 * APC functions are not available in PHP 7; however, APCu is availble.
 * It would be even better to use something like:
 *
 * https://github.com/laravel/framework/blob/5.1/src/Illuminate/Cache/ApcWrapper.php
 */
if (function_exists('apcu_fetch')) {
  if (!function_exists('apc_fetch')) {
    function apc_exists($key) { return apcu_exists($key); }
    function apc_store($key, $value, $seconds) { return apcu_store($key, $value, $seconds); }
    function apc_fetch($key, &$success = null) { return isset($success) ? apcu_fetch($key, $success) : apcu_fetch($key); }
    function apc_delete($key) { return apcu_delete($key); }
    function apc_clear_cache() { apcu_clear_cache(); }
  }
}

// Compatibility for Enterprise Pantheon customers with a custom SSL-terminating
// load balancer.
// TODO (Mark): This logic is duplicated in default.vcl. It should probably only be there,
// not here. Find out why, and remove this.
if (isset($_SERVER['HTTP_SSLCLIENTCIPHER']) && $_SERVER['HTTP_SSLCLIENTCIPHER'] != '') {
  $_SERVER['HTTP_X_SSL'] = 'ON';
}

if (isset($_SERVER['HTTP_X_SSL']) && $_SERVER['HTTP_X_SSL'] == 'ON') {
  // Let drupal know when to generate absolute links as https.
  // Used in drupal_settings_initialize()
  $_SERVER['HTTPS'] = 'on';
}

if (isset($_SERVER['HTTP_TRACEPARENT'])) {
  // Drop W3C headers.
  unset($_SERVER['HTTP_TRACEPARENT']);
}

/**
 * Don't allow Argus to trigger install.php
 */
if (isset($_SERVER['HTTP_X_PANTHEON_SCREENSHOT'])) {
  if (isset($_SERVER['SCRIPT_FILENAME']) && preg_match('/install.php$/', $_SERVER['SCRIPT_FILENAME']) === 1) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 560 Not Installed', TRUE, 560);
    header('Cache-Control: no-cache, must-revalidate, post-check=0, pre-check=0, max-age=0');
    exit();
  }
}

/**
 * A function to try and get pantheon variables. Safe outside of Drupal.
 */
function pantheon_variable_get($name, $default = NULL) {
  static $pressflow_settings = array();
  if (count($pressflow_settings) == 0) {
    $pressflow_settings = json_decode($_SERVER['PRESSFLOW_SETTINGS'], TRUE);
  }

  if (isset($pressflow_settings['conf'][$name])) {
    return $pressflow_settings['conf'][$name];
  }

  if(function_exists('variable_get')) {
    return variable_get($name, $default);
  }

  return $default;
}

// Helper function to reach out to the resurrector for a given binding type
// Args:
// method: curl method type (GET, POST, etc)
// endpoint_type: pantheon endpoint type (appserver, dbserver, etc)
// service_type: binding service name (mysql, proxysql, etc)
// service_action: command passed to resurrector to call on service (start, stop, reload, restart, etc.)
function curl_resurrector($method, $endpoint_type, $service_type, $service_action) {
  // Check called type to set some variables
  if ($endpoint_type == "dbserver") {
    $url = 'https://' . $_ENV['DB_RESURRECTOR_HOST'] . '?source=php_prepend';
    $binding = $_ENV['DB_BINDING_ID'];
  } elseif($endpoint_type == "appserver") {
    $url = 'https://' . RESURRECTOR_HOST . '?source=php_prepend';
    $binding = PANTHEON_BINDING;
  } elseif($endpoint_type == "cacheserver") {
    $url = 'https://' . $_ENV['CACHE_RESURRECTOR_HOST'] . '?source=php_prepend';
    $binding = $_ENV['CACHE_BINDING_ID'];
  } else {
    // If called improperly, exit
    die();
  }
  list($ch, $opts) = pantheon_curl_setup($url, NULL, 457, $method);
  $headers = array(
    'X-Pantheon-Binding: ' . $binding,
    'X-Pantheon-Service-Type: ' . $service_type,
    'X-Pantheon-Service-Action: ' . $service_action
  );
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_TIMEOUT, 90);
  curl_exec($ch); // guardrails-disable-line
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($code != 418) {
    header("HTTP/1.1 555 Call to Resurrector Failed");
    header('Content-Type: text/plain');
    // Example: Failed to start mysql. Got code...
    // Example: Failed to restart proxysql. Got code...
    echo('Failed to ' . $service_action . ' ' . $service_type . '. Got code: ' . $code . PHP_EOL);
    $error = curl_error($ch);
    if ($error != '') {
      echo('Got error: ' . $error . PHP_EOL);
    }
    // Only die on calls to dbserver resurrector
    if($endpoint_type == 'dbserver') {
      die();
    }
  }
  curl_close($ch);
}

// This function is guaranteed to be called after PRESSFLOW_SETTINGS are
// loaded into $_SERVER but before the application code runs.
function initialize_pantheon($test_db = FALSE) {
  pantheon_informant_filter(FALSE);
  pantheon_remi_filter();
  $db = new \Pantheon\Internal\PantheonDbBackend();
  try {
    $db->resurrect_database($test_db);
  }
  catch (\Pantheon\Internal\DbConnectionFailureException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo '<h1>Database Connection Failure</h1>';
    echo '<h2>Code['. $e->getCode() .']</h2>';
    echo '<h2>Message['. $e->getMessage() .']</h2>';
    echo '<hr />';
    echo '<!--';
    die();
  }

  $cache = new \Pantheon\Internal\PantheonCacheBackend();
  $cache->resurrect_cache();

}

function pantheon_stat($path, $existence_only = FALSE) {
  $system_path = $_SERVER['HOME'] .'/files/'. $path;
  $result = @stat($system_path); // guardrails-disable-line
  if ($existence_only && $result != FALSE) {
    return TRUE;
  }
  return $result;
}

function pantheon_realpath($path) {
  $absolute = ltrim(preg_replace('/\w+\/\.\.\//', '', $path), '/');

  if (!pantheon_stat($absolute, TRUE)) {
    return FALSE;
  }

  return $_SERVER['HOME'] . '/files/' . rtrim($absolute, '/');
}

function pantheon_binding_pem_path()
{
  // @todo: Unify how each environment manages the binding.pem file.
  if (file_exists($_SERVER['HOME'] .'/certs/binding.pem')) {
    return $_SERVER['HOME'] .'/certs/binding.pem';
  }
  if (file_exists('/tmp/binding.pem')) {
    return '/tmp/binding.pem';
  }
  return '';
}

/**
 * Helper function for running CURLs
 */
function pantheon_curl_setup($url, $data = NULL, $port = 443, $verb = 'GET') {
  // create a new cURL resource
  $ch = curl_init();

  // set URL and other appropriate options
  $cert_path = pantheon_binding_pem_path();
  $opts = array(
    CURLOPT_URL => $url,
    CURLOPT_HEADER => 1,
    CURLOPT_PORT => $port,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_CUSTOMREQUEST => $verb,
    CURLOPT_SSLCERT => $cert_path,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Ignore-Agent: 1'),
  );
  curl_setopt_array($ch, $opts);

  // If we are posting data...
  if ($data) {
    if ($verb == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  }

  return array($ch, $opts);
}

function pantheon_curl_result($ch, $opts, $data, $result) {
  if (curl_errno($ch) != 0) {
    $error = curl_error($ch);
    curl_close($ch);
    error_log('Error contacting Pantheon API: '. $error);
    return FALSE;
  }
  list($headers, $body) = explode("\r\n\r\n", "$result\r\n\r\n", 2);

  $return = array(
    'result' => $result,
    'headers' => $headers,
    'body' => $body,
    'opts' => $opts,
    'data' => print_r($data, 1),
    'status-code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
  );

  // close cURL resource, and free up system resources
  curl_close($ch);

  return $return;
}

/**
 * Helper function for running CURLs
 */
function pantheon_curl($url, $data = NULL, $port = 443, $verb = 'GET') {
  list($ch, $opts) = pantheon_curl_setup($url, $data, $port, $verb);

  // grab URL and pass it to the browser
  $result = curl_exec($ch); // guardrails-disable-line

  return pantheon_curl_result($ch, $opts, $data, $result);
}

/**
 * Helper function for running CURLs
 */
function pantheon_curl_retry($url, $data = NULL, $port = 443, $verb = 'GET', $extra = array()) {
  list($ch, $opts) = pantheon_curl_setup($url, $data, $port, $verb);

  // Add defaults to extra parameters
  $extra += array(
      'timeout' => 6,
      'retries' => 6,
  );

  curl_setopt($ch, CURLOPT_TIMEOUT, $extra['timeout']);

  $result = curl_exec($ch); // guardrails-disable-line
  $attempts = 0;
  while ($attempts < $extra['retries'] && curl_errno($ch) != 0) {
    sleep(2 * $attempts);
    $result = curl_exec($ch); // guardrails-disable-line
    $attempts++;
  }

  return pantheon_curl_result($ch, $opts, $data, $result);
}

/**
 * Try for a short while to send a single request, then drop it.
 * Intended for logging or other non-critical messages.
 */
function pantheon_curl_timeout($url, $data = NULL, $port = 443, $verb = 'GET', $timeout = 1) {
  return pantheon_curl_retry($url, $data, $port, $verb, array('timeout' => $timeout, 'retries' => 0));
}

/**
 * Helper function for running multi-CURLs
 * returns an array of mixed type (FALSE for failure, array for success)
 *
 * @variable $urls = an array of full URLs
 * @variable $data = an array of request bodies
 */
function pantheon_multi_curl($urls, $data = NULL, $port = 443, $verb = 'GET') {
  $url_count = count($urls);
  $curl_arr = array();
  $master = curl_multi_init();
  // TODO(kibra): Below only works on php 7+, figure out what we want to do here.
  // http://php.net/manual/en/curl.constants.php
  // curl_multi_setopt($master, CURLMOPT_MAX_TOTAL_CONNECTIONS, 10);

  //setup the handles
  for($i = 0; $i < $url_count; $i++)
  {
      $url =$urls[$i];
      $curl_arr[$i] = curl_init($url);
      $cert_path = pantheon_binding_pem_path();
      $opts = array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => 1,
        CURLOPT_PORT => $port,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_CUSTOMREQUEST => $verb,
        CURLOPT_SSLCERT => $cert_path,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Ignore-Agent: 1'),
        CURLOPT_TIMEOUT => 10,
      );
      curl_setopt_array($curl_arr[$i], $opts);
      curl_multi_add_handle($master, $curl_arr[$i]);
      if (is_array($data)) {
        if ($verb == 'POST') {
          curl_setopt($curl_arr[$i], CURLOPT_POST, 1);
        }
        curl_setopt($curl_arr[$i], CURLOPT_POSTFIELDS, $data[$i]);
      }
  }

  //execute the handles
  $active = null;
  do {
      $mrc = curl_multi_exec($master, $active); // guardrails-disable-line
  } while ($mrc == CURLM_CALL_MULTI_PERFORM);

  while ($active && $mrc == CURLM_OK) {
      if (curl_multi_select($master) == -1) {
          usleep(100000);
      }
      do {
          $mrc = curl_multi_exec($master, $active); // guardrails-disable-line
      } while ($mrc == CURLM_CALL_MULTI_PERFORM);
  }

  $return = array(
    'successes' => array(),
    'failures' => array(),
  );

  //process results
  for($i = 0; $i < $url_count; $i++)
  {
      if (curl_errno($curl_arr[$i]) != 0) {
        $error = curl_error($curl_arr[$i]);
        curl_multi_remove_handle($master, $curl_arr[$i]);
        curl_close($curl_arr[$i]);
        error_log('Error contacting Pantheon API: '. $error);
        $return['failures'][] = array(
          'url' => $urls[$i],
        );
        continue;
      }
      $result = curl_multi_getcontent  ( $curl_arr[$i] );
      list($headers, $body) = explode("\r\n\r\n", "$result\r\n\r\n", 2);
      $return['successes'][] = array(
        'url' => $urls[$i],
        'result' => $result,
        'headers' => $headers,
        'body' => $body,
        'status-code' => curl_getinfo($curl_arr[$i], CURLINFO_HTTP_CODE),
      );
      if(is_array($data)) {
        $return['successes'][$i]['data'] = print_r($data[$i], 1);
      }
      curl_multi_remove_handle($master, $curl_arr[$i]);
      curl_close($curl_arr[$i]);
  }
  curl_multi_close($master);
  return $return;
}


/**
 * Wrapper for in-application cache.
 *
 * TODO: auto-detect redis and use that? Add a "durable" option?
 */
function pantheon_cache_get($cid) {
  return apc_fetch($cid);
}

/**
 * Wrapper for in-application cache.
 */
function pantheon_cache_set($cid, $value, $ttl = 0) {
  return apc_store($cid, $value, $ttl);
}

/**
 * Wrapper for in-application cache.
 */
function pantheon_cache_clear($cid) {
  return apc_delete($cid);
}

/**
 * Purge specific URLs from the Varnish edge cache.
 *
 * Customer-facing instructions for using this function are here:
 *   https://pantheon.io/docs/articles/architecture/edge/varnish/caching-advancedtopics
 */
function pantheon_purge_edge_urls($paths, $cookies = NULL, $hostnames = NULL, $https = FALSE) {
  trigger_error("Clearing caches via purge_edge_urls will be deprecated in favor the pantheon_clear_edge_* functions.", E_USER_DEPRECATED);
  // Be defensive about parameters, as we don't want to call Ygg if we
  // are able to figure out here that the request will be rejected.
  if (is_string($paths)) {
    $paths = array($paths);
  }
  if (!is_array($paths) || count($paths) < 1) {
    throw new InvalidArgumentException('At least one value is required for $paths');
  }

  // HTTPS.
  if (!is_bool($https)) {
    throw new InvalidArgumentException('Boolean value required for $https');
  }

  // Cookies.
  if (is_string($cookies)) {
    $cookies = array($cookies);
  }
  if (is_null($cookies)) {
    $cookies = array();
  }
  if (!is_array($cookies)) {
      throw new InvalidArgumentException('String, array or NULL required for $cookies');
  }

  // Hostnames.
  if (is_string($hostnames)) {
    $hostnames = array($hostnames);
  }
  if (is_null($hostnames)) {
    if (!isset($_SERVER['HTTP_HOST']) || strlen($_SERVER['HTTP_HOST']) < 3) {
        throw new UnexpectedValueException('Cannot automatically detect the hostname for PURGE, please pass $hostnames parameter.');
    }
    $hostnames = array($_SERVER['HTTP_HOST']);
  }
  if (!is_array($hostnames) || count($hostnames) < 1) {
    throw new InvalidArgumentException('Array or string required for $hostnames, or NULL for auto-detect.');
  }

  // Calculate how many requests this will total. We have a limit of 10 per Styx Host.
  // If changing this, don't forget to change it in the purge_varnish workflow in the API.
  if (count($paths) * count($cookies) * count($hostnames) > 10) {
    $msg = 'Limit overflow: you\'re trying to PURGE too much at once from the edge cache.';
    $msg .= ' Please try again with fewer values. $hostnames x $paths x $cookies may not be more than 10';
    throw new UnexpectedValueException($msg);
  }

  $payload = json_encode(array(
    'type' => 'purge_varnish',
    'params' => array(
      'hostnames' => $hostnames,
      'paths' => $paths,
      'cookies' => $cookies,
      'https' => $https,
    ),
  ));

  $host = 'api.'. PANTHEON_INFRASTRUCTURE_ENVIRONMENT .'.getpantheon.com';
  $url = "https://$host/sites/self/environments/" . PANTHEON_ENVIRONMENT . '/workflows?source=pantheon.php';

  $result = pantheon_curl($url, $payload, 8443, 'POST');

  if (!isset($result['headers']) || strpos($result['headers'], '202 Accepted') === FALSE) {
    throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to PURGE edge cache.');
  }
}

/**
 * Flush Varnish cache.
 */
function pantheon_api_flush_caches_shutdown($hostnames = NULL, $paths = NULL) {
  trigger_error("Clearing caches via flush_caches_shutdown will be deprecated in favor the pantheon_clear_edge_* functions.", E_USER_DEPRECATED);
  $options = array();

  $options['hostnames'] = $hostnames;
  $options['paths'] = $paths;

  // Don't clear caches when cron is run (as it also invokes hook_flush_caches).
  global $pantheon_cron_defender;
  if ($pantheon_cron_defender) {
    return;
  }

  $payload = json_encode(array(
    'type' => 'ban_varnish_routes',
    'params' => array(
      'hostnames' => $hostnames,
      'paths' => $paths,
    ),
  ));

  $host = 'api.'. PANTHEON_INFRASTRUCTURE_ENVIRONMENT .'.getpantheon.com';
  $url = "https://$host/sites/self/environments/" . PANTHEON_ENVIRONMENT . '/workflows?source=pantheon.php';

  $result = pantheon_curl($url, $payload, 8443, 'POST');

  if (!isset($result['headers']) || strpos($result['headers'], '202 Accepted') === FALSE) {
    throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to BAN edge cache.');
  }
}

/**
 * Implement hook_flush_caches so we can register a shutdown function (to clear Varnish and APC)
 * once the regular cache clearning has finished.
 */
function pantheon_api_flush_caches() {
  register_shutdown_function('pantheon_clear_edge_all');
}

/**
 * The Drupal 8 hook.
 */
function pantheon_api_cache_flush() {
  register_shutdown_function('pantheon_clear_edge_all');
}

/**
 * The function we expose to WP developers.
 */
function pantheon_clear_edge($host, $urls=array()) {
  $hostnames = array($host);
  if (count($urls) > 10) {
    trigger_error("Attempted to clear more than 10 urls, truncating list", E_USER_WARNING);
    $urls = array_slice($urls, 0, 10);
  }
  elseif (count($urls) == 0) {
    // Presume that we want to clear everything if no urls are specified.
    $urls = array('/.*');
  }
  register_shutdown_function('pantheon_api_flush_caches_shutdown', $hostnames, $urls);
}

/**
 * A function we expose to developers to clear the entire edge cache.
 */
function pantheon_clear_edge_all() {
  // Don't clear caches when cron is run (as it also invokes hook_flush_caches).
  global $pantheon_cron_defender;
  if ($pantheon_cron_defender) {
    return;
  }
  if (PANTHEON_INFRASTRUCTURE_ENVIRONMENT == 'live'):
    $host = 'edge-cache-clearer.svc.pantheon.io';
  else:
    $host = 'edge-cache-clearer';
  endif;
  $url = "https://$host/self/cache";
  $result = pantheon_curl_retry($url, NULL, 443, 'DELETE');
  if (!isset($result['status-code']) || $result['status-code'] != 200) {
    throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to PURGE edge cache.');
  }
}

/**
 * A function we expose to developers to clear specific paths from the edge cache.
 */
function pantheon_clear_edge_paths($paths=array()) {
  if (!is_array($paths) || count($paths) < 1) {
    throw new InvalidArgumentException('At least one value is required for $paths');
  }

  if (PANTHEON_INFRASTRUCTURE_ENVIRONMENT == 'live'):
    $host = 'edge-cache-clearer.svc.pantheon.io';
  else:
    $host = 'edge-cache-clearer';
  endif;
  $url = "https://$host/self/cache/paths/";
  $urls = array();

  foreach ($paths as $path) {
    $urls[] = $url . urlencode(urlencode($path));
  }

  $results = pantheon_multi_curl($urls, NULL, 443, 'DELETE');

  $retry_urls = array();
  foreach ($results['failures'] as $result) {
    $retry_urls[] = $result['url'];
  }
  foreach ($results['successes'] as $result) {
    if (!isset($result['status-code']) || $result['status-code'] != 200) {
      $retry_urls[] = $result['url'];
    }
  }

  // Retry once
  $results = pantheon_multi_curl($retry_urls, NULL, 443, 'DELETE');
  foreach ($results['failures'] as $result) {
    throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to PURGE edge cache.');
  }
  foreach ($results['successes'] as $result) {
    if (!isset($result['status-code']) || $result['status-code'] != 200) {
      throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to PURGE edge cache.');
    }
  }
}

/**
 * A function we expose to developers to clear specific surrogate keys from the edge cache.
 */
function pantheon_clear_edge_keys_batch($keys=array()) {
  if (!is_array($keys) || count($keys) < 1) {
    throw new InvalidArgumentException('At least one value is required for $keys');
  }

  if (PANTHEON_INFRASTRUCTURE_ENVIRONMENT == 'live'):
    $host = 'edge-cache-clearer.svc.pantheon.io';
  else:
    $host = 'edge-cache-clearer';
  endif;
  $url = "https://$host/self/cache/keys";

  $data = json_encode($keys);

  $result = pantheon_curl_retry($url, $data, 443, 'POST');

  if (!isset($result['status-code']) || $result['status-code'] != 200) {
    throw new UnexpectedValueException('Unexpected result from Pantheon API when attempting to PURGE edge cache.');
  }
}

/**
 * A function we expose to developers to collect surrogate keys to be cleared
 * in pantheon_clear_edge_keys_batch() in a shutdown function.
 */
 function pantheon_clear_edge_keys(array $keys = array()) {
   static $all_keys = array();
   static $shutdown_registered = FALSE;

   if ($shutdown_registered === FALSE) {
     register_shutdown_function('pantheon_clear_edge_keys_shutdown');
     $shutdown_registered = TRUE;
   }

   $all_keys = array_merge($keys, $all_keys);
   $all_keys = array_unique($all_keys);
   return $all_keys;
 }

/**
 * A shutdown function to clear edge keys that have been collected during
 * the life of the request.
 */
 function pantheon_clear_edge_keys_shutdown() {
   $all_keys = pantheon_clear_edge_keys();
   if (!empty($all_keys)) {
     pantheon_clear_edge_keys_batch($all_keys);
   }
 }

/**
 * Detect when cron is being run and set global to avoid also clearing caches.
 */
function pantheon_api_cron() {
  global $pantheon_cron_defender;
  $pantheon_cron_defender = TRUE;
}

/**
 * Verify the database is online and accessible.  Return TRUE on success
 * and FALSE on failure.  Also, print details about failures.
 */
function healthcheck_database() {
  try {
    $dsn = 'mysql:host='. $_ENV['DB_HOST']. ';port='. $_ENV['DB_PORT'] .';dbname='. $_ENV['DB_NAME'];
    $dbh = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
    $dbh->setAttribute(PDO::ATTR_TIMEOUT, 2);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $result = $dbh->query("SELECT 1;");
    if ($result === FALSE) {
      return FALSE;
    }
  }
  catch (PDOException $e) {
    print $e->getMessage();
    return FALSE;
  }
  return TRUE;
}

/**
 * TODO: is this function used? It should be namespaced if so.
 */
function log_data($data) {
  file_put_contents('/tmp/pp', "\n" . var_export($data, true), FILE_APPEND); // guardrails-disable-line
}

/**
 * Function to examine and log incoming $_POST data, so that we can see
 * how our customers are using the platform.
 */
function pantheon_remi_filter()
{
  $framework = $_ENV['FRAMEWORK'];
  if (stripos($framework, 'wordpress') !== FALSE) {
    return;
  }
  $original_post = $_POST;
  $original_get = $_GET;
  $filtered_get = pantheon_remi_filter_get();
  $filtered_post = pantheon_remi_filter_recursive($_POST);
  $filtered_get |= pantheon_remi_filter_recursive($_GET);
  if ($filtered_post) {
    pantheon_log_event(json_encode($original_post), 'php-fpm-remi-x', LOG_CRIT);
  }
  elseif ($filtered_get) {
    pantheon_log_event(json_encode($original_get), 'php-fpm-remi-x', LOG_CRIT);
  }
  else {
    pantheon_remi_examine_recursive($_GET);
    pantheon_remi_examine_recursive($_REQUEST);
  }
}

function pantheon_remi_filter_get() {
  $result = false;
  if (!isset($_SERVER['REQUEST_METHOD'])) {
    return false;
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach($_GET as $key => $value) {
      if (remi_detect($value) || remi_detect($key)) {
        unset($_GET[$key]);
        $result = true;
      }
    }
  }
  return $result;
}

function remi_detect($value) {
  if (is_array($value)) {
    foreach($value as $key => $subvalue) {
      if (remi_detect($subvalue)) {
        return TRUE;
      }
      if (remi_detect($key)) {
        return TRUE;
      }
    }
    return FALSE;
  }
  if (strpos($value, '[#') !== FALSE) {
    return TRUE;
  }
  if (strpos($value, '/#') !== FALSE) {
    return TRUE;
  }
  $value = rawurldecode($value);
  if (strpos($value, '[#') !== FALSE) {
    return TRUE;
  }
  if (strpos($value, '/#') !== FALSE) {
    return TRUE;
  }
  return FALSE;
}

function pantheon_remi_filter_recursive(array &$input) {
  $result = false;
  foreach ($input as $key => $value) {
    if (is_string($key) && $key !== '' && $key[0] == '#' && !empty($value)) {
      unset($input[$key]);
      $result = true;
    } elseif (is_array($value)) {
      $result |= pantheon_remi_filter_recursive($input[$key]);
    }
  }
  return $result;
}

function pantheon_remi_examine_recursive(array &$input) {
  $result = false;
  foreach ($input as $key => $value) {
    if (is_string($key) && $key !== '' && $key[0] == '#' && !empty($value)) {
      $output = $value;
      if (is_array($value)) {
        $output = print_r($value, TRUE);
      }
      $message = $key . ' ' . escapeshellarg($output);
      pantheon_log_event($message, 'php-fpm-remi', LOG_WARNING);
      $result = true;
    }
    if (is_array($value)) {
      $result |= pantheon_remi_examine_recursive($input[$key]);
    }
  }
  return $result;
}

function pantheon_log_event($message, $tag, $priority) {
  $framework = $_ENV['FRAMEWORK'];
  $source_address = $_SERVER['REMOTE_ADDR'];
  $method = $_SERVER['REQUEST_METHOD'];
  $host = $_SERVER['HTTP_HOST'];
  $uri = $_SERVER['REQUEST_URI'];
  $bid = $_SERVER['USER'];
  $log_header = "{$tag} ({$framework}) $source_address {$method} {$host}{$uri} {$bid}";
  register_shutdown_function('pantheon_log_event_shutdown', $log_header, $message, $priority);
}

function pantheon_log_event_shutdown($log_header, $message, $priority) {
  // Send to logz.io
  $token = "igahExjXPGruMPwGOcFspKapLjGoshfQ";
  $port = 8071;
  $url = "https://listener.logz.io:$port/?token=$token&type=php-fpm";
  $data = json_encode(array('message' => $log_header . ' ' . $message));
  pantheon_curl_timeout($url, $data, $port, 'POST');
}

/**
 * Function to examine and sanitize incoming $_REQUESTS to prevent the
 * "informant" attack AKA Drupal SA 2014-10-15.
 *
 * For now we are only logging. Change $deny when invoked to start issuing 500s.
 */
function pantheon_informant_filter($deny=FALSE) {
  if (is_array($_REQUEST)) {
    pantheon_informant_filter_recursive($_REQUEST, $deny);
  }
  if (is_array($_COOKIE)) {
    pantheon_informant_filter_recursive($_COOKIE, $deny);
  }
}

function pantheon_informant_filter_recursive($array, $deny=FALSE) {
  foreach($array as $k => $v) {
    // Don't allow keys with semicolons that appear to contain sql.
    if (strpos($k, ';') !== FALSE) {
      if (pantheon_find_banned_sql_keywords($k)) {
        $msg = 'Informant attack BLOCKED for ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $msg .= ' - SQL detected in key: ' . escapeshellarg($k);
        $priority = 2;
        $deny = TRUE;
      }
      else {
        $msg = 'Informant attack SUSPECTED for ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $msg .= ' - Suspect looking key: ' . escapeshellarg($k);
        $priority = 4;
      }
      pantheon_malicious_request($msg, 'informant', $deny, $priority);
    }
    // And "OR" could be used to circumvent password checks on login.
    if (stripos($k, ' OR ') !== FALSE ||
        stripos($k, '+OR+') !== FALSE ||
        stripos($k, '%20OR%20') !== FALSE) {
      $msg = 'Informant attack SUSPECTED at '. $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      $msg .= ' - Suspect "OR" detected: ' . escapeshellarg($k);
      pantheon_malicious_request($msg, 'informant', $deny);
    }
    // Recurse into nested arrays.
    if (is_array($v)) {
      pantheon_informant_filter_recursive($v);
    }
  }
}

/**
 * Quick helper function to check if there are SQL keywords in a string.
 */
function pantheon_find_banned_sql_keywords($str) {
  static $banned_sql = array('insert',
                            'update',
                            'delete',
                            'alter',
                            'show',
                            'load',
                            'drop',
                            'create',
                            'truncate',
                            'replace',
                            'call',
                            'subquery',
                            'grant',
                            'extractvalue');
  foreach ($banned_sql as $sql) {
    if (stripos($str, ';'. $sql) !== FALSE) {
      return TRUE;
    }
    if (stripos($str, ' '. $sql .' ') !== FALSE) {
      return TRUE;
    }
    if (stripos($str, '+'. $sql .'+') !== FALSE) {
      return TRUE;
    }
    if (stripos($str, '%20'. $sql .'%20') !== FALSE) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * A function to log and optionally deny malicious requests.
 *
 * Accepts a $message and a $tag to idenitfy the incident.
 *
 * Pass $deny as TRUE to generate a 500. Otherwise it will just log the event.
 */
function pantheon_malicious_request($message, $tag='security', $deny=FALSE, $priority=4) {
  $tags = implode('&', array('site=' . $_ENV['PANTHEON_SITE'],
                             'environment=' . $_ENV['PANTHEON_ENVIRONMENT'],
                             $tag . '=true',
                             ));
  // Log the message out with rich data.
  // $message has been hit with escapeshellarg() already, so comes in as a
  // string with single-quotes around it.
  // http://php.net/manual/en/function.escapeshellarg.php
  openlog($tags, LOG_ODELAY, LOG_USER);
  //syslog($priority, $message);

  // Should we deny the request now?
  if ($deny) {
    // Log more details to disk
    error_log("SQL Injection attack blocked by Pantheon");
    error_log('$_REQUEST: ' . print_r($_REQUEST, TRUE));
    error_log('$_COOKIE: ' . print_r($_COOKIE, TRUE));
    $attrs_to_log = array("HTTP_X_PANTHEON_CLIENT_IP", "REQUEST_URI");
    foreach ($attrs_to_log as $attr_to_log) {
        error_log('$_SERVER[' . $attr_to_log . ']: ' . $_SERVER[$attr_to_log]);
    }

    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', TRUE, 500);
    print '<html><head><title>Internal Server Error</title></head>';
    print '<body><h1 style="color: red;">500 Error</h1>';
    print '<p>Your request could not be completed.</p>';
    print '<p>Please contact your administrator for more information.</p>';
    // Add an md5() of the $extra_tag so we can trace these if they come up
    // in requests from CSE.
    print '<hr /><p>Internal error code:';
    print md5($tag);
    print '</p></body>';
    exit();
  }
}


/**
* Look for query strings representing special APIs for performing healthchecks
* or other administrative actions, like clearing the APC cache.
**/
if (isset($_REQUEST['q'])) {

  /**
   * Run healthcheck and immediately exit (before Drupal).
   */
  if ($_REQUEST['q'] === "pantheon_healthcheck") {
    echo 'Deprecated';
    exit();
  }
}
