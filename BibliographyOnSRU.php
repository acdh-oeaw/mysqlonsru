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

use ACDH\FCSSRU\mysqlonsru\SRUFromMysqlBase;

/**
 * Load configuration and common functions
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . "/common.php";


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
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
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

    array_push($maps, array(
        'title' => 'VICAV Bibliography by author',
        'name' => 'author',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));

    array_push($maps, array(
        'title' => 'VICAV Bibliography by date published',
        'name' => 'imprintDate',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));

    array_push($maps, array(
        'title' => 'Resource Fragement PID',
        'name' => 'rfpid',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
    $base->populateExplainResult($db, "vicav_bibl_002", "vicav-bib", $maps);
 }
 
 /**
  * Searches vicav_profiles_001 database using the lemma column
  * 
  * @uses $sru_fcs_params
  */
 function search()
 {
    global $sru_fcs_params;
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
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
    $profile_query = $base->get_search_term_for_wildcard_search("id", $sru_fcs_params->query);
    if (!isset($profile_query)) {
        $profile_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $profile_query_exact = $base->get_search_term_for_exact_search("id", $sru_fcs_params->query);
    if (!isset($profile_query_exact)) {
        $profile_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $vicavTaxonomy_query = $base->get_search_term_for_wildcard_search("vicavTaxonomy", $sru_fcs_params->query);
    $vicavTaxonomy_query_exact = $base->get_search_term_for_exact_search("vicavTaxonomy", $sru_fcs_params->query);
    $author_query = $base->get_search_term_for_wildcard_search("author", $sru_fcs_params->query);
    $author_query_exact = $base->get_search_term_for_exact_search("author", $sru_fcs_params->query);
    $imprintDate_query_exact = $base->get_search_term_for_exact_search("imprintDate", $sru_fcs_params->query);
    
    $rfpid_query = $base->get_search_term_for_wildcard_search("rfpid", $sru_fcs_params->query);
    $rfpid_query_exact = $base->get_search_term_for_exact_search("rfpid", $sru_fcs_params->query);
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $db->escape_string($rfpid_query_exact);
        populateSearchResult($db, "SELECT id, entry, sid, 1 FROM vicav_bibl_002 WHERE id=$query", "Resource Fragment for pid");
        return;
    } else if (isset($vicavTaxonomy_query_exact)){
        $options["query"] = $db->escape_string($vicavTaxonomy_query_exact);       
        $options["xpath"] = "-index-term-vicavTaxonomy-";
        $options["exact"] = true;
    } else if (isset($vicavTaxonomy_query)) {
        $options["query"] = $db->escape_string($vicavTaxonomy_query);       
        $options["xpath"] = "-index-term-vicavTaxonomy-";        
    } else if (isset($author_query_exact)){
        $options["query"] = $db->escape_string($author_query_exact);       
        $options["xpath"] = "-monogr-author-|-analytic-author-";
        $options["exact"] = true;
    } else if (isset($author_query)) {
        $options["query"] = $db->escape_string($author_query);       
        $options["xpath"] = "-monogr-author-|-analytic-author-";        
    } else if (isset($imprintDate_query_exact)){
        $options["query"] = $db->escape_string($imprintDate_query_exact);       
        $options["xpath"] = "-imprint-date-";
        $options["exact"] = true;
    } else {
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

    $base->populateSearchResult($db, $options, "Bibliography for the region of " . $options["query"]);
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
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === 'rfpid') {
       $sqlstr = "SELECT id, entry, sid FROM vicav_bibl_002 ORDER BY CAST(id AS SIGNED)";
       populateScanResult($db, $sqlstr, NULL, true, true);
       return;
    }
    if ($sru_fcs_params->scanClause === '' ||
        $sru_fcs_params->scanClause === 'id' ||
        $sru_fcs_params->scanClause === 'serverChoice' ||
        $sru_fcs_params->scanClause === 'cql.serverChoice') {
       $sqlstr = $base->sqlForXPath("vicav_bibl_002", "biblStruct-xml:id",
               array("filter" => "-",
                     "distinct-values" => true,
                     "xpath-filters" => array (
                        "-change-f-status-" => "released",
                        ),
                   ));     
    } else if ($sru_fcs_params->scanClause === 'vicavTaxonomy') {
       $sqlstr = $base->sqlForXPath("vicav_bibl_002", "-index-term-vicavTaxonomy-",
               array("filter" => "-",
                     "distinct-values" => true,
                     "xpath-filters" => array (
                        "-change-f-status-" => "released",
                        ),
                   )); 
    } else if ($sru_fcs_params->scanClause === 'author') {
       $sqlstr = $base->sqlForXPath("vicav_bibl_002", "-monogr-author-|-analytic-author-",
               array("filter" => "-",
                     "distinct-values" => true,
                     "xpath-filters" => array (
                        "-change-f-status-" => "released",
                        ),
                   )); 
    } else if ($sru_fcs_params->scanClause === 'imprintDate') {
       $sqlstr = $base->sqlForXPath("vicav_bibl_002", "-imprint-date-",
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
    
    $base->populateScanResult($db, $sqlstr);
}
if (!isset($runner)) {
    \ACDH\FCSSRU\getParamsAndSetUpHeader();
    SRUFromMysqlBase::processRequest();
}

