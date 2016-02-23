<?php

/**
 * Common functions used by all the scripts using the mysql database.
 * 
 * @uses $dbConfigfile
 * @package mysqlonsru
 */

namespace ACDH\FCSSRU\mysqlonsru;
/**
 * Configuration options and function common to all fcs php scripts
 */
require_once __DIR__ . "/../utils-php/common.php";

use clausvb\vlib\vlibTemplate,
    ACDH\FCSSRU\Http\Response,
    ACDH\FCSSRU\ErrorOrWarningException,
    ACDH\FCSSRU\SRUDiagnostics;

use ACDH\FCSSRU\SRUWithFCSParameters;

class SRUFromMysqlBase {
    /**
     * Whether processSearchResult is called.
     * @var boolean
     */
    protected $extendedSearchResultProcessing = false;
    
    /**
     *
     * @var SRUWithFCSParameters 
     */
    protected $params;
    
    /**
     * May contain error information in case of an error.
     * @var SRUDiagnostics
     */
    protected $errorDiagnostics;
    
    /**
     * DB accessor
     * @var \mysqli
     */
    protected $db;

    /**
     * An array with index configuratiuons. Should contain maps
     * with the following keys:
     *                       title string: An intelligable title for the index.
     *                       name string: The name of the index unterstood by the script
     *                       search bool
     *                       scan bool
     *                       sort bool
     * @var array 
     */
    protected $indices;
    
    protected $explainTemplateFilename = '';
    protected $scanTemplateFilename = '';
    protected $responseTemplateFilename = '';
    
    protected $errors_array = array();

    public function __construct(SRUWithFCSParameters $params = null) {
        if (!isset($params)) {
            global $sru_fcs_params;
            $this->params = $sru_fcs_params;
        } else {
            $this->params = $params;
        }
        $this->indices = array();
    }
    
/**
 * Get a database connection object (currently mysqli)
 * 
 * @uses $server
 * @uses $user
 * @uses $password
 * @uses $database
 * @return \mysqli
 */
public function db_connect() {
    $server = '';
    $user = '';
    $password = '';
    $database = '';
    
// Load database and user data
    global $dbConfigFile;
    require_once $dbConfigFile;

    $this->db = new \mysqli($server, $user, $password, $database);
    if ($this->db->connect_errno) {
        $this->errorDiagnostics = new SRUDiagnostics(1, 'MySQL Connection Error: Failed to connect to database: (' . $this->db->connect_errno . ") " . $this->db->connect_error);
    }
    $this->db->set_charset('utf8');
    $this->db->query("SET character_set_results = 'utf8',"
            . " character_set_client = 'utf8',"
            . " character_set_connection = 'utf8',"
            . " character_set_database = 'utf8',"
            . " character_set_server = 'utf8'");
    return $this->db;
}

/**
 * Decode custom encoding used by web_dict databases to UTF-8
 * 
 * @param string $str
 * @return string The decoded string as an UTF-8 encoded string. May contain
 *                characters that need to be escaped in XML/XHTML.
 */
protected function decodecharrefs($str) {
    $replacements = array(
        "&gt;" => '&amp;gt;',
        "&lt;" => '&amp;lt;',
        "&amp;" => '&amp;amp;',
        "#8#38#9#" => '&amp;amp;', // & -> &amp;
        "#9#" => ";",
        "#8#" => "&#",
//     "%gt" => "&gt;",
//     "%lt" => "&lt;",
//     "&#amp;" => "&amp;",
//     "&#x" => "&x",
    );
    foreach ($replacements as $search => $replace) {
        $str = str_replace($search, $replace, $str);
    }
    return \ACDH\FCSSRU\html_entity_decode_numeric($str);
}

/**
 * Take a string and encode it the way it's stored in web_dict dbs.
 * 
 * @param type $str String to encode.
 * @return type Encoded String
 */
protected function encodecharrefs($str) {
    if ($str === null) {return null;}
    $replacements = array(
        ";" => "#9#",
        "&#" => "#8#",
//     "&gt;" => "%gt",
//     "&lt;" => "%lt",
//     "&amp;" => "&#amp;",
//     "&x" => "&#x",
    );
    $htmlEncodedStr = \ACDH\FCSSRU\utf8_character2html_decimal_numeric($str);
    foreach ($replacements as $search => $replace) {
        $htmlEncodedStr = str_replace($search, $replace, $htmlEncodedStr);
    }
    return $htmlEncodedStr;
}

protected function _or($string1, $string2) {
    if (($string1 !== "") and ($string2 !== "")) {
        return ("($string1 OR $string2)");
    } else if ($string2 !== "") {
        return $string2;
    } else {
        return $string1;
    }
}

protected function _and($string1, $string2) {
    if (($string1 !== "") and ($string2 !== "")) {
        return ("($string1 AND $string2)");
    } else if ($string2 !== "") {
        return $string2;
    } else {
        return $string1;
    }
}

/**
 * Genereates an SQL statement that can be used to fetch data from tables used
 * and generated by web_dict_editor. The result contains the text searched for
 * in the first column and the (full text) entry in the second one. Optionally
 * the third column, lemma, contains the lemma associated with the entry.
 * @param string $table Name of the table to search in.
 *                      Maybe overidden by dbtable in options.
 * @param string $xpath XPath like statement of the form -node-node-node-.
 *                      An empty string will select every XPath.
 *                      My be overridden by xpath in options.
 * @param array $options Options: show-lemma => return a lemma column
 *                                query => The term searched for in the specified nodes
 *                                filter => A term to filter from the specified nodes, eg. - (no text)
 *                                xpath-filters => An array of the form $xpaht => $text values which limits
 *                                                 the result to all those entries that end in an
 *                                                 xpath having the value text.
 *                                distinct-values => whether the result should have only a single
 *                                                   column for each term found among the XPaths
 *                                exact => Whether to search for exactly that string, default
 *                                         is to just search for the string anywhere in the
 *                                         specified tags.
 *                                startRecord => limited search starting at this position
 *                                               of the result set. Default is start at the first.
 *                                maximumRecords => maximum number of records to return.
 *                                                  Needs startRecord to be set.
 *                                                  Default is return all records.
 *                                dbtable => Overrides $table.
 *                                xpath => Overrides $xpath.                   
 * @return string
 */
public function sqlForXPath($table, $xpath, $options = NULL) {
    $lemma = "";
    $query = "";
    $filter = "";
    $groupAndLimit = "";
    $groupCount = "";
    $likeXpath = "";
    $justCount = false;
    if (isset($options) && is_array($options)) {
        if (isset($options["dbtable"])) {
            $table = $options["dbtable"];
        }
        if (isset($options["xpath"])) {
            $xpath = $options["xpath"];
        }
        // ndx search
        $indexTable = $table . "_ndx";
        if (isset($options["xpath-filters"])) {
            $tableNameOrPrefilter = $this->genereatePrefilterSql($table, $options);
        } else {
            $tableNameOrPrefilter = $indexTable;
        }
        if ($xpath !== "") {
            $likeXpath .= "(";
            foreach (explode('|', $xpath) as $xpath) {
                $likeXpath .= "ndx.xpath LIKE '%" . $xpath . "' OR ";
            }
            $likeXpath = substr($likeXpath , 0, strrpos($likeXpath, ' OR '));
            $likeXpath .= ')';
        }
        if (isset($options["xpath-filters"]) and 
            in_array(null, $options["xpath-filters"])) {
            unset($options["query"]);           
        }
        if (isset($options["query"])) {
            $q = $options["query"];
            $qEnc = $this->encodecharrefs($q);
            if (isset($options["exact"]) && $options["exact"] === true) {
               $query .= "(ndx.txt = '$q' OR ndx.txt = '$qEnc') ";
            } elseif ($q !== '') {
               $query .= "(ndx.txt LIKE '%$q%' OR ndx.txt LIKE '%$qEnc%') ";
            } else {
               $query .= "ndx.txt LIKE '%' ";
            }
        }
        
        $indexTableWhereClause = "WHERE ". $this->_and($query, $this->_and($filter, $likeXpath));
        $indexTableWhereClause = ($indexTableWhereClause === "WHERE ") ? '' : $indexTableWhereClause;

        $indexTableForJoin = $this->hasOnlyRealXPathFilters($options) ? $tableNameOrPrefilter :
                "(SELECT ndx.id, ndx.txt FROM " . $tableNameOrPrefilter .
                " AS ndx $indexTableWhereClause". 
                // There seems no point in reporting all id + txt if the query did match a lot of txt
                'GROUP BY ndx.id)';
        // base
        if (isset($options["show-lemma"]) && $options["show-lemma"] === true) {
            $lemma = ", base.lemma";
        }
        if (isset($options["justCount"]) && $options["justCount"] === true) {
            $justCount = true;
        }
        if (isset($options["distinct-values"]) && $options["distinct-values"] === true) {
            $groupCount = ", COUNT(*)";
            $groupAndLimit .= " GROUP BY ndx.txt ORDER BY ndx.txt";
        } else if ($justCount !== true) {
            $groupCount = ", COUNT(*)";
            $groupAndLimit .= " GROUP BY base.sid";
        }
        if (isset($options["startRecord"]) && $options["startRecord"] !== false) {
            $groupAndLimit .= " LIMIT " . ($options["startRecord"] - 1);
        }
        if (isset($options["maximumRecords"]) && $options["maximumRecords"] !== false) {
            if (isset($options["startRecord"]) && $options["startRecord"] !== false) {
                $groupAndLimit .= ", " . $options["maximumRecords"];
            } else {
                $groupAndLimit .= " LIMIT 0," .  $options["maximumRecords"];
            }
        }
    }
    
    /*
        } else if ($queryparts[0] == "senses") {
            $querytemplate = "extractvalue(entry,\"//sense/cit[@xml:lang='en']/quote|//wkp:sense/wkp:cit[@xml:lang='en']/wkp:quote\")";
        } */
    return "SELECT" . ($justCount ? " COUNT(*) " : " ndx.txt, base.entry, base.sid" . $lemma . $groupCount) .
            " FROM " . $table . " AS base " .
            "INNER JOIN " . $indexTableForJoin . " AS ndx ON base.id = ndx.id WHERE base.id > 700" .
            $groupAndLimit;  
}

protected function genereatePrefilterSql($table, &$options) {
    $topMostTable = $this->generateXPathPrefilter($table, $options);
    $recursiveOptions = $options;
    // check this now. It's cached so changing xpath-filters doesn't matter.
    $this->hasOnlyRealXPathFilters($options);
    $recursiveOptions["xpath-filters"] = array_slice($recursiveOptions["xpath-filters"], 1, null, true);
    if (count($recursiveOptions["xpath-filters"]) === 0) {
        $tableOrPrefilter = $topMostTable;
    } else {
        $tableOrPrefilter = $this->genereatePrefilterSql($table, $recursiveOptions);
    }
    $filter = '';
    if (isset($options["filter"])) {
        $f = $options["filter"];
        if (strpos($f, '%') !== false) {
            $filter .= "WHERE tab.txt NOT LIKE '$f'";
        } else {
            $filter .= "WHERE tab.txt != '$f'";
        }
    }
    $indexTable = $table.'_ndx';
    $xpathToSearchIn = key($options["xpath-filters"]);
        if (is_array(current($options["xpath-filters"]))) {
            $p = parseFilterSpecs(current($options["xpath-filters"]));
            $whereClause = "CAST(inner.txt AS ".$p['as'].") ".$p['op']." ".$p['value']." ";
        } else {
            $whereClause = "inner.txt = '" . current($options["xpath-filters"]) . "' ";
        }
        $innerSql = "(SELECT inner.id, inner.txt FROM $indexTable AS `inner` WHERE ". 
                   $whereClause .
                    "AND inner.xpath LIKE '%$xpathToSearchIn')";
    $result = $this->hasOnlyRealXPathFilters($options) ? $tableOrPrefilter :
            "(SELECT tab.id, tab.xpath, tab.txt FROM $tableOrPrefilter AS tab ".
            "INNER JOIN " .
            $innerSql." AS prefid ". 
            "ON tab.id = prefid.id $filter)";
    return $result;
}

private $xPathPrefilter = '';

protected function generateXPathPrefilter($table, &$options) {
    if ($this->xPathPrefilter !== '') return $this->xPathPrefilter;
    $extractValueToCondition = array();
    $filternum = 0;
    $filters = $options["xpath-filters"];
    foreach ($filters as $xpathToSearchIn => $condition) {
        $colname = 'f'.(string)$filternum;
        $havingCondition = '!= \'\'';
        if ($xpathToSearchIn[0] === '/') {
            $q = $options["query"];
            if ($condition === null) {
                $colname = 'txt';
                if ($q === '') {
                    $predicate = ''; 
                } elseif (isset($options['exact']) and ($options['exact'] === true)) {
                    $predicate = "[.=\"$q\"]"; 
                } else {
                    $predicate = "[contains(., \"$q\")]"; 
                }
            } else {
                if (is_array($condition)) {
                    $p = parseFilterSpecs($condition);
                    $predicate = '['.'.'.$p['op'].$p['value'].']';
                } elseif ((strlen($condition) > 0) && ($condition[0] === '[')) {
                    $predicate = $condition;
                } else {
                    $predicate = '';
                }  
            }
            $xpath = $xpathToSearchIn.$predicate;
            $extractValueToCondition["ExtractValue(base.entry, '$xpath') AS '$colname'"] =
                   "$colname $havingCondition";
            $filternum++;
            unset($filters[$xpathToSearchIn]);
        }
    }
    $extractValues = '';
    $conditions = '';
    foreach ($extractValueToCondition as $extractValue => $condition) {
        $extractValues .= $extractValue.', ';
        $conditions .= $condition.' AND ';
    }   
    $extractValues = rtrim($extractValues, ', ');
    $conditions = rtrim($conditions, 'AND ');
    // no XPaths?
    $this->xPathPrefilter = $extractValues === '' ? $table.'_ndx':
                "(SELECT base.id, $extractValues" . 
                " FROM $table AS base GROUP BY base.id HAVING $conditions)";
    $options["xpath-filters"] = $filters;
    return $this->xPathPrefilter;
}

private $cachedHasOnlyRealXPathFilters = null;

protected function hasOnlyRealXPathFilters($options) {
    if($this->cachedHasOnlyRealXPathFilters !== null) {
        return $this->cachedHasOnlyRealXPathFilters;
    }
    if (!isset($options["xpath-filters"])) {
        $this->cachedHasOnlyRealXPathFilters = false;
    } else {
        $this->cachedHasOnlyRealXPathFilters = true;
        foreach ($options["xpath-filters"] as $filterSpec => $unused) {
           $this->cachedHasOnlyRealXPathFilters =
                $this->cachedHasOnlyRealXPathFilters && ($filterSpec[0] === '/'); 
       }
    }
    return $this->cachedHasOnlyRealXPathFilters;
}

protected function parseFilterSpecs($filterSpecs) {
    $result = array();
    $result['as'] = $filterSpecs["as"];
    $boolOps = array('<', '>', '<=', '>=', '=', '!=');
    $boolSpec = array_intersect_key($filterSpecs, array_flip($boolOps));
    $result['op'] = key($boolSpec);
    $result['value'] = current($boolSpec);
    return $result;    
}

/**
 * Get the URL the client requested so this script was called
 * @return string The URL the client requested.
 */
protected function curPageURL() {
    $pageURL = 'http';
    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
        $pageURL .= "s";
    }
    $pageURL .= "://";
    if (isset($_SERVER["SERVER_PORT"]) && $_SERVER["SERVER_PORT"] != "80") {
        $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["PHP_SELF"];
    } else if (isset($_SERVER["SERVER_NAME"])) {
        $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"];
    } else {
        $pageURL .= $_SERVER["PHP_SELF"];
    }
    return $pageURL;
}
/**
 * Fill in the ZeeRex explain template
 * 
 * @global type $explainTemplate
 * @param string $table
 * @param string $publicName
 * @return string
 */
protected function getExplainResult($table, $publicName) {
    if ($this->explainTemplateFilename === '') {
         global $explainTemplate;
         $this->explainTemplateFilename = $explainTemplate;
    }
    $teiHeaderXML = $this->getMetadataAsXML($table);
    $title = "";
    $authors = "";
    $restrictions = "";
    $description = "";
    if (isset($teiHeaderXML)) {
        $title = $teiHeaderXML->evaluate('string(//titleStmt/title)');

        $authorsNodes = $teiHeaderXML->query('//fileDesc/author');
        $authors = "";
        foreach ($authorsNodes as $author) {
            $authors .= "; " . $author->nodeValue;
        }
        $authors = substr($authors, 2);

        $restrictions = $teiHeaderXML->evaluate('string(//publicationStmt/availability[@status="restricted"]//ref/@target)');

//        $description = $xmlDocXPath->evaluate('string(//publicationStmt/pubPlace)') . ', ' .
//                $xmlDocXPath->evaluate('string(//publicationStmt/date)') . '. Edition: ' .
//                $xmlDocXPath->evaluate('string(//editionStmt/edition)') . '.';
        $frontMatterXML = null;
        if (strpos($this->params->xdataview, 'metadata') === false) {
            $frontMatterXML = $this->getFrontMatterAsXML($table);
        }
        if ($frontMatterXML !== null) {
            $description = $frontMatterXML->document->saveXML($frontMatterXML->document->firstChild);
        } else {
            $description = $teiHeaderXML->document->saveXML($teiHeaderXML->document->firstChild);
        }
    }
    
    ErrorOrWarningException::$code_has_known_errors = true;
    $tmpl = new vlibTemplate($this->explainTemplateFilename);
    ErrorOrWarningException::$code_has_known_errors = false;
    
    // PHP: How to use [array_intersect_key()] to filter array keys? http://stackoverflow.com/a/4260168
    $explainDataKeys = array('title', 'name', 'search', 'scan', 'sort', 'native');
    $explainData = array();
    foreach ($this->indices as $indexDescription) {
        array_push($explainData, array_intersect_key($indexDescription, array_flip($explainDataKeys)));
    }
    $tmpl->setLoop('maps', $explainData);
    
    $hostId = 'NoHost';
    if (isset($_SERVER["HTTP_HOST"])) {
        $hostId = $_SERVER["HTTP_HOST"];
    }
    $tmpl->setVar('hostid', htmlentities($hostId));
    $tmpl->setVar('database', $publicName);
    $tmpl->setVar('databaseTitle', $title);
    $tmpl->setVar('databaseAuthor', $authors);
    $tmpl->setVar('dbRestrictions', $restrictions);
    $tmpl->setVar('dbDescription', $description);
    ErrorOrWarningException::$code_has_known_errors = true;
    $ret = $tmpl->grab();
    ErrorOrWarningException::$code_has_known_errors = false;
    return $ret;
}
/**
 * Fill in the ZeeRex explain template and return it to the client.
 * 
 * @uses $explainTemplate
 * @param object $db
 * @param string $table The table from which the teiHeader at id 1 is fetched.
 * @param string $publicName The public name for this resource.
 * @param array $indices An array with index configuratiuons. Should contain maps
 *                       with the following keys:
 *                       title string: An intelligable title for the index.
 *                       name string: The name of the index unterstood by the script
 *                       search bool
 *                       scan bool
 *                       sort bool
 * @see http://zeerex.z3950.org/overview/index.html
 */
public function populateExplainResult ($db, $table, $publicName, $indices) {
    $this->indices = $indices;
    $this->db = $db;
    echo $this->getExplainResult($table, $publicName);
}

/**
 * Get the metadata stored in the db as XPaht object which also contains a
 * representation of the document.
 * 
 * @param type $db The db connection
 * @param type $table The table in the db that should be queried
 * @return \DOMXPath|null The metadata (teiHeader) as 
 */
protected function getMetadataAsXML($table) {
    // It is assumed that there is a teiHeader for the resource with this well knonwn id 1
    return $this->getWellKnownTEIPartAsXML($table, 1);
}

/**
 * Get the front matter from the given db
 * 
 * @param type $db The db connection
 * @param type $table The table in the db that should be queried
 * @return \DOMXPath|null The front matter
 */
protected function getFrontMatterAsXML($table) {
    // It is assumed that there is a front part for the resource with this well knonwn id 5
    return $this->getWellKnownTEIPartAsXML($table, 5);
}

/**
 * Get some TEI part by well known id
 * 
 * @param type $db The db connectio
 * @param type $table The table in the db that should be queried
 * @param type $id The well known id of the TEI part to fetch 
 * @return \DOMXPath|null Some TEI-XML, null if the id is not in teh db
 */
protected function getWellKnownTEIPartAsXML ($table, $id) {
    $result = $this->db->query("SELECT entry FROM $table WHERE id = $id");
    if ($result !== false) {
        $line = $result->fetch_array();
        if (is_array($line) && trim($line[0]) !== "") {
            return $this->getTEIDataAsXMLQueryObject($this->decodecharrefs($line[0]));
        } else {
            return null;
        }
    } else {
        return null;
    } 
}

/**
 * Turn the input text into a queryable object
 * 
 * @param type $xmlText A chunk of TEI XML
 * @return \DOMXPath The input text consisting of TEI XML as DOMXPath queryable object
 */
protected function getTEIDataAsXMLQueryObject($xmlText) {
    $trimmedXMLText = trim($xmlText);
    if ($trimmedXMLText[0] !== '<') {
        return null;
    }
    $xmlDoc = new \DOMDocument();
    try {
        $xmlDoc->loadXML($xmlText);
    } catch (ErrorOrWarningException $exc) {
        array_push($this->errors_array, $exc);
    }

    // forcebly register default and tei xmlns as tei
    try {
        $xmlDoc->createAttributeNS('http://www.tei-c.org/ns/1.0', 'create-ns');
        $xmlDoc->createAttributeNS('http://www.tei-c.org/ns/1.0', 'tei:create-ns');    
    } catch (\DOMException $exc) {}
    $xmlDocXPath = new \DOMXPath($xmlDoc);
    return $xmlDocXPath;
}

protected function getSearchResult($sql, $description, $comparatorFactory = NULL) {
    if ($this->responseTemplateFilename === '') {
            global $responseTemplate;
            $this->responseTemplateFilename = $responseTemplate;
    }

    $baseURL = $this->curPageURL();

    $dbTeiHeaderXML = null;
    $wantTitle = (stripos($this->params->xdataview, 'title') !== false);
    $wantMetadata = (stripos($this->params->xdataview, 'metadata') !== false);

    $extraCountSql = false;
    if (is_array($sql)) {
        $options = $sql;
        $sql = $this->sqlForXPath("", "", $options);
        if ($wantMetadata || $wantTitle) {
            $dbTeiHeaderXML = $this->getMetadataAsXML($options['dbtable']);
        }

        if (isset($options["maximumRecords"])) {

                $options["startRecord"] = NULL;
                $options["maximumRecords"] = NULL;
                $options["justCount"] = true;
                $countSql = $this->sqlForXPath("", "", $options);
                $result = $this->db->query($countSql);
                if ($result !== false) {
                    $line = $result->fetch_row();
                    $extraCountSql = $line[0];
                }
        }
        
    } else if ($wantMetadata || $wantTitle) {
        $dbtable = preg_filter('/.* FROM (\\w+) .*/', '$1', $sql);
        if ($dbtable !== false) {
            $dbTeiHeaderXML = $this->getMetadataAsXML($dbtable);
        }
    }
    
    $result = $this->db->query($sql);
   
    if ($result !== FALSE) {
         $numberOfRecords =     $this->db->query("SELECT FOUND_ROWS()");
         $numberOfRecords = $numberOfRecords->fetch_row();
         $numberOfRecords = $numberOfRecords[0];
     if ($extraCountSql !== false) {
          // $numberOfRecords = $extraCountSql;
        } else {
           // $numberOfRecords = $result->num_rows;
       //  $numberOfRecords =     $this->db->query("SELECT FOUND_ROWS()");
       }

        ErrorOrWarningException::$code_has_known_errors = true;
        $tmpl = new vlibTemplate($responseTemplate);
        ErrorOrWarningException::$code_has_known_errors = false;
        
        $tmpl->setVar('version', $this->params->version);
        $tmpl->setVar('numberOfRecords', $numberOfRecords);
        // There is currently no support for limiting the number of results.
        $tmpl->setVar('returnedRecords', $result->num_rows);
        $tmpl->setVar('query', $this->params->query);
        $tmpl->setVar('startRecord', $this->params->startRecord);
        $tmpl->setVar('maximumRecords', $this->params->maximumRecords);
        $tmpl->setVar('transformedQuery', str_replace('<', '&lt;', $sql));
        $tmpl->setVar('baseURL', $baseURL);
        $tmpl->setVar('xcontext', $this->params->xcontext);
        $tmpl->setVar('xdataview', $this->params->xdataview);
        // this isn't generated by fcs.xqm either ?!
        $nextRecordPosition = 0;
        $tmpl->setVar('nextRecordPosition', $nextRecordPosition);
        $tmpl->setVar('res', '1');
        
        $hits = array();
        $hitsMetaData = null;
//        $hitsMetaData = array();
//        array_push($hitsMetaData, array('key' => 'copyright', 'value' => 'ICLTT'));
//        array_push($hitsMetaData, array('key' => 'content', 'value' => $description));

        while (($line = $result->fetch_row()) !== NULL) {
            //$id = $line[0];
            if ($this->extendedSearchResultProcessing === true) {
                $content = $this->processSearchResult($line);
            } else {
                $content = $line[1];
            }
            
            $decodedContent = $this->decodecharrefs($content);
            $title = "";
            
            if ($wantTitle) {               
                $contentXPath = $this->getTEIDataAsXMLQueryObject($decodedContent);
                if (isset($contextXPath)) {
                    foreach ($contentXPath->query('//teiHeader/fileDesc/titleStmt/title') as $node) {
                        $title .= $node->textContent;
                    }
                }
                if ($title === "") {
                    foreach ($dbTeiHeaderXML->query('//teiHeader/fileDesc/titleStmt/title') as $node) {
                        $title .= $node->textContent;
                    }
                }
                
            }

            array_push($hits, array(
                'recordSchema' => $this->params->recordSchema,
                'recordPacking' => $this->params->recordPacking,
                'queryUrl' => $baseURL,
                'content' => $decodedContent,
                'wantMetadata' => $wantMetadata,
                'wantTitle' => $wantTitle,
                'title' => $title,
                'hitsMetaData' => $hitsMetaData,
                'hitsTeiHeader' => isset($dbTeiHeaderXML) ? $dbTeiHeaderXML->document->saveXML($dbTeiHeaderXML->document->firstChild) : null,
                // TODO: replace this by sth. like $this->params->http_build_query
                'queryUrl' => '?' . htmlentities(http_build_query($_GET)),
            ));
        }
        $result->close();
        if (isset($comparatorFactory)) {
            $comparator = $comparatorFactory->createComparator();
            usort($hits, array($comparator, 'sortSearchResult'));
        }
        $tmpl->setloop('hits', $hits);
        $this->addXDebugErrorsIfExist($tmpl);
        ErrorOrWarningException::$code_has_known_errors = true;
        $ret = $tmpl->grab();
        ErrorOrWarningException::$code_has_known_errors = false;
        return $ret;
    } else {
        $errorMessage = $this->db->error;
        $this->errorDiagnostics = new SRUdiagnostics(1, "MySQL query error: $errorMessage; Query was: $sql");
        return '';
    }
   }

    protected function addXDebugErrorsIfExist(vlibTemplate $tmpl) {
        $errorsString = '';
        if (count($this->errors_array > 0)) {
            foreach ($this->errors_array as $exc) {
                $errorsString .= basename($exc->getFile(), '.php') . ': ' . $exc->getLine() . ": \n" .
                                $exc->getCode() . ' ' . $exc->getMessage() . "\n";
            }
        }
        if (\function_exists('\\xdebug_get_collected_errors')) {
            $xdebugErrors = \xdebug_get_collected_errors(true);
            foreach ($xdebugErrors as $error) {
                $errorsString .= $error;
            }
            \xdebug_stop_error_collection();
        }
        if ($errorsString !== '') {
            $tmpl->setVar('wantDiag', true);
            $tmpl->setVar('errorsString', $errorsString);
        }
    }
    
/**
 * Execute a search and return the result using the $responseTemplate
 * @uses $responseTemplate
 * @uses $this->params
 * @param object $db An object supporting query($sqlstr) which should return a
 *                   query object supporting fetch_row(). Eg. a mysqli object
 *                   or an sqlite3 object.
 * @param array|string $sql Either an arrary that can be used to get a query string using
 *                          sqlForXPath;
 *                          or a query string to exequte using $db->query()
 * @param string $description A description used by the $responseTemplate.
 * @param comparatorFactory $comparatorFactory A class that can create a comporator for sorting the result.
 */
public function populateSearchResult($db, $sql, $description, $comparatorFactory = NULL) {
    $this->db = $db;
    $ret = $this->getSearchResult($sql, $description, $comparatorFactory);
    if ($ret !== '') {
        echo $ret;
    } else {
        $this->returnError();
    }
}

protected function returnError() {
    $response = $this->diagnosticsToResponse($this->errorDiagnostics);
    if ($this->shouldNotSendMetadata()) {
        $response->getHeaders()->clearHeaders();
    }
    \ACDH\FCSSRU\HttpResponseSender::sendResponse($response);
}

/**
 * An optional function called on every result record if $extendedSearchResultProcessing is true
 * so additional processing may be done. The default
 * is to return the result fetched from the DB as is.
 * The function receives the record line (array) returned by
 * the database as input and the db access object.
 * It is expected to return
 * the content that is placed at the appropriate
 * position in the returned XML document. 
 * @param array $line Array like object that represents the current line in the database.
 * @param /mysqli $db The database access object (note: remove it, change to member variable).
 * @return string
 */
protected function processSearchResult($line) {
    // Note: just a dummy, not called by default.
    return $line[1];    
}
protected function getScanResult($sqlstr, $entry = NULL, $exact = true, $isNumber = false) {
    if ($this->scanTemplateFilename === '') {
        global $scanTemplate;
        $this->scanTemplateFilename = $scanTemplate; 
    }
        
    $maximumTerms = $this->params->maximumTerms;
                
    $result = $this->db->query($sqlstr);
    if ($result !== FALSE) {
        $numberOfRecords = $result->num_rows;

        ErrorOrWarningException::$code_has_known_errors = true;
        $tmpl = new vlibTemplate($this->scanTemplateFilename);
        ErrorOrWarningException::$code_has_known_errors = false;

        $terms = new \SplFixedArray($result->num_rows);
        $i = 0;
        while (($row = $result->fetch_array()) !== NULL) {
            $entry_count = isset($row["COUNT(*)"]) ? $row["COUNT(*)"]: 1;
            $term = array(
                'value' => $this->decodecharrefs($row[0]),
                'numberOfRecords' => $entry_count,
// may be useful for debugging
//                'sid' => $row['sid'],
//                'idx' => $i,
//                'rawValue' => $row[0],
            );
            // for sorting ignore some punctation marks etc.
            $term["sortValue"] = trim(preg_replace('/[?!()*,.\\-\\/|=]/', '', mb_strtoupper($term["value"], 'UTF-8')));
            // sort strings that start with numbers at the back.
            $term["sortValue"] = preg_replace('/^(\d)/', 'zz${1}', $term["sortValue"]);
            // only punctation marks or nothing at all last.
            if ($term["sortValue"] === "") {
                $term["sortValue"] = "zzz";
            }
            if (isset($row["lemma"]) && $this->decodecharrefs($row["lemma"]) !== $term["value"]) {
                $term["displayTerm"] = $this->decodecharrefs($row["lemma"]);
            }
            $terms[$i++] = $term;
        }
        $sortedTerms = $terms->toArray();
        if (!$isNumber) {
            usort($sortedTerms, function ($a, $b) {
                $ret = strcmp($a["sortValue"], $b["sortValue"]);
                return $ret;
            });
        }
        $startPosition = 0;
        if (isset($entry)) {
            $startAtString = is_array($entry) ? $entry[0] : $entry;
            while ($startAtString !== "" && $startPosition < count($sortedTerms)) {
                $found = strpos($sortedTerms[$startPosition]["value"], $startAtString);
                if ($exact ? $found === 0 : $found !== false) {
                    break;
                }
                $startPosition++;
            }
        }
        $position = ($startPosition - $this->params->responsePosition) + 1;
        $position = $position <= 0 ? 0 : $position;
        $shortList = array();
        $endPosition = min($position + $maximumTerms, count($sortedTerms));
        while ($position < $endPosition){
            $sortedTerms[$position]['value'] = htmlentities($sortedTerms[$position]['value'], ENT_XML1);
            if (isset($sortedTerms[$position]['displayTerm']))
                $sortedTerms[$position]['displayTerm'] = htmlentities($sortedTerms[$position]['displayTerm'], ENT_XML1);
            array_push($shortList, $sortedTerms[$position]);
            $shortList[$position]["position"] = $position + 1;
            $position++;
        }

        $tmpl->setloop('terms', $shortList);

        $tmpl->setVar('version', $this->params->version);
        $tmpl->setVar('count', $numberOfRecords);
        $tmpl->setVar('transformedQuery', str_replace('<', '&lt;', $sqlstr));
        $tmpl->setVar('clause', $this->params->scanClause);
        $responsePosition = 0;
        $tmpl->setVar('responsePosition', $responsePosition);
        $tmpl->setVar('maximumTerms', $maximumTerms);
        $this->addXDebugErrorsIfExist($tmpl);

        ErrorOrWarningException::$code_has_known_errors = true;
        $ret = $tmpl->grab();
        ErrorOrWarningException::$code_has_known_errors = false;
        return $ret;
    } else {
        $this->errorDiagnostics = new SRUdiagnostics(1, 'MySQL query error: Query was: ' . $sqlstr);
        return '';
    }
}
/**
 * Execute a scan and return the result using the $scanTemplate
 * @uses $scanTemplate
 * @uses $this->params
 * @param object $db An object supporting query($sqlstr) which should return a
 *                   query object supporting fetch_row(). Eg. a mysqli object
 *                   or an sqlite3 object.
 * @param string $sqlstr A query string to exequte using $db->query()
 * @param string|array $entry An optional entry from which to start listing terms.
 *                       If this is an array it is assumed that it its buld like this:
 *                       array[0] => the beginning search string
 *                       array[1] => wildcard(s)
 *                       array[2] => the end of the search string
 * @param bool $exact If the start word needs to be exactly the specified or
 *                    if it should be just anywhere in the string.
 */
public function populateScanResult($db, $sqlstr, $entry = NULL, $exact = true, $isNumber = false) {
    $this->db = $db;
    $ret = $this->getScanResult($sqlstr, $entry, $exact, $isNumber);
    if ($ret !== '') {
        echo $ret;
    } else {
        $this->returnError();
    }
}

/**
 * Switching function that initiates the correct action as specified by the
 * operation member of $this->params.
 * @return Response
 */
public function run() {
    if (function_exists('xdebug_start_error_collection')) {
        xdebug_start_error_collection();
    }
    $this->db = $this->db_connect();
    if ($this->db ->connect_errno) {
        $ret = $this->errorDiagnostics;
    } else if ($this->params->operation === "explain" || $this->params->operation == "") {
        $ret = $this->explain();
    } else if ($this->params->operation === "scan") {
        $ret = $this->scan();
    } else if ($this->params->operation === "searchRetrieve") {
        $ret = $this->search();
    }
    if (get_class($ret) === 'ACDH\\FCSSRU\\SRUDiagnostics') {
        $ret = $this->diagnosticsToResponse($ret);
    }
    
    if ($this->shouldNotSendMetadata()) {
        $ret->getHeaders()->clearHeaders();
    }
    return $ret;
}
/**
 * @return Response|SRUDiagnostics; 
 */
public function explain() {
    return new SRUDiagnostics(1, 'Not implememnted');
}
/**
 * @return Response|SRUDiagnostics; 
 */
public function scan() {
    return new SRUDiagnostics(1, 'Not implememnted');
}
/**
 * @return Response|SRUDiagnostics; 
 */
public function search() {
    return new SRUDiagnostics(1, 'Not implememnted');
}

protected function diagnosticsToResponse(SRUDiagnostics $diag) {
    $ret = new Response();
    $ret->getHeaders()->addHeaders(array('content-type' => 'text/xml'));
    $ret->setContent($diag->getAsXML());
    return $ret;
}

/**
 * Whether a HTTP header describing the content should be sent according to the
 * input.
 * @return boolean
 */
public function shouldNotSendMetadata() {
    return $this->params->recordPacking === 'raw';
}

/**
 * Switching function that initiates the correct action as specified by the
 * operation member of $sru_fcs_params.
 * @uses $sru_fcs_params
 */
public static function processRequest() {
    global $sru_fcs_params;
    
    if (function_exists('xdebug_start_error_collection')) {
        xdebug_start_error_collection();
    }    
    if ($sru_fcs_params->operation == "explain" || $sru_fcs_params->operation == "") {
        explain();
    } else if ($sru_fcs_params->operation == "scan") {
        scan();
    } else if ($sru_fcs_params->operation == "searchRetrieve") {
        search();
    }
}

/**
 * Returns the search term if a wildcard search is requested for the given index
 * @param string $index The index name that should be in the query string if
 * this function is to return a search term.
 * @param string $queryString The query string passed by the user.
 * @param string $index_context An optional context name for the index.
 * As in _cql_.serverChoice.
 * @return string|NULL The term to search for, NULL if this is not the index the user wanted. The string
 * is encoded as needed by the web_dict dbs!
 */
public function get_search_term_for_wildcard_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(=|any) *(.*)/', '$3', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(=|any) *(.*)/', '$2', $queryString);
    }
    return $ret;
}

/**
 * Returns the search term if an exact search is requested for the given index
 * 
 * Note that the definition of a search anywhere and an exact one is rather close
 * so get_search_term_for_wildcard_search will also return a result (_=_+search).
 * 
 * @param string $index The index name that should be in the query string if
 * this function is to return a search term.
 * @param string $queryString The query string passed by the user.
 * @param string $index_context An optional context name for the index.
 * As in _cql_.serverChoice.
 * @return string|NULL NULL if this is not the index the user wanted. The string
 * is encoded as needed by the web_dict dbs!
 */
public function get_search_term_for_exact_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(==|(cql\.)?string) *(.*)/', '$4', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(==|(cql\.)?string) *(.*)/', '$3', $queryString);
    }
    return $ret;
}

/**
 * Look for the * or ? wildcards
 * @param string $input A string that may contain ? or * as wildcards.
 * @return string|array An array consisting of the first part, the wildcard and 
 *                      the last part of the search string
 *                      or just the input string if it didn't contain wildcards
 */
protected function get_wild_card_search($input) {
    $search = preg_filter('/(\w*)([?*][?]*)(\w*)/', '$1&$2&$3', $input);
    if (isset($search)) {
        $ret = explode("&", $search);
    } else {
        $ret = $input;
    }
    return $ret;
}


/**
 * Tries to find the index, operator and searchString (start string for scan) in
 * either the query parameter or the scanClause parameter.
 * @return array An array that has all the groups found by preg_match. The
 *               index, operator and searchString found are contained as 
 *               key value pairs.
 */
protected function findCQLParts() {
    $cqlIdentifier = '("([^"])*")|([^\s()=<>"\/]*)';
    $matches = array();
    $regexp = '/(?<index>'.$cqlIdentifier.') *(?<operator>(==?)|(>=?)|(<=?)|('.$cqlIdentifier.')) *(?<searchString>'.$cqlIdentifier.')/';
    preg_match($regexp, $this->params->query !== '' ? $this->params->query : $this->params->scanClause, $matches);
    $matches['index'] = trim($matches['index'], '"');
    $matches['operator'] = trim($matches['operator'], '"');
    $matches['searchString'] = trim($matches['searchString'], '"');
    return $matches;
}
}
class comparatorFactory {
    
    /**
     *
     * @var string the query string passed for searchRetrieve 
     */
    protected $query;
    
    public function __construct($query) {
        $this->query = $query;
    }
    
    /**
     * Dummy, override to create your comparator.
     * @return searchResultComparator
     */
    public function createComparator() {
        return new searchResultComparator();
    }
}

class searchResultComparator {
    public function sortSearchResult($a, $b) {
        return 0;
    }
}
