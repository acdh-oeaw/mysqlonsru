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
namespace ACDH\FCSSRU\mysqlonsru;

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
    global $sampleTable;
    
    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $maps = array();
    
    if (stripos($sampleTable, "sampletext") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Profile Sample Text',
            'name' => 'sampleText',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));
    } else if (stripos($sampleTable, "lingfeatures") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Linguistic Features Samples',
            'name' => 'lingfeature',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));
    }
        
    array_push($maps, array(
        'title' => 'Resource Fragement PID',
        'name' => 'rfpid',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    populateExplainResult($db, "$sampleTable", "$sampleTable", $maps);
 }

 /**
  * Searches vicav_profiles_001 database using the lemma column
  * 
  * @uses $responseTemplate
  * @uses $sru_fcs_params
  * @uses $baseURL
  */
 function search() {
    global $sampleTable;
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $description = "";
    $sampleText_query_exact = get_search_term_for_exact_search("sampleText", $sru_fcs_params->query);
    if (!isset($sampleText_query_exact) && (stripos($sampleTable, "sampletext") !== false)) {
        $sampleText_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $sampleText_query = get_search_term_for_wildcard_search("sampleText", $sru_fcs_params->query);
    if (!isset($sampleText_query) && (stripos($sampleTable, "sampletext") !== false)) {
        $sampleText_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
        if (!isset($sampleText_query)) {
            $sampleText_query = $sru_fcs_params->query;
        }         
    }
    $lingfeatureText_query_exact = get_search_term_for_exact_search("lingfeature", $sru_fcs_params->query);
    if (!isset($lingfeatureText_query_exact) && (stripos($sampleTable, "lingfeatures") !== false)) {
        $lingfeatureText_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $lingfeatureText_query = get_search_term_for_wildcard_search("lingfeature", $sru_fcs_params->query);
    if (!isset($lingfeatureText_query) && (stripos($sampleTable, "lingfeatures") !== false)) {
        $lingfeatureText_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
        if (!isset($lingfeatureText_query)) {
            $lingfeatureText_query = $sru_fcs_params->query;
        }
    }
    $options = array (
       "dbtable" => "$sampleTable",
       "distinct-values" => false,
    );     
    
 
    $rfpid_query = get_search_term_for_wildcard_search("rfpid", $sru_fcs_params->query);
    $rfpid_query_exact = get_search_term_for_exact_search("rfpid", $sru_fcs_params->query);
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $db->escape_string($rfpid_query_exact);
        populateSearchResult($db, "SELECT id, entry, sid, 1 FROM $sampleTable WHERE id=$query", "Resource Fragment for pid");
        return;
    } else if (isset($sampleText_query_exact)) {
        $description = "Arabic sample text $sampleText_query_exact";
        $options["xpath"] = "TEI-text-body-div-head-";
        $options["query"] = $sampleText_query_exact;
        $options["exact"] = true;
        $sqlstr = $options;
    } else if (isset($sampleText_query)) {
        $description = "Arabic sample text $sampleText_query";
        $options["xpath"] = "TEI-text-body-div-head-";
        $options["query"] = $sampleText_query;
        $options["exact"] = false;
        $sqlstr = $options;
    } else if (isset($lingfeatureText_query_exact)) {
        $description = "Arabic lingfeature $lingfeatureText_query_exact";
        $options["xpath"] = "TEI-text-body-div-head-";
        $options["query"] = $lingfeatureText_query_exact;
        $options["exact"] = true;
        $sqlstr = $options;
    } else if (isset($lingfeatureText_query)) {
        $description = "Arabic lingfeature $lingfeatureText_query";
        $options["xpath"] = "TEI-text-body-div-head-";
        $options["query"] = $lingfeatureText_query;
        $options["exact"] = false;
        $sqlstr = $options;
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
    global $sampleTable;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === 'rfpid') {
       $sqlstr = "SELECT id, entry, sid FROM $sampleTable ORDER BY CAST(id AS SIGNED)";
       populateScanResult($db, $sqlstr, NULL, true, true);
       return;
    }
    if ($sru_fcs_params->scanClause === 'sampleText' ||
        ((stripos($sampleTable, "sample") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = sqlForXPath($sampleTable, "TEI-text-body-div-head-", array(
           "distinct-values" => true,
       ));
    } else if ($sru_fcs_params->scanClause === 'lingfeature' ||
        ((stripos($sampleTable, "lingfeatures") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = sqlForXPath($sampleTable, "TEI-text-body-div-head-", array(
           "distinct-values" => true,
       ));
         
//    } else if ($sru_fcs_params->scanClause === 'geo') {
//       $sqlstr = sqlForXPath($sampleTable, "geo-"); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    populateScanResult($db, $sqlstr);
}

\ACDH\FCSSRU\getParamsAndSetUpHeader();
$sampleTable = $sru_fcs_params->xcontext;
processRequest();
