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
    
    $tmpl->setLoop('maps', $maps);
    
    $tmpl->setVar('hostid', htmlentities($_SERVER["HTTP_HOST"]));
    $tmpl->setVar('database', 'vicav-bib');
    $tmpl->setVar('databaseTitle', 'VICAV Bibliography');
    $tmpl->pparse();
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
    $query = "";
    $profile_query = preg_filter('/id *(=|any) *(.*)/', '$2', $sru_fcs_params->query);
    if (!isset($profile_query)) {
        $profile_query = preg_filter('/(cql\.)?serverChoice *(=|any) *(.*)/', '$3', $sru_fcs_params->query);
    }
    $vicavTaxonomy_query = preg_filter('/vicavTaxonomy *(=|any) *(.*)/', '$2', $sru_fcs_params->query);
    
    if (isset($vicavTaxonomy_query)){
        $query = $db->escape_string($vicavTaxonomy_query);
        $sqlstr = sqlForXPath("vicav_bibl_002", "-index-term-vicavTaxonomy-",
                array("query" => $query,
                      "distinct-values" => false,
                    ));
    } else {
       if (isset($profile_query)) {
           $query = $db->escape_string($profile_query);
       } else {
           $query = $db->escape_string($sru_fcs_params->query);
       }
       $sqlstr = sqlForXPath("vicav_bibl_002", "biblStruct-xml:id",
               array("query" => $query,
                     "distinct-values" => false,
                   ));
    }

    populateSearchResult($db, $sqlstr, "Bibliography for the region of $query");
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
                   ));     
    } else if ($sru_fcs_params->scanClause === 'vicavTaxonomy') {
       $sqlstr = sqlForXPath("vicav_bibl_002", "-index-term-vicavTaxonomy-",
               array("filter" => "-",
                     "distinct-values" => true,
                   )); 
    } else {
        diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    populateScanResult($db, $sqlstr);
}

getParamsAndSetUpHeader();
processRequest();

