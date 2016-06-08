<?php

/* 
 * The MIT License
 *
 * Copyright 2016 OEAW/ACDH.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

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

use ACDH\FCSSRU\mysqlonsru\SRUFromMysqlBase,
    ACDH\FCSSRU\SRUDiagnostics;

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
 * @uses $explainTemplate
 */
 function explain()
 {
    global $sampleTable;
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
    if ($db instanceof SRUDiagnostics) {
        $base->populateSearchResult($db, '', '');
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
    
    $base->populateExplainResult($db, "$sampleTable", "$sampleTable", $maps);
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
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
    if ($db instanceof SRUDiagnostics) {
        $base->populateSearchResult($db, '', '');
        return;
    }  
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->setQuery(str_replace("\"", "", $sru_fcs_params->getQuery()));
    $description = "";
    $sampleText_query_exact = $base->get_search_term_for_exact_search("sampleText", $sru_fcs_params->getQuery());
    if (!isset($sampleText_query_exact) && (stripos($sampleTable, "sampletext") !== false)) {
        $sampleText_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $sampleText_query = $base->get_search_term_for_wildcard_search("sampleText", $sru_fcs_params->getQuery());
    if (!isset($sampleText_query) && (stripos($sampleTable, "sampletext") !== false)) {
        $sampleText_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
        if (!isset($sampleText_query)) {
            $sampleText_query = $sru_fcs_params->getQuery();
        }         
    }
    $lingfeatureText_query_exact = $base->get_search_term_for_exact_search("lingfeature", $sru_fcs_params->getQuery());
    if (!isset($lingfeatureText_query_exact) && (stripos($sampleTable, "lingfeatures") !== false)) {
        $lingfeatureText_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $lingfeatureText_query = $base->get_search_term_for_wildcard_search("lingfeature", $sru_fcs_params->getQuery());
    if (!isset($lingfeatureText_query) && (stripos($sampleTable, "lingfeatures") !== false)) {
        $lingfeatureText_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
        if (!isset($lingfeatureText_query)) {
            $lingfeatureText_query = $sru_fcs_params->getQuery();
        }
    }
    $options = array (
       "dbtable" => "$sampleTable",
       "distinct-values" => false,
    );     
    
 
    $rfpid_query = $base->get_search_term_for_wildcard_search("rfpid", $sru_fcs_params->getQuery());
    $rfpid_query_exact = $base->get_search_term_for_exact_search("rfpid", $sru_fcs_params->getQuery());
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $db->escape_string($rfpid_query_exact);
        $base->populateSearchResult($db, "SELECT id, entry, sid, 1 FROM $sampleTable WHERE id=$query", "Resource Fragment for pid");
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
    $base->populateSearchResult($db, $sqlstr, $description);
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
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === 'rfpid') {
       $sqlstr = "SELECT id, entry, sid FROM $sampleTable ORDER BY CAST(id AS SIGNED)";
       $base->populateScanResult($db, $sqlstr, NULL, true, true);
       return;
    }
    if ($sru_fcs_params->scanClause === 'sampleText' ||
        ((stripos($sampleTable, "sample") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = $base->sqlForXPath($sampleTable, "TEI-text-body-div-head-", array(
           "distinct-values" => true,
       ));
    } else if ($sru_fcs_params->scanClause === 'lingfeature' ||
        ((stripos($sampleTable, "lingfeatures") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = $base->sqlForXPath($sampleTable, "TEI-text-body-div-head-", array(
           "distinct-values" => true,
       ));
         
//    } else if ($sru_fcs_params->scanClause === 'geo') {
//       $sqlstr = $base->sqlForXPath($sampleTable, "geo-"); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    $base->populateScanResult($db, $sqlstr);
}
if (!isset($runner)) {
    \ACDH\FCSSRU\getParamsAndSetUpHeader();
    $sampleTable = $sru_fcs_params->xcontext;
    SRUFromMysqlBase::processRequest();
}
