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

use ACDH\FCSSRU\mysqlonsru\SRUFromMysqlBase,
    ACDH\FCSSRU\SRUDiagnostics,
    ACDH\FCSSRU\SRUWithFCSParameters,
    ACDH\FCSSRU\Http\Response,
    ACDH\FCSSRU\HttpResponseSender;

require_once __DIR__ . '/../../vendor/autoload.php';
/**
 * Load configuration and common functions
 */

require_once __DIR__ . "/common.php";

class GlossaryOnSRU extends SRUFromMysqlBase {

    private $restrictedGlossaries = array(
        "apc_eng_002",
        "aeb_eng_001__v001",
    );

    protected $options = array(
        "filter" => "-",        
        "distinct-values" => true,
        "query" => "", // the database can't sort or filter due to encoding
    );
            
    public function __construct(SRUWithFCSParameters $params = null) {
        parent::__construct($params);
        $this->extendedSearchResultProcessing = true;
    }

    /**
     * Generates a response according to ZeeRex
     * 
     * This is a machine readable description of this script's capabilities.
     * 
     * @see http://zeerex.z3950.org/overview/index.html
     * 
     */
    public function explain() {

        if ($this->params->context[0] === '') {
            return new SRUDiagnostics(1, 'This script needs to know which resource to use!');
        }

        $glossTable = $this->params->context[0];

        $resIdParts = explode("_", $glossTable);
        $langId = $this->langId2LangName($resIdParts[0]);
        $transLangId = $this->langId2LangName($resIdParts[1]);
        $this->indices = array();

        array_push($this->indices, array(
            'title' => "VICAV $langId - $transLangId any entry",
            'name' => 'entry',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));

        array_push($this->indices, array(
            'title' => "VICAV $langId - $transLangId translated sense",
            'name' => 'sense',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));

        array_push($this->indices, array(
            'title' => 'Resource Fragement PID',
            'name' => 'rfpid',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
        ));

        $ret = new Response();
        $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
        $ret->setContent($this->getExplainResult($glossTable, $glossTable));
        return $ret;
    }

    private function langId2LangName($langId) {
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
     * Lists the entries from the lemma column in the database
     * 
     * Lists either the profiles (city names) or the sample texts ([id])
     * 
     * @see http://www.loc.gov/standards/sru/specs/scan.html
     */
    public function scan() {
        $glossTable = $this->params->context[0];
        $sqlstr = '';

        $this->addReleasedFilter();
        
        if ($this->params->scanClause === 'rfpid') {
            $sqlstr = "SELECT id, entry, sid FROM $glossTable ORDER BY CAST(id AS SIGNED)";
            $scanClause = null;
            $exact = true;
            $isNumber = true;
        } else {
            if ($this->params->scanClause === '' ||
                    strpos($this->params->scanClause, 'entry') === 0 ||
                    strpos($this->params->scanClause, 'serverChoice') === 0 ||
                    strpos($this->params->scanClause, 'cql.serverChoice') === 0) {
                $sqlstr = $this->sqlForXPath($glossTable, "", $this->options);
            } else if (strpos($this->params->scanClause, 'sense') === 0) {
                $sqlstr = $this->sqlForXPath($glossTable, "-quote-", $this->options);
            } else {
                return new SRUdiagnostics(51, 'Result set: ' . $this->params->scanClause);
            }

            $lemma_query = $this->get_search_term_for_wildcard_search("entry", $this->params->scanClause);
            if (!isset($lemma_query)) {
                $lemma_query = $this->get_search_term_for_wildcard_search("serverChoice", $this->params->scanClause, "cql");
            }
            $lemma_query_exact = $this->get_search_term_for_exact_search("entry", $this->params->scanClause);
            if (!isset($lemma_query_exact)) {
                $lemma_query_exact = $this->get_search_term_for_exact_search("serverChoice", $this->params->scanClause, "cql");
            }

            $isNumber = false;
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
        }
        $scanResult = $this->getScanResult($sqlstr, $scanClause, $exact, $isNumber);
        if ($scanResult !== '') {
            $ret = new Response();
            $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
            $ret->setContent($scanResult);
        } else {
            $ret = $this->errorDiagnostics;
        }
        return $ret;
    }

    private function addReleasedFilter() {
        $relseasedXPathFilter = array(
                "-change-f-status-" => "released",
            );
        if (in_array($this->params->context[0], $this->restrictedGlossaries)) {
            if (isset($this->options["xpath-filters"])) {
            $this->options["xpath-filters"] = array_merge($this->options["xpath-filters"],
            $relseasedXPathFilter);            
            } else {
                $this->options["xpath-filters"] = $relseasedXPathFilter;  
            }
        }
    }
    /**
     * 
     */
 public function search()
 {  
    $glossTable = $this->params->context[0];     
    // HACK, sql parser? cql.php = GPL -> this GPL too
    $this->params->query = str_replace("\"", "", $this->params->query);
    $this->options = array_merge($this->options, array("distinct-values" => false,));
    $this->options["startRecord"] = $this->params->startRecord;
    $this->options["maximumRecords"] = $this->params->maximumRecords;
    $this->addReleasedFilter();
    $lemma_query = $this->get_search_term_for_wildcard_search("entry", $this->params->query);
    if (!isset($lemma_query)) {
        $lemma_query = $this->get_search_term_for_wildcard_search("serverChoice", $this->params->query, "cql");
    }
    $lemma_query_exact = $this->get_search_term_for_exact_search("entry", $this->params->query);
    if (!isset($lemma_query_exact)) {
        $lemma_query_exact = $this->get_search_term_for_exact_search("serverChoice", $this->params->query, "cql");
    }
    $sense_query_exact = $this->get_search_term_for_exact_search("sense", $this->params->query);
    $sense_query = $this->get_search_term_for_wildcard_search("sense", $this->params->query);
 
    $rfpid_query = $this->get_search_term_for_wildcard_search("rfpid", $this->params->query);
    $rfpid_query_exact = $this->get_search_term_for_exact_search("rfpid", $this->params->query);
    if (!isset($rfpid_query_exact)) {
        $rfpid_query_exact = $rfpid_query;
    }
    if (isset($rfpid_query_exact)) {
        $query = $this->db->escape_string($rfpid_query_exact);
        $this->populateSearchResult($this->db, "SELECT id, entry, sid, 1 FROM $glossTable WHERE id=$query", "Resource Fragment for pid");
        return;
    } else if (isset($sense_query_exact)) {
        $this->options["query"] = $this->db->escape_string($sense_query_exact);
        $this->options["xpath"] = "-quote-";
        $this->options["exact"] = true;
    } else if (isset($sense_query)) {
        $this->options["query"] = $this->db->escape_string($sense_query);
        $this->options["xpath"] = "-quote-";
    } else if (isset($lemma_query_exact)) {
        $this->options["query"] = $this->db->escape_string($lemma_query_exact);
        $this->options["exact"] = true;
    } else if (isset($lemma_query)) {
        $this->options["query"] = $this->db->escape_string($lemma_query);
    } else {
        $this->options["query"] = $this->db->escape_string($this->params->query);
    }
    $this->options["dbtable"] = $glossTable;

    $searchResult = $this->getSearchResult($this->options, "Glossary for " . $this->options["query"],
            new glossaryComparatorFactory($this->options["query"]));
    if ($searchResult !== '') { 
        $ret = new Response();    
        $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
        $ret->setContent($searchResult);
    } else {
        $ret = $this->errorDiagnostics;
    }
    return $ret; }
    
protected function processSearchResult($line) {
    global $glossTable;
    
    $xmlcode = str_replace("\n\n", "\n", $this->decodecharrefs($line[1]));

    $doc = new \DOMDocument();
    $doc->loadXML($xmlcode);

    $xpath = new \DOMXpath($doc);
    $elements = $xpath->query("//ptr[@type='example' or @type='multiWordUnit']");

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

            $subresult = $this->db->query($hstr);
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

}

class glossaryComparatorFactory extends comparatorFactory {
    public function createComparator() {
        return new glossarySearchResultComparator($this->query);
    } 
}

class glossarySearchResultComparator extends searchResultComparator {

    private $query;
    private $queryLen;
    
    public function __construct($query) {
        $this->query = $query;
        $this->queryLen = strlen($query);
    }
    /**
     * 
     * @param array $a An array with a 'content' field
     * @param array $b An array with a 'content' field
     */
    public function sortSearchResult($a, $b) {
        $xmla = new \DOMDocument;
        $xmla->loadXML($a['content']);
        $xmlaXPath = new \DOMXPath($xmla);
        $xmlb = new \DOMDocument;
        $xmlb->loadXML($b['content']);
        $xmlbXPath = new \DOMXPath($xmlb);
        $similarityA = 1;
        $similarityB = 1;
        foreach ($xmlaXPath->query('//form[@type = "lemma"]/orth|//cit[@type="translation"]/quote|//def') as $node) {
            $text = $node->textContent;
            if ($text === $this->query) {
                $similarityA += 10;
            } else {
                $norm = strlen($text) > $this->queryLen ? strlen($text) : $this->queryLen;
                $ratio = 1 - (\levenshtein($this->query, $text) / $norm);
                $similarityA *= 1 + $ratio;
            }
        }
        foreach ($xmlbXPath->query('//form[@type = "lemma"]/orth|//cit[@type="translation"]/quote|//def') as $node) {
            $text = $node->textContent;
            if ($text === $this->query) {
                $similarityB += 10;
            } else {
                $norm = strlen($text) > $this->queryLen ? strlen($text) : $this->queryLen;
                $ratio = 1 - (\levenshtein($this->query, $text) / $norm);
                $similarityB *= 1 + $ratio;
            }
        }
        if ($similarityA === $similarityB)
            return 0;
        if ($similarityA > $similarityB)
            return -1;
        return 1;
    }

}
if (!isset($runner)) {
    $worker = new GlossaryOnSRU(new SRUWithFCSParameters('lax'));
    $response = $worker->run();
    HttpResponseSender::sendResponse($response);
}