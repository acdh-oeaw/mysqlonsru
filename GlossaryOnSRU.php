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
        "aeb_eng_001",
        "aeb_eng_001__v001",
        "ar_de__v001",
        "pes_eng_032",
        "arz_eng_006",
	"pes_eng_033",
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
        $this->getLocalOrGlobalParams($params);
        $glossTable = $this->params->context[0];       
        $this->dbTableName = $glossTable;

        if ($this->params->context[0] === '') {
            throw new ESRUDiagnostics(new SRUDiagnostics(1, 'This script needs to know which resource to use!'));
        }
        
        parent::__construct($params);
        $this->extendedSearchResultProcessing = true;        

        $this->db = $this->db_connect();
        if ($this->db instanceof SRUDiagnostics) {
            throw new ESRUDiagnostics($this->db);
        }
        
        $indexConfig = $this->getWellKnownTEIPartAsXML($this->dbTableName, 8);
        
        if ($indexConfig !== NULL) {
        
        $indexes = $indexConfig->query('//queryTemplate[@published]');
        $converter = new XPath2NdxSqlLikeInterpreter();
        $data = array();
        
        foreach ($indexes as $index) {
            try {
                // A breakpoint within this block here trigger a bug
                // see: http://php.net/manual/en/class.domattr.php#116291
                $published = $index->attributes->getNamedItem('published')->value;
                $label = $index->attributes->getNamedItem('label')->value;
                $descr = $index->attributes->getNamedItem('descr')->value;
                $xpath = $converter->xpath2NdxSqlLike(trim($index->textContent), $data);
                // End of bug triggering block.
            } catch (\ACDH\FCSSRU\ErrorOrWarningException $e) {
                throw new ESRUDiagnostics(new SRUDiagnostics(1, 'Index definition corrupt.'), $e);
            }
            array_push($this->indices, array(
                'title' => $descr,
                'name' => $label,
                'search' => 'true',
                'scan' => 'true',
                'sort' => 'false',
                'filter' => $xpath,
                'isServerChoice' => $published === 'default'
            ));
        }
        
        $autocompleteParts = $indexConfig->query('//queryTemplate[@published="autocomplete"]');
        
        $autocompleteIndices = array();
        foreach($autocompleteParts as $part) {
            array_push($autocompleteIndices, $part->attributes->getNamedItem('label')->value);
        }
        } else {
            $autocompleteIndices = $this->getDefaultIndexes();
        }
        
        array_push($this->indices, array(
                'title' => 'Autocomplete Source',
                'name' => 'autocomp',
                'search' => 'false',
                'scan' => 'true',
                'sort' => 'false',
                'parts' => $autocompleteIndices
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
    
    protected function getDefaultIndexes() {
        $resIdParts = explode("_", $this->params->context[0]);
        $langId = $this->langId2LangName($resIdParts[0]);
        $transLangId = $this->langId2LangName($resIdParts[1]);
        
        $autocomplete = array();

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
            'filter' => '/%/sense/cit%[@type="translation"]%[@xml:lang=%'
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
                'filter' => "/%/sense/cit%[@type=\"translation\"]%[@xml:lang=\"$lang\"]%",
            ));
            array_push($autocomplete, "sense-$lang");
        }
        
        array_push($this->indices, array(
            'title' => 'Lemma',
            'name' => 'lemma',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '/%/entry/form[@type=\"lemma\"]/orth%',
        ));
        
        array_push($autocomplete, 'lemma');
        
        array_push($this->indices, array(
            'title' => 'POS',
            'name' => 'pos',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '/%/gramGrp/gram[@type=\"pos\"]%',
        ));
        
        array_push($this->indices, array(
            'title' => 'POS Subcategory',
            'name' => 'subc',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '/%/gramGrp/gram[@type=\"subc\"]%',
        ));
        
        array_push($this->indices, array(
            'title' => 'Root',
            'name' => 'root',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '/%/gramGrp/gram[@type=\"root\"]%',
        ));
        
        array_push($this->indices, array(
            'title' => 'Inflected forms',
            'name' => 'inflected',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'filter' => '/%form%[@type="inflected"]%',
        ));
                        
        array_push($autocomplete, 'inflected');
        
        array_push($this->indices, array(
            'title' => "Language Course $langId - $transLangId unit",
            'name' => 'unit',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => true,
            'filter' => '-%bibl%Course%',
        ));

        array_push($this->indices, array(
            'title' => 'XML ID',
            'name' => 'xmlid',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => true,
            'filter' => '-xml:id',
            'sqlStrSearch' => "SELECT sid, entry, id, 1 FROM $this->dbTableName WHERE sid='?'",
        ));
        
        return $autocomplete;
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
        $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml; charset=UTF-8'));
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

    /**
     * Lists the entries from the lemma column in the database
     * 
     * Lists either the profiles (city names) or the sample texts ([id])
     * 
     * @see http://www.loc.gov/standards/sru/specs/scan.html
     * @return Response|SRUDiagnostics Response or failure object.
     */
    public function scan($splittetSearchClause = NULL) {       
        if ($splittetSearchClause === NULL) { $splittetSearchClause = $this->params->queryParts; }
        
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
            $scanClause = $splittetSearchClause['searchString'];
            $searchRelation = SRUFromMysqlBase::EXACT;
            $isNumber = true;
        } else {
            if (isset($indexDescription['filter'])) {
                $sqlstr = $this->scanSqlForXPath($glossTable, $indexDescription['filter'], $this->options);
            }
            if (isset($indexDescription['parts'])) {
                $sqlstr = $indexDescription['parts'];
            }
            $isNumber = false;
            $searchRelation = SRUFromMysqlBase::STARTS_WITH;
            if (isset($indexDescription['exactOnly']) && ($indexDescription['exactOnly'] === true)) {                
                $searchRelation = SRUFromMysqlBase::STARTS_WITH;
            } else {
                $searchRelation = $this->operatorToStringSearchRelation($splittetSearchClause['operator'], $searchRelation);
            }
            $searchRelation = $this->parseStarAndRemove($splittetSearchClause, $searchRelation);
            $scanClause = $splittetSearchClause['searchString']; // a scan clause that is no index cannot be used.
        }

        try {
            $scanResult = $this->getScanResult($sqlstr, $scanClause, $searchRelation, $isNumber);
            $ret = new Response();
            $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml; charset=UTF-8'));
            $ret->setContent($scanResult);
        } catch (ESRUDiagnostics $ex) {
            $ret = $ex->getSRUDiagnostics();
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
        
        $splittetSearchClause = $this->params->queryParts;
               
        if ($splittetSearchClause['searchString'] === '') { 
            $splittetSearchClause['searchString'] = $this->params->getQuery();
            $splittetSearchClause['index'] = '';
        }
        
        if (!in_array($splittetSearchClause['operator'], array('', '=', '==', '>=', '>', '<', '<=', 'exact', 'any'))) {
           return new SRUdiagnostics(4, 'Operator: ' . $splittetSearchClause['operator']); 
        }
        
        if (!in_array($splittetSearchClause['index'], $this->indexNames)) {
            return new SRUdiagnostics(51, 'Result set: ' . $this->params->getQuery());
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
        
        try {
            if (isset($indexDescription['sqlStrSearch'])) {
                $query = $this->db->escape_string($splittetSearchClause['searchString']);
                $searchResult = $this->getSearchResult(preg_replace('/\?/', $query, $indexDescription['sqlStrSearch']), $indexDescription['title']);
            } else {
                $this->options["searchRelation"] = $this->parseStarAndRemove($splittetSearchClause,
                        (isset($indexDescription['exactOnly']) && ($indexDescription['exactOnly'] === true) ?
                        SRUFromMysqlBase::EXACT :
                        $this->operatorToStringSearchRelation($splittetSearchClause['operator'])
                        ));
                $this->options["query"] = $this->db->escape_string($splittetSearchClause['searchString']);
                $this->options["xpath"] = $indexDescription['filter'];
                $this->options["dbtable"] = $glossTable;

                $searchResult = $this->getSearchResult($this->options, "Glossary for " . $this->options["query"], new glossaryComparatorFactory($this->options["query"]));
            }
            $ret = new Response();
            $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml; charset=UTF-8'));
            $ret->setContent($searchResult);
        } catch (ESRUDiagnostics $ex) {
            $ret = $ex->getSRUDiagnostics();
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
    
    private $calculatedDistances = array();
    
    public function __construct($query) {
        $this->query = $query;
        $this->queryLen = mb_strlen($query);
    }
    /**
     * 
     * @param array $a An array with a 'content' field
     * @param array $b An array with a 'content' field
     *  https://bugs.php.net/bug.php?id=50688 exception may not be used!
     */
    public function sortSearchResult($a, $b) {
        //$xmlnsPattern = '~xmlns:([^"\']+)["\']([^"\']+)["\']~';
        $similarityA = $this->calculateSimilarity($a['content']);
        $similarityB = $this->calculateSimilarity($b['content']);
        if ($similarityA === $similarityB)
            return 0;
        if ($similarityA > $similarityB)
            return -1;
        return 1;
    }
    
    private function prepareCompareXPathQuery($str) {
        $namespacedTagsPattern = '~</?[^:> ]+:[^>]+>~';
        $cleanContent = preg_replace($namespacedTagsPattern, '', $str);
        $xml = new \DOMDocument;
        ErrorOrWarningException::$code_has_known_errors = true;
        $xml->loadXML($cleanContent);
        $ret = new \DOMXPath($xml);
        ErrorOrWarningException::$code_has_known_errors = false;
        return $ret;        
    }
    
    private function calculateSimilarity($entry) {
        $hash = hash('sha256', $entry);
        if (isset($this->calculatedDistances[$hash])) {
            return $this->calculatedDistances[$hash];
        }
        $search = $this->prepareCompareXPathQuery($entry);
        $similarity = 1;
        foreach ($search->query('//form[@type = "lemma" or @type="multiWordUnit" or @type="inflected"]/orth|//cit[@type="translation"]/quote|//def') as $node) {
            $wholeStringLength = mb_strlen($node->textContent);
            $wholeString = $node->textContent;
            $texts = preg_split('~[,;:.?! ]~', $wholeString, -1, PREG_SPLIT_OFFSET_CAPTURE);
            $perfectMatchFound = false;
            foreach($texts as $text) {
                $pos = $text[1];
                $text = trim($text[0]);
                $stringLength = mb_strlen($text);
                if ($text === $this->query) {
                    $similarity += ($pos === 0 ? 10 : 5) * ($stringLength / $wholeStringLength);
                    $perfectMatchFound = true;
                } else {
                    $norm = $wholeStringLength > $this->queryLen ? $wholeStringLength : $this->queryLen;
                    $lDist = $this->levenshtein($this->query, $wholeString);
                    $ratio = 1 - ($lDist / $norm);
                    $similarity *= 1 + $ratio;
                }
            }
            if ($perfectMatchFound) {break;}
        }
        $this->calculatedDistances[$hash] = $similarity;
        return $similarity;        
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
