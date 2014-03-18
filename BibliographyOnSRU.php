<?php
/**
 * This script provides SRU access to a mysql database containing TEI data
 * language profiles.
 * This script is responsible for handling FCS requests for bibliographic data. 
 * 
 * @uses $dbConfigfile
 * @uses $operation
 * @uses $query
 * @uses $version
 * @uses $scanClause
 * @uses responseTemplate
 * @uses responseTemplateFcs
 * @package mysqlonsru
 */

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
    
    array_push($maps, array(
        'title' => 'VICAV Bibliography',
        'name' => 'id',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    array_push($maps, array(
        'title' => 'VICAV Bibliography by VICAV Taxonomy',
        'name' => 'vicavTaxonomy',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    populateExplainResult($db, "vicav_bibl_002", "vicav-bib", $maps);
 }
 
 /**
  * Searches vicav_profiles_001 database using the lemma column
  * 
  * @uses $sru_fcs_params
  */
 function search()
 {
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $options = array("distinct-values" => false,
       "dbtable" => "vicav_bibl_002",
       "xpath-filters" => array (
         "-change-f-status-" => "released",
         ),
       );
    $options["startRecord"] = $sru_fcs_params->startRecord;
    $options["maximumRecords"] = $sru_fcs_params->maximumRecords;
    $profile_query = get_search_term_for_wildcard_search("id", $sru_fcs_params->query);
    if (!isset($profile_query)) {
        $profile_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $vicavTaxonomy_query = get_search_term_for_wildcard_search("vicavTaxonomy", $sru_fcs_params->query);
    $profile_query_exact = get_search_term_for_exact_search("id", $sru_fcs_params->query);
    if (!isset($profile_query_exact)) {
        $profile_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $vicavTaxonomy_query_exact = get_search_term_for_exact_search("vicavTaxonomy", $sru_fcs_params->query);
    
    if (isset($vicavTaxonomy_query_exact)){
        $options["query"] = $db->escape_string($vicavTaxonomy_query_exact);       
        $options["xpath"] = "-index-term-vicavTaxonomy-";
        $options["exact"] = true;
    } else if (isset($vicavTaxonomy_query)) {
        $options["query"] = $db->escape_string($vicavTaxonomy_query);       
        $options["xpath"] = "-index-term-vicavTaxonomy-";        
    } else{
       if (isset($profile_query_exact)) {
           $options["query"] = $db->escape_string($profile_query_exact);
           $options["exact"] = true;
       } else if (isset($profile_query)) {
           $options["query"] = $db->escape_string($profile_query);
       } else {
           $options["query"] = $db->escape_string($sru_fcs_params->query);
       }
       $options["xpath"] = "biblStruct-xml:id";
    }

    populateSearchResult($db, $options, "Bibliography for the region of " . $options["query"]);
}

 /**
 * Lists the entries from the lemma column in the vicav_profiles_001 database
 * 
 * Lists either the profiles (city names) or the sample texts ([id])
 * 
 * @see http://www.loc.gov/standards/sru/specs/scan.html
 * 
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
        $sru_fcs_params->scanClause === 'id' ||
        $sru_fcs_params->scanClause === 'serverChoice' ||
        $sru_fcs_params->scanClause === 'cql.serverChoice') {
       $sqlstr = sqlForXPath("vicav_bibl_002", "biblStruct-xml:id",
               array("filter" => "-",
                     "distinct-values" => true,
                     "xpath-filters" => array (
                        "-change-f-status-" => "released",
                        ),
                   ));     
    } else if ($sru_fcs_params->scanClause === 'vicavTaxonomy') {
       $sqlstr = sqlForXPath("vicav_bibl_002", "-index-term-vicavTaxonomy-",
               array("filter" => "-",
                     "distinct-values" => true,
                     "xpath-filters" => array (
                        "-change-f-status-" => "released",
                        ),
                   )); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    populateScanResult($db, $sqlstr);
}

\ACDH\FCSSRU\getParamsAndSetUpHeader();
processRequest();

