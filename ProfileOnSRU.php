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
 */
 function explain()
 {
    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $maps = array();
    
    global $profileTable;
    if (stripos($profileTable, "profile") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Profile',
            'name' => 'profile',
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
    } else if (stripos($profileTable, "sampletext") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Profile Sample Text',
            'name' => 'sampleText',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));
    } else if (stripos($profileTable, "meta") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Meta Text',
            'name' => 'metaText',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));
    }
    populateExplainResult($db, "$profileTable", "$profileTable", $maps);
 }

 /**
  * Searches $profileTable database using the lemma column
  * 
  * @uses $responseTemplate
  * @uses $sru_fcs_params
  * @uses $baseURL
  */
 function search() {
    global $profileTable;
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $query = "";
    $description = "";
    $profile_query = get_search_term_for_wildcard_search("profile", $sru_fcs_params->query);
    if (!isset($profile_query) && (stripos($profileTable, "profile") !== false)) {
        $profile_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $geo_query = get_search_term_for_wildcard_search("geo", $sru_fcs_params->query);
    $profile_query_exact = get_search_term_for_exact_search("profile", $sru_fcs_params->query);
    if (!isset($profile_query_exact) && (stripos($profileTable, "profile") !== false)) {
        $profile_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $sampleText_query_exact = get_search_term_for_exact_search("sampleText", $sru_fcs_params->query);
    if (!isset($sampleText_query_exact) && (stripos($profileTable, "sampletext") !== false)) {
        $sampleText_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $sampleText_query = get_search_term_for_wildcard_search("sampleText", $sru_fcs_params->query);
    if (!isset($sampleText_query) && (stripos($profileTable, "sampletext") !== false)) {
        $sampleText_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $metaText_query_exact = get_search_term_for_exact_search("metaText", $sru_fcs_params->query);
    if (!isset($metaText_query_exact) && (stripos($profileTable, "meta") !== false)) {
        $metaText_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $metaText_query = get_search_term_for_wildcard_search("metaText", $sru_fcs_params->query);
    if (!isset($metaText_query) && (stripos($profileTable, "meta") !== false)) {
        $metaText_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $geo_query_exact = get_search_term_for_exact_search("geo", $sru_fcs_params->query);
    // there is no point in having a fuzzy geo search yet so treat it as exact always
    if (!isset($geo_query_exact)) {
        $geo_query_exact = $geo_query;
    }
    $options = array (
       "dbtable" => "$profileTable",
       "query" => $query,
       "distinct-values" => false,
    );
    if (isset($sampleText_query_exact)) {
        $regionGuess = explode('_', $sampleText_query_exact);
        populateSampleTextResult($db->escape_string($sampleText_query_exact), $db, $regionGuess[0]);
        return;
    } else if (isset($sampleText_query)) {
        $regionGuess = explode('_', $sampleText_query);
        populateSampleTextResult("%" . $db->escape_string(strtolower($sampleText_query)) . "%", $db, $regionGuess[0]);
        return;
    } else if (isset($geo_query_exact)){
        $query = $db->escape_string($geo_query_exact);
        $description = "Arabic dialect profile for the coordinates $geo_query_exact";
        $options["xpath"] = "geo-";
        $options["query"] = $geo_query_exact;
        $options["exact"] = true;
        $sqlstr = $options;
    } else {
       if (isset($profile_query_exact)) {
           $query = $db->escape_string($profile_query_exact);
       } else if (isset($profile_query)) {
           $query = $db->escape_string($profile_query);
       } else if (isset($metaText_query_exact)) {
           $query = $db->escape_string($metaText_query_exact);
       } else if (isset($metaText_query)) {
           $query = $db->escape_string($metaText_query);
       } else {
           $query = $db->escape_string($sru_fcs_params->query);
       }
       $description = "Arabic dialect profile for the region of $query"; 
       $sqlstr = "SELECT DISTINCT id, entry FROM $profileTable ";
       if (isset($profile_query_exact) || isset($metaText_query_exact)) {
          $sqlstr.= "WHERE lemma = '" . encodecharrefs($query) . "'"; 
       } else {
           if ((stripos($profileTable, "profile") !== false) || (stripos($profileTable, "meta") !== false)) {
                $sqlstr.= "WHERE lemma LIKE '%" . encodecharrefs($query) . "%'";
           } else if (stripos($profileTable, "sampletext") !== false) {
                $regionGuess = explode('_', $query);
                
                populateSampleTextResult("%" . $db->escape_string(encodecharrefs(strtolower($query))) . "%", $db, $regionGuess[0]);
                return;
            }
        }
    }   
    populateSearchResult($db, $sqlstr, $description);
}

function populateSampleTextResult($escapedQueryString, $db, $region) {
    global $profileTable;

    $description = "Arabic dialect sample text for the region of $region";
    $sqlstr = "SELECT DISTINCT sid, entry FROM $profileTable ";
    $sqlstr.= "WHERE sid " . 
            (strpos($escapedQueryString, '%') !== false ? 'LIKE' : '=') .
            " '$escapedQueryString'";
    populateSearchResult($db, $sqlstr, $description);
}
/**
 * Lists the entries from the lemma column in the $profileTable database
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
    global $profileTable;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === 'profile' ||
        ((stripos($profileTable, "profile") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = "SELECT DISTINCT lemma, id, sid, COUNT(*) FROM $profileTable " .
              "WHERE lemma NOT LIKE '[%]' GROUP BY lemma";   
    } else if ($sru_fcs_params->scanClause === 'sampleText' ||
        ((stripos($profileTable, "sampletext") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = "SELECT DISTINCT sid, id, sid, COUNT(*) FROM $profileTable " .
              "WHERE sid LIKE '%_sample_%' GROUP BY sid";           
    } else if ($sru_fcs_params->scanClause === 'metaText' ||
        ((stripos($profileTable, "meta") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = "SELECT DISTINCT lemma, id, sid, COUNT(*) FROM $profileTable " .
              "WHERE lemma NOT LIKE '[%]' GROUP BY lemma";           
    } else if ($sru_fcs_params->scanClause === 'geo') {
       $sqlstr = sqlForXPath("$profileTable", "geo-",
               array("show-lemma" => true,
                     "distinct-values" => true,
           )); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    populateScanResult($db, $sqlstr);
}

\ACDH\FCSSRU\getParamsAndSetUpHeader();
$profileTable = $sru_fcs_params->xcontext;
processRequest();

