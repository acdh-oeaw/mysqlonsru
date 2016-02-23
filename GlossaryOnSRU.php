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
    ACDH\FCSSRU\ErrorOrWarningException,
    ACDH\FCSSRU\ESRUDiagnostics,
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
        "ar_de__v001",
        "pes_eng_032",
        "arz_eng_006",
    );

    protected $options = array(
        "filter" => "-",        
        "distinct-values" => true,
        "query" => "", // the database can't sort or filter due to encoding
    );
    
    protected $indexNames = array();
    protected $serverChoiceIndexNames = array('', 'serverChoice', 'cql.serverChoice');
    protected $serverChoiceIndex;
            
    public function __construct(SRUWithFCSParameters $params = null) {
        parent::__construct($params);
        $this->extendedSearchResultProcessing = true;

        if ($this->params->context[0] === '') {
            throw new ESRUDiagnostics(new SRUDiagnostics(1, 'This script needs to know which resource to use!'));
        }

        $glossTable = $this->params->context[0];

        $resIdParts = explode("_", $glossTable);
        $langId = $this->langId2LangName($resIdParts[0]);
        $transLangId = $this->langId2LangName($resIdParts[1]);

        array_push($this->indices, array(
            'title' => "VICAV $langId - $transLangId any entry",
            'name' => 'entry',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '',
            'isServerChoice' => true
        ));

        array_push($this->indices, array(
            'title' => "VICAV $langId - $transLangId translated sense",
            'name' => 'sense',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '-quote-'
        ));
        
        foreach (array(
            'en' => "VICAV $langId - $transLangId translated english sense",
            'de' => "VICAV $langId - $transLangId Übersetzung deutsch",
            'es' => "VICAV $langId - $transLangId translated spanish sense",
            'fr' => "VICAV $langId - $transLangId translated french sense",                 
        ) as $lang => $description) {
            array_push($this->indices, array(
                'title' => $description,
                'name' => "sense-$lang",
                'search' => 'true',
                'scan' => 'true',
                'sort' => 'false',
                'filter' => "",
                'xpath-filters' => array(
                    "//cit[@xml:lang=\"$lang\"]//text()" => null
                )
            ));
        }
        
        array_push($this->indices, array(
            'title' => 'Lemma',
            'name' => 'lemma',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '',
            'xpath-filters' => array(
                "//form[@type=\"lemma\" or @type=\"multiUnitWord\"]/orth[@xml:lang=\"fa-Arab\" or @xml:lang=\"fa-x-modDMG\"]" => null
            )
        ));
        
        array_push($this->indices, array(
            'title' => 'POS',
            'name' => 'pos',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '',
            'xpath-filters' => array(
                '//gramGrp/gram[@type="pos"]' => null
            )
        ));
        
        array_push($this->indices, array(
            'title' => 'Inflected forms',
            'name' => 'inflected',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '',
            'xpath-filters' => array(
                '//form[@type="inflected"]/orth[position()<3]' => null
            )
        ));       
        
        array_push($this->indices, array(
            'title' => "Language Course $langId - $transLangId unit",
            'name' => 'unit',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => true,
            'filter' => '-bibl-%Course-',
        ));

        array_push($this->indices, array(
            'title' => 'Resource Fragement PID',
            'name' => 'rfpid',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => 'true',
            'sqlStrScan' => "SELECT id, entry, sid FROM $glossTable ORDER BY CAST(id AS SIGNED)",
            'sqlStrSearch' => "SELECT id, entry, sid, 1 FROM $glossTable WHERE id='?'",
        ));

        array_push($this->indices, array(
            'title' => 'XML ID',
            'name' => 'xmlid',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => true,
            'filter' => '-xml:id',
            'sqlStrSearch' => "SELECT sid, entry, id, 1 FROM $glossTable WHERE sid='?'",
        ));
        
        $this->indexNames = array_merge($this->indexNames, $this->serverChoiceIndexNames);
        
        foreach ($this->indices as $indexDescription) {
            array_push($this->indexNames, $indexDescription['name']);
            if (isset($indexDescription['isServerChoice']) && 
                ($indexDescription['isServerChoice'] === true)) {
                $this->serverChoiceIndex = $indexDescription;
            } 
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
    public function explain() {
        $ret = new Response();
        $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
        $ret->setContent($this->getExplainResult($this->params->context[0], $this->params->context[0]));
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
    
    protected function getIndexDescription($splittetSearchClause) {       
        $indexDescription = null;
        if (in_array($splittetSearchClause['index'], $this->serverChoiceIndexNames)) {
            $indexDescription = $this->serverChoiceIndex;
        } else {
            foreach ($this->indices as $indexDescription) {
                if ($indexDescription['name'] === $splittetSearchClause['index']) {break;}
            }
        }
        return $indexDescription;
    }

    /**
     * Lists the entries from the lemma column in the database
     * 
     * Lists either the profiles (city names) or the sample texts ([id])
     * 
     * @see http://www.loc.gov/standards/sru/specs/scan.html
     */
    public function scan() {       
        $splittetSearchClause = $this->findCQLParts();
        
        if ($splittetSearchClause['index'] === '') { 
            $splittetSearchClause['index'] = $this->params->scanClause;                    
        }
        
        if (!in_array($splittetSearchClause['operator'], array('', '=', '==', '>=', 'exact', 'any'))) {
           return new SRUdiagnostics(4, 'Operator: ' . $splittetSearchClause['operator']); 
        }
        
        if (!in_array($splittetSearchClause['index'], $this->indexNames)) {
           return new SRUdiagnostics(51, 'Result set: ' . $this->params->scanClause);
        }
        $indexDescription = $this->getIndexDescription($splittetSearchClause);
        
        $glossTable = $this->params->context[0];
        $sqlstr = '';
        
        if (isset($indexDescription["xpath-filters"])) {
           $this->options["xpath-filters"] = $indexDescription["xpath-filters"];
        }
        $this->addReleasedFilter();
        
        
        if (isset($indexDescription['sqlStrScan'])) {
            $sqlstr = $indexDescription['sqlStrScan'];
            $scanClause = null;
            $exact = true;
            $isNumber = true;
        } else {
            $sqlstr = $this->sqlForXPath($glossTable, $indexDescription['filter'], $this->options);
//                $sqlstr = $this->sqlForXPath($glossTable, "-xml:id", $this->options);
            $isNumber = false;
            $exact = false;
            if (isset($indexDescription['exactOnly']) && ($indexDescription['exactOnly'] === true)) {
                $exact = true;
            } else {
                $exact = in_array($splittetSearchClause['operator'], array('==', 'exact')) ? true : false;
            }
            $scanClause = ""; // a scan clause that is no index cannot be used.
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
        if ($this->hasOnlyRealXPathFilters($this->options)) {
            $releasedXPathFilter = array(
                '//f[@name="status"]/symbol/@value[.="released"]' => '',
            );
        } else {
            $releasedXPathFilter = array(
                    "-change-f-status-" => "released",
                );
        }
        if (in_array($this->params->context[0], $this->restrictedGlossaries)) {
            if (isset($this->options["xpath-filters"])) {
            $this->options["xpath-filters"] = array_merge($this->options["xpath-filters"],
                 $releasedXPathFilter);            
            } else {
                $this->options["xpath-filters"] = $releasedXPathFilter;  
            }
        }
    }
    /**
     * 
     */
    public function search() {
        // HACK, sql parser? cql.php = GPL -> this GPL too
        
        $splittetSearchClause = $this->findCQLParts();
               
        if ($splittetSearchClause['searchString'] === '') { 
            $splittetSearchClause['searchString'] = $this->params->query;
            $splittetSearchClause['index'] = '';
        }
        
        if (!in_array($splittetSearchClause['operator'], array('', '=', '==', '>=', '>', '<', '<=', 'exact', 'any'))) {
           return new SRUdiagnostics(4, 'Operator: ' . $splittetSearchClause['operator']); 
        }
        
        if (!in_array($splittetSearchClause['index'], $this->indexNames)) {
            return new SRUdiagnostics(51, 'Result set: ' . $this->params->query);
        }
        
        $indexDescription = $this->getIndexDescription($splittetSearchClause);
        
        $glossTable = $this->params->context[0];
        $this->options = array_merge($this->options, array("distinct-values" => false,));
        $this->options["startRecord"] = $this->params->startRecord;
        $this->options["maximumRecords"] = $this->params->maximumRecords;
        if (isset($indexDescription["xpath-filters"])) {
           $this->options["xpath-filters"] = $indexDescription["xpath-filters"];
        }
        $this->addReleasedFilter();
        
        if (isset($indexDescription['sqlStrSearch'])) {
            $query = $this->db->escape_string($splittetSearchClause['searchString']);
            $searchResult = $this->getSearchResult(preg_replace('/\?/', $query, $indexDescription['sqlStrSearch']), $indexDescription['title']);
        } else {
            $this->options["query"] = $this->db->escape_string($splittetSearchClause['searchString']);
            $this->options["xpath"] = $indexDescription['filter'];
            $this->options["exact"] = in_array($splittetSearchClause['operator'], array('==', 'exact'))||
                                      (isset($indexDescription['exactOnly']) && $indexDescription['exactOnly'] === true)? true : false;
            $this->options["dbtable"] = $glossTable;

            $searchResult = $this->getSearchResult($this->options, "Glossary for " . $this->options["query"], new glossaryComparatorFactory($this->options["query"]));
        }
        if ($searchResult !== '') {
            $ret = new Response();
            $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
            $ret->setContent($searchResult);
        } else {
            $ret = $this->errorDiagnostics;
        }
        return $ret;
    }

    protected function processSearchResult($line) {
    $glossTable = $this->params->context[0];
    
    $xmlcode = str_replace("\n\n", "\n", $this->decodecharrefs($line[1]));

    $doc = new \DOMDocument();
    
    try {
       $doc->loadXML($xmlcode);    
    } catch (ErrorOrWarningException $exc) {
       array_push($this->errors_array, $exc);
    }

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
            while (($subresult !== false) && ($subline = $subresult->fetch_row())) {
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
     *  https://bugs.php.net/bug.php?id=50688 exception may not be used!
     */
    public function sortSearchResult($a, $b) {
        $xmla = new \DOMDocument;
        ErrorOrWarningException::$code_has_known_errors = true;
        $xmla->loadXML($a['content']);
        $xmlaXPath = new \DOMXPath($xmla);
        $xmlb = new \DOMDocument;
        $xmlb->loadXML($b['content']);
        ErrorOrWarningException::$code_has_known_errors = false;
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
    try {
       $worker = new GlossaryOnSRU(new SRUWithFCSParameters('lax'));
       $response = $worker->run();        
    } catch (ESRUDiagnostics $ex) {
       $response = new Response();
       $response->setContent($ex->getSRUDiagnostics()->getAsXML());
    }
    HttpResponseSender::sendResponse($response);
}