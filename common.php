<?php

/**
 * Common functions used by all the scripts using the mysql database.
 * 
 * @uses $dbConfigfile
 * @package mysqlonsru
 */
/**
 * Configuration options and function common to all fcs php scripts
 */
require_once "../utils-php/common.php";

/**
 * Load database and user data
 */
require_once $dbConfigFile;

/**
 * Get a database connection object (currently mysqli)
 * 
 * @uses $server
 * @uses $user
 * @uses $password
 * @uses $database
 * @return \mysqli
 */
function db_connect() {
    global $server;
    global $user;
    global $password;
    global $database;

    $db = new mysqli($server, $user, $password, $database);
    if ($db->connect_errno) {
        diagnostics(1, 'MySQL Connection Error: Failed to connect to database: (' . $db->connect_errno . ") " . $db->connect_error);
    }
    return $db;
}

/**
 * Process custom encoding used by web_dict databases
 * 
 * @param string $str
 * @return string
 */
function decodecharrefs($str) {
    $replacements = array(
        "#9#" => ";",
        "#8#" => "&#",
//     "%gt" => "&gt;",
//     "%lt" => "&lt;",
//     "&#amp;" => "&amp;",
//     "&#x" => "&x",
    );
    foreach ($replacements as $search => $replace) {
        $str = str_replace($search, $replace, $str);
    }
    return $str;
}

