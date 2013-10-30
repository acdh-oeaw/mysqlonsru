<?php
/**
 * This script provides SRU access to a mysql database containing TEI data
 * language profiles.
 * This script is responsible for handling FCS requests for language profile data. 
 * 
 * @uses $dbConfigfile
 * @uses $sru_fcs_params
 * @uses $responseTemplate
 * @uses $responseTemplateFcs
 * @package mysqlonsru
 */
//error_reporting(E_ALL);

/**
 * Load configuration and common functions
 */
require_once "common.php";

/**
 * Generates a response according to ZeeRex
 * 
 * This is a machine readable description of this script's capabilities.
 * 
 * @see http://zeerex.z3950.org/overview/index.html
 * 
 * @uses $explainTemplate
 */
 function explain()
 {
    global $explainTemplate;

    $tmpl = new vlibTemplate($explainTemplate);
    
    $maps = array();
    
    array_push($maps, array(
        'title' => 'VICAV Profile',
        'name' => 'profile',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    array_push($maps, array(
        'title' => 'VICAV Profile Sample Text',
        'name' => 'sampleText',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    array_push($maps, array(
        'title' => 'VICAV Profile Geo Coordinates',
        'name' => 'geo',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    $tmpl->setLoop('maps', $maps);
    
    $tmpl->setVar('hostid', htmlentities($_SERVER["HTTP_HOST"]));
    $tmpl->setVar('database', 'vicav-profile');
    $tmpl->setVar('databaseTitle', 'VICAV Profile');
    $tmpl->pparse();
 }

 /**
  * Searches vicav_profiles_001 database using the lemma column
  * 
  * @uses $responseTemplate
  * @uses $sru_fcs_params
  * @uses $baseURL
  */
 function search() {
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $query = "";
    $description = "";
    $profile_query = preg_filter('/profile *(=|any) *(.*)/', '$2', $sru_fcs_params->query);
    if (!isset($profile_query)) {
        $profile_query = preg_filter('/(cql\.)?serverChoice *(=|any) *(.*)/', '$3', $sru_fcs_params->query);
    }
    $sampleText_query = preg_filter('/sampleText *(=|any) *(.*)/', '$2', $sru_fcs_params->query);
    $geo_query = preg_filter('/geo *(=|any) *(.*)/', '$2', $sru_fcs_params->query);
    if (isset($sampleText_query)) {
        $query = $db->escape_string($sampleText_query);
        $description = "Arabic dialect sample text for the region of $query";
        $sqlstr = "SELECT DISTINCT sid, entry FROM vicav_profiles_001 ";
        $sqlstr.= "WHERE sid = '$query'";
    } else if (isset($geo_query)){
        $query = $db->escape_string($geo_query);
        $description = "Arabic dialect profile for the coordinates $query";
        $sqlstr = sqlForXPath("vicav_profiles_001", "geo-", array("query" => $query));
    } else {
       if (isset($profile_query)) {
           $query = $db->escape_string($profile_query);
       } else {
           $query = $db->escape_string($sru_fcs_params->query);
       }
       $description = "Arabic dialect profile for the region of $query"; 
       $sqlstr = "SELECT DISTINCT id, entry FROM vicav_profiles_001 ";
       $sqlstr.= "WHERE lemma = '$query'";
    }   
    populateSearchResult($db, $sqlstr, $description);
}

/**
 * Lists the entries from the lemma column in the vicav_profiles_001 database
 * 
 * Lists either the profiles (city names) or the sample texts ([id])
 * 
 * @see http://www.loc.gov/standards/sru/specs/scan.html
 * 
 * @uses $scanTemplate
 * @uses $sru_fcs_params
 */
function scan() {
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === '' ||
        $sru_fcs_params->scanClause === 'profile' ||
        $sru_fcs_params->scanClause === 'serverChoice' ||
        $sru_fcs_params->scanClause === 'cql.serverChoice') {
       $sqlstr = "SELECT DISTINCT lemma, id FROM vicav_profiles_001 " .
              "WHERE lemma NOT LIKE '[%]'";   
    } else if ($sru_fcs_params->scanClause === 'sampleText') {
       $sqlstr = "SELECT DISTINCT sid, id FROM vicav_profiles_001 " .
              "WHERE sid LIKE '%_sample_%'";           
    } else if ($sru_fcs_params->scanClause === 'geo') {
       $sqlstr = sqlForXPath("vicav_profiles_001", "geo-"); 
    } else {
        diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    populateScanResult($db, $sqlstr);
}

getParamsAndSetUpHeader();
processRequest();

