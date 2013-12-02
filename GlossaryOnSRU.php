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
        'title' => 'VICAV Egyptian Arabic - English',
        'name' => 'entry',
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

 function processSearchResult($line, $db) {
    global $glossTable;

    $xmlcode = str_replace("\n\n", "\n", decodecharrefs($line[1]));

    $doc = new DOMDocument();
    $doc->loadXML($xmlcode);

    $xpath = new DOMXpath($doc);
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
 
 function search()
 {
    global $glossTable;
    global $sru_fcs_params;

    $db = db_connect();
    if ($db->connect_errno) {
        return;
    }
    
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $sru_fcs_params->query = str_replace("\"", "", $sru_fcs_params->query);
    $options = array("distinct-values" => false,);
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
 
    if (isset($lemma_query_exact)) {
        $options["query"] = $db->escape_string($lemma_query_exact);
        $options["exact"] = true;
    } else if (isset($lemma_query)) {
        $options["query"] = $db->escape_string($lemma_query);
    } else {
        $options["query"] = $db->escape_string($sru_fcs_params->query);
    }
    
    $sqlstr = sqlForXPath($glossTable, "", $options);

    populateSearchResult($db, $sqlstr, "Glossary for " . $options["query"], 'processSearchResult');
 }

getParamsAndSetUpHeader();
//$glossTable = $sru_fcs_param["x-context"];
$glossTable = "arz_eng_006";
processRequest();