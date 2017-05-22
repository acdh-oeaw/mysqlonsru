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

class ProfileOnSRU extends SRUFromMysqlBase {

    public function __construct(\ACDH\FCSSRU\SRUWithFCSParameters $params = null) {
        parent::__construct($params);
        $this->extendedSearchResultProcessing = true;
    }
    
public function populateSampleTextResult($escapedQueryString, $db, $region) {
    global $profileTable;

    $description = "Arabic dialect sample text for the region of $region";
    $sqlstr = "SELECT DISTINCT sid, entry FROM $profileTable ";
    $sqlstr.= "WHERE sid " . 
            (strpos($escapedQueryString, '%') !== false ? 'LIKE' : '=') .
            " '$escapedQueryString'";
    try {
        $this->populateSearchResult($db, $sqlstr, $description);
    } catch (ESRUDiagnostics $ex) {
        \ACDH\FCSSRU\diagnostics($ex->getSRUDiagnostics());
    }
}

public function getLemmaWhereClause($query) {
    return "WHERE lemma LIKE '%" . $this->encodecharrefs($query) . "%' OR lemma LIKE '%$query%'";
}

public function getLemmaWhereClauseExact($query) {
    return "WHERE lemma = '" . $this->encodecharrefs($query) . "' OR lemma = '$query'";
}

public function sampleTextQuery($query) {
    return $this->encodecharrefs(strtolower($query));
}

    protected function processSearchResult($line) {
    $glossTable = $this->params->context[0];
    
    $xmlcode = str_replace("\n\n", "\n", $this->decodecharrefs($line[1]));

    $doc = new \DOMDocument();
    
    try {
        $doc->loadXML($xmlcode);    
    } catch (\Exception $exc) {
        array_push($this->errors_array, $exc);
    }

    $xpath = new \DOMXpath($doc);
    $teiHeader = $xpath->query("//teiHeader");

    if ((!is_null($teiHeader)) && ($teiHeader->length === 1) && isset($line[2])) {
       $teiHeader = $teiHeader->item(0);
       $revDescFragment = $doc->createDocumentFragment();
       $revDescFragment->appendXML("<revisionDesc><change>$line[2]</change></revisionDesc>");
       $teiHeader->appendChild($revDescFragment);
    }
    $content = str_replace("<?xml version=\"1.0\"?>", "", $doc->saveXML());
    $content = str_replace("&lt;", "<", str_replace("&gt;", ">", str_replace("&amp;", "&amp;amp;", $content)));
    return $content;
}
}
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
    } else if (stripos($profileTable, "texts") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Project Texts',
            'name' => 'text',
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
    } else if (stripos($profileTable, "tools") !== false) {
        array_push($maps, array(
            'title' => 'VICAV Tools',
            'name' => 'toolsText',
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
    
    $base->populateExplainResult($db, "$profileTable", "$profileTable", $maps);
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
    
    $base = new ProfileOnSRU();
        
    $db = $base->db_connect();
    if ($db instanceof SRUDiagnostics) {
        \ACDH\FCSSRU\diagnostics($db);
        return;
    }   
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->setQuery(str_replace("\"", "", $sru_fcs_params->getQuery()));
    $query = "";
    $description = "";
    $profile_query = $base->get_search_term_for_wildcard_search("profile", $sru_fcs_params->getQuery());
    if (!isset($profile_query) && (stripos($profileTable, "profile") !== false)) {
        $profile_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $geo_query = $base->get_search_term_for_wildcard_search("geo", $sru_fcs_params->getQuery());
    $profile_query_exact = $base->get_search_term_for_exact_search("profile", $sru_fcs_params->getQuery());
    if (!isset($profile_query_exact) && (stripos($profileTable, "profile") !== false)) {
        $profile_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $sampleText_query_exact = $base->get_search_term_for_exact_search("sampleText", $sru_fcs_params->getQuery());
    if (!isset($sampleText_query_exact) && (stripos($profileTable, "sampletext") !== false)) {
        $sampleText_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $sampleText_query = $base->get_search_term_for_wildcard_search("sampleText", $sru_fcs_params->getQuery());
    if (!isset($sampleText_query) && (stripos($profileTable, "sampletext") !== false)) {
        $sampleText_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }    
    $text_query_exact = $base->get_search_term_for_exact_search("text", $sru_fcs_params->getQuery());
    if (!isset($text_query_exact) && (stripos($profileTable, "texts") !== false)) {
        $text_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $text_query = $base->get_search_term_for_wildcard_search("text", $sru_fcs_params->getQuery());
    if (!isset($text_query) && (stripos($profileTable, "texts") !== false)) {
        $text_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $metaText_query_exact = $base->get_search_term_for_exact_search("metaText", $sru_fcs_params->getQuery());
    if (!isset($metaText_query_exact) && (stripos($profileTable, "meta") !== false)) {
        $metaText_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $metaText_query = $base->get_search_term_for_wildcard_search("metaText", $sru_fcs_params->getQuery());
    if (!isset($metaText_query) && (stripos($profileTable, "meta") !== false)) {
        $metaText_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $toolsText_query_exact = $base->get_search_term_for_exact_search("toolsText", $sru_fcs_params->getQuery());
    if (!isset($toolsText_query_exact) && (stripos($profileTable, "tools") !== false)) {
        $toolsText_query_exact = $base->get_search_term_for_exact_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $toolsText_query = $base->get_search_term_for_wildcard_search("toolsText", $sru_fcs_params->getQuery());
    if (!isset($toolsText_query) && (stripos($profileTable, "tools") !== false)) {
        $toolsText_query = $base->get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->getQuery(), "cql");
    }
    $geo_query_exact = $base->get_search_term_for_exact_search("geo", $sru_fcs_params->getQuery());
    // there is no point in having a fuzzy geo search yet so treat it as exact always
    if (!isset($geo_query_exact)) {
        $geo_query_exact = $geo_query;
    }
    $options = array (
       "dbtable" => "$profileTable",
       "query" => $query,
       "distinct-values" => false,
    );
 
    $rfpid_query = $base->get_search_term_for_wildcard_search("rfpid", $sru_fcs_params->getQuery());
    $rfpid_query_exact = $base->get_search_term_for_exact_search("rfpid", $sru_fcs_params->getQuery());
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $db->escape_string($rfpid_query_exact);
        try {
            $base->populateSearchResult($db, "SELECT id, entry, sid, 1 FROM $profileTable WHERE id=$query", "Resource Fragment for pid");    
        } catch (ESRUDiagnostics $ex) {
            \ACDH\FCSSRU\diagnostics($ex->getSRUDiagnostics());
        }
        return;
    } else if (isset($sampleText_query_exact)) {
        $regionGuess = explode('_', $sampleText_query_exact);
        $base->populateSampleTextResult($db->escape_string($sampleText_query_exact), $db, $regionGuess[0]);
        return;
    } else if (isset($sampleText_query)) {
        $regionGuess = explode('_', $sampleText_query);
        $base->populateSampleTextResult("%" . $db->escape_string(strtolower($sampleText_query)) . "%", $db, $regionGuess[0]);
        return;
    } else if (isset($geo_query_exact)){
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
       } else if (isset($text_query_exact)) {
           $query = $db->escape_string($text_query_exact);
       } else if (isset($text_query)) {
           $query = $db->escape_string($text_query);
       } else if (isset($toolsText_query_exact)) {
           $query = $db->escape_string($toolsText_query_exact);
       } else if (isset($toolsText_query)) {
           $query = $db->escape_string($toolsText_query);
       } else {
           $query = $db->escape_string($sru_fcs_params->getQuery());
       }
       $description = "Arabic dialect profile for the region of $query"; 
       $sqlstr = "SELECT DISTINCT id, entry, status FROM $profileTable ";
       if (isset($profile_query_exact) || isset($metaText_query_exact) || isset($text_query_exact)) {
          $sqlstr.= $base->getLemmaWhereClauseExact($query); 
       } else {
           if ((stripos($profileTable, "profile") !== false) ||
               (stripos($profileTable, "meta") !== false) ||
               (stripos($profileTable, "texts") !== false) ||
               (stripos($profileTable, "tools") !== false)) {
                $sqlstr.= $base->getLemmaWhereClause($query);
           } else if (stripos($profileTable, "sampletext") !== false) {
                $regionGuess = explode('_', $query);                
                $base->populateSampleTextResult("%" . $db->escape_string($base->sampleTextQuery($query)) . "%", $db, $regionGuess[0]);
                return;
            }
        }
    }
    try {
        $base->populateSearchResult($db, $sqlstr, $description);
    } catch (ESRUDiagnostics $ex) {
        \ACDH\FCSSRU\diagnostics($ex->getSRUDiagnostics());
    }
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
    
    $base = new SRUFromMysqlBase();
        
    $db = $base->db_connect();
    if ($db instanceof SRUDiagnostics) {
        \ACDH\FCSSRU\diagnostics($db);
        return;
    }
    
    $sqlstr = '';
    
    if ($sru_fcs_params->scanClause === 'rfpid') {
        $sqlstr = "SELECT id, entry, sid FROM $profileTable ORDER BY CAST(id AS SIGNED)";
        try {
            $base->populateScanResult($db, $sqlstr, NULL, true, true);
        } catch (ESRUDiagnostics $ex) {
            \ACDH\FCSSRU\diagnostics($ex->getSRUDiagnostics());
        }           
        return;
    }
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
    } else if ($sru_fcs_params->scanClause === 'text' ||
        ((stripos($profileTable, "texts") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = "SELECT DISTINCT lemma, id, sid, COUNT(*) FROM $profileTable " .
              "WHERE lemma NOT LIKE '[%]' GROUP BY lemma";           
    } else if ($sru_fcs_params->scanClause === 'toolsText' ||
        ((stripos($profileTable, "tools") !== false) &&
        ($sru_fcs_params->scanClause === '' ||
         $sru_fcs_params->scanClause === 'serverChoice' ||
         $sru_fcs_params->scanClause === 'cql.serverChoice'))) {
       $sqlstr = "SELECT DISTINCT lemma, id, sid, COUNT(*) FROM $profileTable " .
              "WHERE lemma NOT LIKE '[%]' GROUP BY lemma";           
    } else if ($sru_fcs_params->scanClause === 'geo') {
       $sqlstr = $base->scanSqlForXPath("$profileTable", "geo-",
               array("show-lemma" => true,
                     "distinct-values" => true,
           )); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    try {        
        $base->populateScanResult($db, $sqlstr);
    } catch (ESRUDiagnostics $ex) {
        \ACDH\FCSSRU\diagnostics($ex->getSRUDiagnostics());
    }
}
if (!isset($runner)) {
    \ACDH\FCSSRU\getParamsAndSetUpHeader();
    $profileTable = $sru_fcs_params->xcontext;
    SRUFromMysqlBase::processRequest();
}

