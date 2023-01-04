<?php

namespace Pantheon\Internal;

use \PDO;

class DbConnectionFailureException extends \Exception {}

/**
 * The PantheonDbBackend class is made available via the autoloader
 * installed by pantheon.php.
 *
 */
class PantheonDbBackend
{

    static function expected_connection_errors() {
        /** Known errors
         * ERROR 2002 (HY000): Can't connect ...
         * ERROR 2003 (HY000): Authentication with backend failed.
         * ERROR 2006 (HY000): MySQL server has gone away
         * 
         * When backends are offline Proxysql will throw:
         * ERROR 9001 (HY000) at line 1: Max connect timeout reached while reaching hostgroup 0 after 10000ms
         */ 
        return array(2002, 2003, 2006, 'HY000');
    }

    public function fetch_db_connection()
    {
        $dsn = 'mysql:host='. $_ENV['DB_HOST'] . ';port='. $_ENV['DB_PORT'] .';dbname='. $_ENV['DB_NAME'];
        try{
            $options = array(PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT);
            $dbh = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
        }
        catch (\PDOException $e) {
            // Proxysql will always accept the connection if its online. However some code does not 
            // connect to Proxysql so we must catch the Exception explicitly
            if (!in_array($e->getCode(), $this->expected_connection_errors())) {
                throw new DbConnectionFailureException($e->getCode(), $e->getMessage(), $e);
            }
            return NULL;
        }
        return $dbh;
    }

    public function verify_db_connection_active($dbh)
    {
        if (empty($dbh)){
            // If the connection couldnt be established $dbh will be NULL
            return FALSE;
        }
        $result = $dbh->query("SELECT 1;");
        if ($result === FALSE) {
            if (!in_array($dbh->errorCode(), $this->expected_connection_errors())) {
                # Uses the driver error code and not the SQL code
                throw new DbConnectionFailureException($dbh->errorInfo()[2], $dbh->errorInfo()[1]);
            }
            return FALSE;
        }
        return TRUE;
    }

    public function update_pantheon_heartbeat($dbh)
    {
        $hb_sql = 'REPLACE INTO _pantheon_heartbeat (actor) VALUES ("resurrection");';
        $result = $dbh->query($hb_sql);
        $error_code = NULL;
        if ($result === FALSE) {
            // Proxysql isn't going to raise an exception
            $error_code = $dbh->errorCode();
        }
        // Handles ERROR 1146 (42S02): Table 'db.tablename' doesn't exist
        if ($error_code == '42S02') {
            // Table might need to be created.
            $create = "CREATE TABLE _pantheon_heartbeat (time TIMESTAMP, actor varchar(64) NOT NULL PRIMARY KEY);";
            $result = $dbh->query($create);
            // Retry update
            $result = $dbh->query($hb_sql);
        }
        return $result;
    }

    public function check_db_empty($dbh)
    {
        $query_result = $dbh->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'pantheon';");
        // Keep reading as long as the table name begins with '_' or 'cache_';
        // databases that have been wiped still contain a couple of cache tables.
        $result_data = '_';
        while ($result_data && (($result_data[0] == '_') || (strpos($result_data, 'cache') === 0))) {
            $result_data = $query_result->fetchColumn();
        }
        $query_result->closeCursor();
        $v = $result_data ? 'ok' : 'empty';
        apc_store('database_is_empty', $v, 20);
        return $v;
    }

    public function resurrect_database($test_db = FALSE)
    {
        $success = false;
        // TEMPORARY: This file will be put in place by chef-solo during an endpoint
        // converge, but PANTHEON_SELECTED_DATABASE won't be defined until each binding
        // converges. This conditional may be removed once all bindings converge.
        $apc_key = 'skip_resurrection_check';
        $proxysql_apc_key = 'proxysql_online';
        if (defined('PANTHEON_SELECTED_DATABASE')) {
            $apc_key = 'skip_resurrection_check_' . PANTHEON_SELECTED_DATABASE;
        }
        if (defined('PANTHEON_BINDING')) {
            $proxysql_apc_key = 'proxysql_online_' . PANTHEON_BINDING;
        }
        if (strlen(PANTHEON_DATABASE_PORT) > 0 && !apc_exists($apc_key)) {
            $dbh = $this->fetch_db_connection();
            if ($this->verify_db_connection_active($dbh)){
                // Don't return a 550; resurrect directly so Drush will work.
                $this->update_pantheon_heartbeat($dbh);
                if ($test_db && (PANTHEON_ENVIRONMENT != 'live') && (PANTHEON_ENVIRONMENT != 'test')) {
                    $this->check_db_empty($dbh);
                }
            }
            else {
                curl_resurrector("GET", "dbserver", "mysql", "start");
                if (!apc_exists($proxysql_apc_key) && !$this->verify_db_connection_active($dbh)) {
                    // When proxysql is restarted so is php. Code execution will stop here.
                    curl_resurrector("GET", "appserver", "proxysql", "try-restart");
                    // Handle Edge case:
                    // If proxysql is not online, we would have gotten a 418 No-Op on the try-restart attempt.
                    // Startup proxysql explicitly and continue.
                    curl_resurrector("GET", "appserver", "proxysql", "start");
                }
                else {
                    apc_store($proxysql_apc_key, TRUE, 300);
                }
            }
            // Store for 10 seconds. On heavy sites, 20 containers, that should be
            // about 2 extra DB connections/REPLACE's per second under steady load.
            // Socket-activated mariaDB should fix this entirely....
            apc_store($apc_key, TRUE, 10);
            // Close the $dhb because this connection is never otherwise used.
            $dbh = NULL;
        }
        // Fetch previous result of empty database check
        $database_status = apc_fetch('database_is_empty', $success);
        if ($success) {
            $_SERVER['PANTHEON_DATABASE_STATE'] = $database_status;
        }
    }
}
