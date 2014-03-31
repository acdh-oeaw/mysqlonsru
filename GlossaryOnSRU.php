<?php
/**
 * This script provides SRU access to a mysql database containing TEI encoded
 * glossaries/dictionaries.
 * This script is responsible for handling FCS requests for glossary data. 
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

$restrictedGlossaries = array(
    "apc_eng_002",
);

function langId2LangName($langId) {
    $langIds = array(
        "arz" => "Egyptian",
        "apc" => "Syrian",
        "aeb" => "Tunisian",
        "eng" => "English/German",
    );
    if (isset($langIds[$langId])) {
        $langId = $langIds[$langId];
    }
    return $langId;
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
    global $glossTable;
    
    if (!isset($glossTable)) {
        \ACDH\FCSSRU\diagnostics(1, 'This script needs to know which resource to use!');
        return; 
    }
    
    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $resIdParts = explode("_", $glossTable);
    $langId = langId2LangName($resIdParts[0]);
    $transLangId = langId2LangName($resIdParts[1]);
    $maps = array();
 
    array_push($maps, array(
        'title' => "VICAV $langId - $transLangId any entry",
        'name' => 'entry',
        'search' => 'true',
        'scan' => 'true',
        'sort' => 'false',
    ));
    
        array_push($maps, array(
        'title' => "VICAV $langId - $transLangId translated sense",
        'name' => 'sense',
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
        
    populateExplainResult($db, $glossTable, $glossTable, $maps);
 }

 function processSearchResult($line, $db) {
    global $glossTable;

    $xmlcode = str_replace("\n\n", "\n", decodecharrefs($line[1]));

    $doc = new \DOMDocument();
    $doc->loadXML($xmlcode);

    $xpath = new \DOMXpath($doc);
    $elements = $xpath->query("//ptr[@type='example']");

    if ((!is_null($elements)) && ($elements->length != 0)) {
        $attr = array();
        foreach ($elements as $element) {
            $attr[] = "'" . $element->attributes->getNamedItem("target")->nodeValue . "'";
        }

        if (count($attr) != 0) {
            $hstr = "SELECT sid, entry FROM $glossTable WHERE sid IN (";
            $hstr .= implode(",", $attr);
            $hstr .= ")";
            //print $hstr;

            $subresult = $db->query($hstr);
            while ($subline = $subresult->fetch_row()) {
                $elements = $xpath->query("//ptr[@target='" . $subline[0] . "']");
                if ((!is_null($elements)) && ($elements->length != 0)) {
                    // XML ID will be twice in the result as this script selects
                    // every occurence of the query. Better solved using XQuery
                    // -> TODO
                    $subline[1] = preg_replace('/xml:id=["\'][^\\s]*["\']/', "", $subline[1]);
                    $newNodeParent = $doc->createElement('dummy', $subline[1]);

                    if ($newNodeParent->hasChildNodes() === TRUE) {
                        $newNode = $newNodeParent->childNodes->item(0);
                    }

                    $oldNode = $elements->item(0);

                    $parent = $oldNode->parentNode;
                    $parent->replaceChild($newNode, $oldNode);
                }
            }
        }        
    }
    $content = str_replace("<?xml version=\"1.0\"?>", "", $doc->saveXML());
    $content = str_replace("&lt;", "<", str_replace("&gt;", ">", $content));
    return $content;
}
 
/**
 * 
 * @uses $glossTable
 * @uses $sru_fcs_params
 * @uses $restrictedGlossaries
 */
 function search()
 {
    global $glossTable;
    global $sru_fcs_params;
    global $restrictedGlossaries;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $options = array("filter" => "-",
                     "distinct-values" => false,
        );
    $options["startRecord"] = $sru_fcs_params->startRecord;
    $options["maximumRecords"] = $sru_fcs_params->maximumRecords;
    $lemma_query = get_search_term_for_wildcard_search("entry", $sru_fcs_params->query);
    if (!isset($lemma_query)) {
        $lemma_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $lemma_query_exact = get_search_term_for_exact_search("entry", $sru_fcs_params->query);
    if (!isset($lemma_query_exact)) {
        $lemma_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->query, "cql");
    }
    $sense_query_exact = get_search_term_for_exact_search("sense", $sru_fcs_params->query);
    $sense_query = get_search_term_for_wildcard_search("sense", $sru_fcs_params->query);
 
    $rfpid_query = get_search_term_for_wildcard_search("rfpid", $sru_fcs_params->query);
    $rfpid_query_exact = get_search_term_for_exact_search("rfpid", $sru_fcs_params->query);
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $db->escape_string($rfpid_query_exact);
        populateSearchResult($db, "SELECT id, entry, sid, 1 FROM $glossTable WHERE id=$query", "Resource Fragment for pid");
        return;
    } else if (isset($sense_query_exact)) {
        $options["query"] = $db->escape_string($sense_query_exact);
        $options["xpath"] = "-quote-";
        $options["exact"] = true;
    } else if (isset($sense_query)) {
        $options["query"] = $db->escape_string($sense_query);
        $options["xpath"] = "-quote-";
    } else if (isset($lemma_query_exact)) {
        $options["query"] = $db->escape_string($lemma_query_exact);
        $options["exact"] = true;
    } else if (isset($lemma_query)) {
        $options["query"] = $db->escape_string($lemma_query);
    } else {
        $options["query"] = $db->escape_string($sru_fcs_params->query);
    }
    $options["dbtable"] = $glossTable;
    
    if (in_array($glossTable, $restrictedGlossaries)) {
        $options["xpath-filters"] = array (
            "-change-f-status-" => "released",
        );
    }

    populateSearchResult($db, $options, "Glossary for " . $options["query"], '\\ACDH\\FCSSRU\\mysqlonsru\\processSearchResult');
 }

  /**
 * Lists the entries from the lemma column in the database
 * 
 * Lists either the profiles (city names) or the sample texts ([id])
 * 
 * @see http://www.loc.gov/standards/sru/specs/scan.html
 * 
 * @uses $sru_fcs_params
 * @uses $glossTable
 * @uses $restrictedGlossaries
 */
function scan() {
    global $glossTable;
    global $sru_fcs_params;
    global $restrictedGlossaries;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    $sqlstr = '';
    $options = array("filter" => "-",
                     "distinct-values" => true,
                     "query" => "", // the database can't sort or filter due to encoding
                   );
    if (in_array($glossTable, $restrictedGlossaries)) {
        $options["xpath-filters"] = array (
            "-change-f-status-" => "released",
        );
    }
    
    if ($sru_fcs_params->scanClause === 'rfpid') {
       $sqlstr = "SELECT id, entry, sid FROM $glossTable ORDER BY CAST(id AS SIGNED)";
       populateScanResult($db, $sqlstr, NULL, true, true);
       return;
    }
    if ($sru_fcs_params->scanClause === '' ||
        strpos($sru_fcs_params->scanClause, 'entry') === 0 ||
        strpos($sru_fcs_params->scanClause, 'serverChoice') === 0 ||
        strpos($sru_fcs_params->scanClause, 'cql.serverChoice') === 0) {
       $sqlstr = sqlForXPath($glossTable, "", $options);     
    } else if (strpos($sru_fcs_params->scanClause, 'sense') === 0) {
       $sqlstr = sqlForXPath($glossTable, "-quote-", $options); 
    } else {
        \ACDH\FCSSRU\diagnostics(51, 'Result set: ' . $sru_fcs_params->scanClause);
        return;
    }
    
    $lemma_query = get_search_term_for_wildcard_search("entry", $sru_fcs_params->scanClause);
    if (!isset($lemma_query)) {
        $lemma_query = get_search_term_for_wildcard_search("serverChoice", $sru_fcs_params->scanClause, "cql");
    }
    $lemma_query_exact = get_search_term_for_exact_search("entry", $sru_fcs_params->scanClause);
    if (!isset($lemma_query_exact)) {
        $lemma_query_exact = get_search_term_for_exact_search("serverChoice", $sru_fcs_params->scanClause, "cql");
    }
    
    $exact = false;
    $scanClause = ""; // a scan clause that is no index cannot be used.
    if (isset($lemma_query_exact)) { // lemma query matches lemma query exact also!
        $wildCardSearch = get_wild_card_search($lemma_query_exact);
        $scanClause = isset($wildCardSearch) ? $wildCardSearch : $lemma_query_exact;
        $exact = true;
    } else if (isset($lemma_query)) {
        $wildCardSearch = get_wild_card_search($lemma_query);
        $scanClause = isset($wildCardSearch) ? $wildCardSearch : $lemma_query;
        $exact = false;
    }
    
    populateScanResult($db, $sqlstr, $scanClause, $exact);
}
\ACDH\FCSSRU\getParamsAndSetUpHeader();
$glossTable = $sru_fcs_params->xcontext;
processRequest();