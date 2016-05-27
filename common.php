<?php

/**
 * Common functions used by all the scripts using the mysql database.
 * 
 * @uses $dbConfigfile
 * @package mysqlonsru
 */

namespace ACDH\FCSSRU\mysqlonsru;

\mb_internal_encoding('UTF-8');
\mb_http_output('UTF-8');
\mb_http_input('UTF-8');
\mb_regex_encoding('UTF-8'); 

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
     * To speed up scan generation position in the stable list a scan has
     * to produce can be ignored.
     * That allows for additional SQL side optimizations.
     * @var boolean
     */
    private $ignorePosition = false;
    
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
    protected $dbTableName = '';
    
    protected $errors_array = array();

    public function __construct(SRUWithFCSParameters $params = null) {
        $this->getLocalOrGlobalParams($params);
        $this->indices = array(
            array(
            'title' => 'Resource Fragement PID',
            'name' => 'rfpid',
            'search' => 'true',
            'scan' => 'true',
            'sort' => 'false',
            'exactOnly' => 'true',
            'sqlStrScan' => "SELECT id, entry, sid FROM $this->dbTableName ORDER BY CAST(id AS SIGNED)",
            'sqlStrSearch' => "SELECT id, entry, sid, 1 FROM $this->dbTableName WHERE id='?'",
            )
        );
    }
    
    protected function getLocalOrGlobalParams(SRUWithFCSParameters $params = null) {
        if (!isset($params)) {
            global $sru_fcs_params;
            $this->params = $sru_fcs_params;
        } else {
            $this->params = $params;
        }        
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
    if (isset($this->db)) {return $this->db;}
    $server = '';
    $user = '';
    $password = '';
    $database = '';
    
// Load database and user data
    global $dbConfigFile;
    require_once $dbConfigFile;
    
    try {
        $this->db = new \mysqli($server, $user, $password, $database);
    } catch(ErrorOrWarningException $e) {
        $this->errorDiagnostics = new SRUDiagnostics(1, 'MySQL Connection Error: Failed to connect to database: (' . $e->getMessage(). ")");
        return $this->errorDiagnostics;
    }
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

private $chars_to_accentless;
private $chars_to_accentless_lower;

/**
 * Converts all accent characters to ASCII characters.
 *
 * Borrowed from https://core.trac.wordpress.org/browser/tags/4.4.2/src/wp-includes/formatting.php#L1132
 *
 * @param string $string Text that might have accent characters
 * @return string Filtered string with replaced "nice" characters.
 */
    protected function remove_accents($string, $andToLower = false) {

        if (!isset($this->chars_to_accentless)) {
        $this->chars_to_accentless = array(
            // Decompositions for Latin-1 Supplement
            chr(194) . chr(170) => 'a', chr(194) . chr(186) => 'o',
            chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
            chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
            chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
            chr(195) . chr(134) => 'AE', chr(195) . chr(135) => 'C',
            chr(195) . chr(136) => 'E', chr(195) . chr(137) => 'E',
            chr(195) . chr(138) => 'E', chr(195) . chr(139) => 'E',
            chr(195) . chr(140) => 'I', chr(195) . chr(141) => 'I',
            chr(195) . chr(142) => 'I', chr(195) . chr(143) => 'I',
            chr(195) . chr(144) => 'D', chr(195) . chr(145) => 'N',
            chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
            chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
            chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
            chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
            chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
            chr(195) . chr(158) => 'TH', chr(195) . chr(159) => 's',
            chr(195) . chr(160) => 'a', chr(195) . chr(161) => 'a',
            chr(195) . chr(162) => 'a', chr(195) . chr(163) => 'a',
            chr(195) . chr(164) => 'a', chr(195) . chr(165) => 'a',
            chr(195) . chr(166) => 'ae', chr(195) . chr(167) => 'c',
            chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
            chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
            chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
            chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
            chr(195) . chr(176) => 'd', chr(195) . chr(177) => 'n',
            chr(195) . chr(178) => 'o', chr(195) . chr(179) => 'o',
            chr(195) . chr(180) => 'o', chr(195) . chr(181) => 'o',
            chr(195) . chr(182) => 'o', chr(195) . chr(184) => 'o',
            chr(195) . chr(185) => 'u', chr(195) . chr(186) => 'u',
            chr(195) . chr(187) => 'u', chr(195) . chr(188) => 'u',
            chr(195) . chr(189) => 'y', chr(195) . chr(190) => 'th',
            chr(195) . chr(191) => 'y', chr(195) . chr(152) => 'O',
            // Decompositions for Latin Extended-A
            chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
            chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
            chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
            chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
            chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
            chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
            chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
            chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
            chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
            chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
            chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
            chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
            chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
            chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
            chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
            chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
            chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
            chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
            chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
            chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
            chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
            chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
            chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
            chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
            chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
            chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
            chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
            chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
            chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
            chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
            chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
            chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
            chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
            chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
            chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
            chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
            chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
            chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
            chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
            chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
            chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
            chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
            chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
            chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
            chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
            chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
            chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
            chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
            chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
            chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
            chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
            chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
            chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
            chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
            chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
            chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
            chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
            chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
            chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
            chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
            chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
            chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
            chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
            chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's',
            // Decompositions for Latin Extended-B
            chr(200) . chr(152) => 'S', chr(200) . chr(153) => 's',
            chr(200) . chr(154) => 'T', chr(200) . chr(155) => 't',
            // Euro Sign
            chr(226) . chr(130) . chr(172) => 'E',
            // GBP (Pound) Sign
            chr(194) . chr(163) => '',
            // Vowels with diacritic (Vietnamese)
            // unmarked
            chr(198) . chr(160) => 'O', chr(198) . chr(161) => 'o',
            chr(198) . chr(175) => 'U', chr(198) . chr(176) => 'u',
            // grave accent
            chr(225) . chr(186) . chr(166) => 'A', chr(225) . chr(186) . chr(167) => 'a',
            chr(225) . chr(186) . chr(176) => 'A', chr(225) . chr(186) . chr(177) => 'a',
            chr(225) . chr(187) . chr(128) => 'E', chr(225) . chr(187) . chr(129) => 'e',
            chr(225) . chr(187) . chr(146) => 'O', chr(225) . chr(187) . chr(147) => 'o',
            chr(225) . chr(187) . chr(156) => 'O', chr(225) . chr(187) . chr(157) => 'o',
            chr(225) . chr(187) . chr(170) => 'U', chr(225) . chr(187) . chr(171) => 'u',
            chr(225) . chr(187) . chr(178) => 'Y', chr(225) . chr(187) . chr(179) => 'y',
            // hook
            chr(225) . chr(186) . chr(162) => 'A', chr(225) . chr(186) . chr(163) => 'a',
            chr(225) . chr(186) . chr(168) => 'A', chr(225) . chr(186) . chr(169) => 'a',
            chr(225) . chr(186) . chr(178) => 'A', chr(225) . chr(186) . chr(179) => 'a',
            chr(225) . chr(186) . chr(186) => 'E', chr(225) . chr(186) . chr(187) => 'e',
            chr(225) . chr(187) . chr(130) => 'E', chr(225) . chr(187) . chr(131) => 'e',
            chr(225) . chr(187) . chr(136) => 'I', chr(225) . chr(187) . chr(137) => 'i',
            chr(225) . chr(187) . chr(142) => 'O', chr(225) . chr(187) . chr(143) => 'o',
            chr(225) . chr(187) . chr(148) => 'O', chr(225) . chr(187) . chr(149) => 'o',
            chr(225) . chr(187) . chr(158) => 'O', chr(225) . chr(187) . chr(159) => 'o',
            chr(225) . chr(187) . chr(166) => 'U', chr(225) . chr(187) . chr(167) => 'u',
            chr(225) . chr(187) . chr(172) => 'U', chr(225) . chr(187) . chr(173) => 'u',
            chr(225) . chr(187) . chr(182) => 'Y', chr(225) . chr(187) . chr(183) => 'y',
            // tilde
            chr(225) . chr(186) . chr(170) => 'A', chr(225) . chr(186) . chr(171) => 'a',
            chr(225) . chr(186) . chr(180) => 'A', chr(225) . chr(186) . chr(181) => 'a',
            chr(225) . chr(186) . chr(188) => 'E', chr(225) . chr(186) . chr(189) => 'e',
            chr(225) . chr(187) . chr(132) => 'E', chr(225) . chr(187) . chr(133) => 'e',
            chr(225) . chr(187) . chr(150) => 'O', chr(225) . chr(187) . chr(151) => 'o',
            chr(225) . chr(187) . chr(160) => 'O', chr(225) . chr(187) . chr(161) => 'o',
            chr(225) . chr(187) . chr(174) => 'U', chr(225) . chr(187) . chr(175) => 'u',
            chr(225) . chr(187) . chr(184) => 'Y', chr(225) . chr(187) . chr(185) => 'y',
            // acute accent
            chr(225) . chr(186) . chr(164) => 'A', chr(225) . chr(186) . chr(165) => 'a',
            chr(225) . chr(186) . chr(174) => 'A', chr(225) . chr(186) . chr(175) => 'a',
            chr(225) . chr(186) . chr(190) => 'E', chr(225) . chr(186) . chr(191) => 'e',
            chr(225) . chr(187) . chr(144) => 'O', chr(225) . chr(187) . chr(145) => 'o',
            chr(225) . chr(187) . chr(154) => 'O', chr(225) . chr(187) . chr(155) => 'o',
            chr(225) . chr(187) . chr(168) => 'U', chr(225) . chr(187) . chr(169) => 'u',
            // dot below
            chr(225) . chr(186) . chr(160) => 'A', chr(225) . chr(186) . chr(161) => 'a',
            chr(225) . chr(186) . chr(172) => 'A', chr(225) . chr(186) . chr(173) => 'a',
            chr(225) . chr(186) . chr(182) => 'A', chr(225) . chr(186) . chr(183) => 'a',
            chr(225) . chr(186) . chr(184) => 'E', chr(225) . chr(186) . chr(185) => 'e',
            chr(225) . chr(187) . chr(134) => 'E', chr(225) . chr(187) . chr(135) => 'e',
            chr(225) . chr(187) . chr(138) => 'I', chr(225) . chr(187) . chr(139) => 'i',
            chr(225) . chr(187) . chr(140) => 'O', chr(225) . chr(187) . chr(141) => 'o',
            chr(225) . chr(187) . chr(152) => 'O', chr(225) . chr(187) . chr(153) => 'o',
            chr(225) . chr(187) . chr(162) => 'O', chr(225) . chr(187) . chr(163) => 'o',
            chr(225) . chr(187) . chr(164) => 'U', chr(225) . chr(187) . chr(165) => 'u',
            chr(225) . chr(187) . chr(176) => 'U', chr(225) . chr(187) . chr(177) => 'u',
            chr(225) . chr(187) . chr(180) => 'Y', chr(225) . chr(187) . chr(181) => 'y',
            // Vowels with diacritic (Chinese, Hanyu Pinyin)
            chr(201) . chr(145) => 'a',
            // macron
            chr(199) . chr(149) => 'U', chr(199) . chr(150) => 'u',
            // acute accent
            chr(199) . chr(151) => 'U', chr(199) . chr(152) => 'u',
            // caron
            chr(199) . chr(141) => 'A', chr(199) . chr(142) => 'a',
            chr(199) . chr(143) => 'I', chr(199) . chr(144) => 'i',
            chr(199) . chr(145) => 'O', chr(199) . chr(146) => 'o',
            chr(199) . chr(147) => 'U', chr(199) . chr(148) => 'u',
            chr(199) . chr(153) => 'U', chr(199) . chr(154) => 'u',
            // grave accent
            chr(199) . chr(155) => 'U', chr(199) . chr(156) => 'u',
        );
        }
        
        if (!isset($this->chars_to_accentless_lower)) {
            $this->chars_to_accentless_lower = $this->chars_to_accentless;
            array_walk($this->chars_to_accentless_lower, function(&$val, $key) {
                $val = strtolower($val);
            });
        }
        
        // not mb_strstr uses byte wise encoding!
        if ($andToLower) {
            return strtolower(strtr($string, $this->chars_to_accentless_lower));
        } else {
            return strtr($string, $this->chars_to_accentless);
        }
    }
    
    const STARTS_WITH = 1;
    const ENDS_WITH = -1;
    const CONTAINS = 0;
    const EXACT = 2;
    
    protected function operatorToStringSearchRelation($operator, $defaultRealtion = SRUFromMysqlBase::EXACT) {        
        if (in_array($operator, array('==', 'exact'))) {
            return SRUFromMysqlBase::EXACT;
        } elseif (in_array($operator, array('>='))) {
            return SRUFromMysqlBase::STARTS_WITH;
        } elseif (in_array($operator, array('<='))) {
            return SRUFromMysqlBase::ENDS_WITH;
        } elseif (in_array($operator, array('any'))) {
            return SRUFromMysqlBase::CONTAINS;
        } else {
            return $defaultRealtion;
        }
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
    
    protected function parseStarAndRemove(array &$splittetSearchClause, $defaultRealtion = SRUFromMysqlBase::EXACT) {
        $starPos = mb_strrpos($splittetSearchClause['searchString'], '*');
        if ($starPos !== false) {
            if ($starPos === mb_strlen($splittetSearchClause['searchString']) - 1) {
                $splittetSearchClause['searchString'] = mb_substr($splittetSearchClause['searchString'], 0,
                                                        $starPos);
                $beginStar = mb_strpos($splittetSearchClause['searchString'], '*') === 0;
                if ($beginStar) {
                    $splittetSearchClause['searchString'] = mb_substr($splittetSearchClause['searchString'], 1);
                    return SRUFromMysqlBase::CONTAINS;
                }
                else { return SRUFromMysqlBase::STARTS_WITH; }
            } elseif ($starPos === 0) {
                $splittetSearchClause['searchString'] = mb_substr($splittetSearchClause['searchString'], 1);
                return SRUFromMysqlBase::ENDS_WITH;
            }
        }
        return $defaultRealtion;
    }

    protected function utf8_strrev($str) {
        preg_match_all('/./us', $str, $ar);
        return join('',array_reverse($ar[0]));
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
 *                                searchRelation => Whether to search for exactly that string, default
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
public function sqlForXPath($table, $xpath, $options = NULL, $justWordList = false) {
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
            if (!is_array($xpath)) {
                $xpaths = explode('|', $xpath);
            } else {
                $xpaths = $xpath;
            }
            $likeXpath .= "(";
            foreach ($xpaths as $xpath) {
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
            if (isset($options["searchRelation"]) && $options["searchRelation"] === SRUFromMysqlBase::EXACT) {
               $query .= "(ndx.txt = '$q' OR ndx.txt = '$qEnc') ";
            } elseif (isset($options["searchRelation"]) && $options["searchRelation"] === SRUFromMysqlBase::STARTS_WITH) {
               $query .= "(ndx.txt LIKE '$q%' OR ndx.txt LIKE '$qEnc%') ";
            } elseif (isset($options["searchRelation"]) && $options["searchRelation"] === SRUFromMysqlBase::ENDS_WITH) {
               $query .= "(ndx.txt LIKE '%$q' OR ndx.txt LIKE '%$qEnc') ";
            } elseif (($q !== '') && (isset($options["searchRelation"]) && $options["searchRelation"] === SRUFromMysqlBase::CONTAINS)) {
               $query .= "(ndx.txt LIKE '%$q%' OR ndx.txt LIKE '%$qEnc%') ";
            } else {
               $query .= "ndx.txt LIKE '%' ";
            }
        }
        
        $indexTableWhereClause = "WHERE ". $this->_and($query, $this->_and($filter, $likeXpath));
        $indexTableWhereClause = ($indexTableWhereClause === "WHERE ") ? '' : $indexTableWhereClause;

        $indexTableForJoin = $this->hasOnlyRealXPathFilters($options) ? $tableNameOrPrefilter :
                "(SELECT ndx.id, ndx.txt, ndx.weight FROM " . $tableNameOrPrefilter . // kil
                " AS ndx $indexTableWhereClause)";
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
        $groupAndLimit .= " ORDER BY ndx.weight DESC, base.lemma ASC";
        if (isset($options["startRecord"]) && $options["startRecord"] !== false) {
            $groupAndLimit .= " LIMIT " . ($options["startRecord"] - 1);
        }
        $sqlCalcRows = '';
        if (isset($options["maximumRecords"]) && $options["maximumRecords"] !== false) {
            $sqlCalcRows = 'SQL_CALC_FOUND_ROWS';
            if (isset($options["startRecord"]) && $options["startRecord"] !== false) {
                $groupAndLimit .= ", " . $options["maximumRecords"];
            } else {
                $groupAndLimit .= " LIMIT 0," .  $options["maximumRecords"];
            }
        }
    }
    
    return "SELECT " . $sqlCalcRows .
            ($justCount ? " COUNT(*) " : " ndx.txt, " .
                ($justWordList ? "'', ''" : "base.entry, base.sid") .
            $lemma . $groupCount) .
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
        $p = $this->parseFilterSpecs(current($options["xpath-filters"]));
        $whereClause = "CAST(inner.txt AS ".$p['as'].") ".$p['op']." ".$p['value']." ";
    } else {
        $whereClause = "inner.txt = '" . current($options["xpath-filters"]) . "' ";
    }
    $innerSql = "(SELECT inner.id, inner.txt FROM $indexTable AS `inner` WHERE ". 
                   $whereClause .
                    "AND inner.xpath LIKE '%$xpathToSearchIn')";
    $result = $this->hasOnlyRealXPathFilters($options) ? $tableOrPrefilter :
            "(SELECT tab.id, tab.xpath, tab.txt, tab.weight FROM $tableOrPrefilter AS tab ".
            "INNER JOIN " .
            $innerSql." AS prefid ". 
            "ON tab.id = prefid.id $filter)";
    return $result;
}

public function scanSqlForXPath($table, $xpath, $options = NULL) {
    $query = "";
    $filter = "";
    $groupAndLimit = " GROUP BY ndx.txt";
    $likeXpath = "";
    if (isset($options) && is_array($options)) {
        if ($this->ignorePosition) {
            $filterLike = str_replace('*', '%', $this->params->xfilter);
            $filterLike = strpos($filter, '%') === false ? $filterLike . '%' : $filterLike;
            if ($filterLike !== '%') {
                $filter = 'ndx.txt LIKE "' . $filterLike . '"';
            }
        }
        if ($xpath !== "") {
            if (!is_array($xpath)) {
                $xpaths = explode('|', $xpath);
            } else {
                $xpaths = $xpath;
            }
            $likeXpath .= "(";
            foreach ($xpaths as $xpath) {
                $likeXpath .= "ndx.xpath LIKE '%" . $xpath . "' OR ";
            }
            $likeXpath = substr($likeXpath , 0, strrpos($likeXpath, ' OR '));
            $likeXpath .= ')';
        }
        // ndx search
        $indexTable = $table . "_ndx";
        if (isset($options["xpath-filters"])) {
            $tableNameOrPrefilter = $this->genereatePrefilterSql($table, $options);
        } else {
            $tableNameOrPrefilter = $indexTable;
        }
        
        $indexTableWhereClause = "WHERE ". $this->_and($query, $this->_and($filter, $likeXpath));
        $indexTableWhereClause = ($indexTableWhereClause === "WHERE ") ? '' : $indexTableWhereClause;
        
        $indexTableForJoin = "SELECT ndx.txt, COUNT(*) FROM " . $tableNameOrPrefilter .
                " AS ndx $indexTableWhereClause";
        
        $sqlCalcRows = '';
        if (isset($options["maximumRecords"]) && $options["maximumRecords"] !== false) {
            $sqlCalcRows = 'SQL_CALC_FOUND_ROWS';
            if (isset($options["startRecord"]) && $options["startRecord"] !== false) {
                $groupAndLimit .= ", " . $options["maximumRecords"];
            } else {
                $groupAndLimit .= " LIMIT 0," .  $options["maximumRecords"];
            }
        }
    }
    
    return $indexTableForJoin . $groupAndLimit;
}

private $xPathPrefilter = '';

protected function generateXPathPrefilter($table, &$options) {
    if ($this->xPathPrefilter !== '') return $this->xPathPrefilter;
    $extractValueToCondition = array();
    $filternum = 0;
    $filters = $options["xpath-filters"];
    foreach ($filters as $xpathToSearchIn => $condition) {
        $colname = 'f'.(string)$filternum;
        
        if ($condition === null) {
            if (isset($options['searchRelation']) && $options['searchRelation'] === SRUFromMysqlBase::STARTS_WITH) {
                $havingCondition = ' LIKE \''.$options["query"].'%\'';           
            } elseif (isset($options['searchRelation']) && $options['searchRelation'] === SRUFromMysqlBase::ENDS_WITH) {
                $havingCondition = ' LIKE \'%'.$options["query"].'\'';
            } else {
                $havingCondition = '!= \'\'';
            }
        } else {
            $havingCondition = '!= \'\'';            
        }
        if ($xpathToSearchIn[0] === '/') {
            $q = $options["query"];            
            if ($condition === null) {
                $colname = 'txt';
                if ($q === '') {
                    $predicate = ''; 
                } elseif (isset($options['searchRelation']) and ($options['searchRelation'] === SRUFromMysqlBase::EXACT)) {
                    $predicate = "[.=\"$q\"]"; 
                } else {
                    $predicate = "[contains(., \"$q\")]"; 
                }
            } else {
                if (is_array($condition)) {
                    $p = parseFilterSpecs($condition);
                    $predicate = '['.'.'.$p['op'].$p['value'].']';
                } elseif ((mb_strlen($condition) > 0) && ($condition[0] === '[')) {
                    $predicate = $condition;
                } else {
                    $predicate = '';
                }  
            }
            $xpath = $xpathToSearchIn.$predicate;
            // Unfortunately MySQL's TRIM does not deal with \r\n. 
            $extractValueToCondition["Trim(Replace(ExtractValue(base.entry, '$xpath'), '\\r\\n', ' ')) AS '$colname'"] =
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
        $tmpl->setVar('query', $this->params->getQuery());
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
            try {
                \xdebug_stop_error_collection();
            } catch(\ACDH\FCSSRU\ErrorOrWarningException $e) {}
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
/**
 *
 * @uses string $scanTemplate
 * @uses integer $chacheScanResultForSeconds
 * @param string $sqlstr
 * @param string $entry
 * @param integer $searchRelation
 * @param boolean $isNumber
 * @return string
 */
protected function getScanResult($sqlstr, $entry = NULL, $searchRelation = SRUFromMysqlBase::STARTS_WITH, $isNumber = false) {
    if (is_array($sqlstr)) {
        return $this->aggregateMultipleScans($sqlstr);
    }
    if ($this->scanTemplateFilename === '') {
        global $scanTemplate;
        $this->scanTemplateFilename = $scanTemplate; 
    }
    global $chacheScanResultForSeconds;
        
    $maximumTerms = $this->params->maximumTerms;
    
    $cache_key = hash('sha256', $sqlstr);
    if (function_exists('apc_fetch') || ini_get('apc.enabled')) {
#        5.1.0 -> PHP 7
#        $sortedTerms = apcu_entry($cache_key, function() use ($sqlstr, $isNumber){
#            return $this->fetchSortedArrayFromDB($sqlstr, $isNumber);            
#        }, $chacheScanResultForSeconds);$this->fetchSortedArrayFromDB($sqlstr, $isNumber);
#        4.0.10 -> PHP 5.x
//        $sortedTerms = apc_fetch($cache_key);
//        if ($sortedTerms === FALSE) {
            $sortedTerms = $this->fetchSortedArrayFromDB($sqlstr, $isNumber);
//            apc_store($cache_key, $sortedTerms, $chacheScanResultForSeconds);
//        } else {
//            $sqlstr = 'Cached: '.$sqlstr;
//        }
    } else {
        $sortedTerms = $this->fetchSortedArrayFromDB($sqlstr, $isNumber);
    }
    if ($sortedTerms !== NULL) {
       
        ErrorOrWarningException::$code_has_known_errors = true;
        $tmpl = new vlibTemplate($this->scanTemplateFilename);
        ErrorOrWarningException::$code_has_known_errors = false;
        
        $numberOfRecords = count($sortedTerms);
        
        if (($this->params->xfilter !== false) && ($this->params->xfilter !== '')) {
            $options = array();
            $options['searchString'] = $this->params->xfilter;
            $fuzzyIncludesCaseInsensitive = true;
            $fuzzyFilter = $options['searchString'] === $this->remove_accents($options['searchString'], $fuzzyIncludesCaseInsensitive);
            $searchRelation = $this->parseStarAndRemove($options, SRUFromMysqlBase::STARTS_WITH);
            $filteredSortedTerms = array_filter($sortedTerms, function($var)
                use ($searchRelation, $options, $fuzzyFilter, $fuzzyIncludesCaseInsensitive) {
                $value = $fuzzyFilter ? $this->remove_accents($var['value'], $fuzzyIncludesCaseInsensitive) : $var['value'];
                $searchString = $options['searchString'];
                switch ($searchRelation) {
                    case SRUFromMysqlBase::ENDS_WITH :
                        return mb_strpos($value, $searchString) ===
                        (mb_strlen($value) - mb_strlen($searchString));
                    case SRUFromMysqlBase::CONTAINS :
                        return mb_strpos($value, $searchString) !== FALSE;
                    case SRUFromMysqlBase::STARTS_WITH :
                        return mb_strpos($value, $searchString) === 0;
                    default :
                        return $value === $searchString;                   
                } 
            });
            $sortedTerms = array();
            foreach ($filteredSortedTerms as $key => $value) {
                $value['position'] = $key;
                array_push($sortedTerms, $value); 
            }
        }

        $startPosition = 0;
        if (isset($entry)) {
            $startAtString = is_array($entry) ? $entry[0] : $entry;
            while ($startAtString !== "" && $startPosition < count($sortedTerms)) {
                $value = $sortedTerms[$startPosition]["value"];
                $found = mb_strpos($value, $startAtString);
                if (($searchRelation === SRUFromMysqlBase::STARTS_WITH ||
                     $searchRelation === SRUFromMysqlBase::EXACT) ? $found === 0 : $found !== false) {
                    if ($searchRelation === SRUFromMysqlBase::STARTS_WITH ||
                        $searchRelation === SRUFromMysqlBase::CONTAINS) {
                        break;
                    } elseif ($searchRelation === SRUFromMysqlBase::ENDS_WITH) {
                        $found = mb_strrpos($value, $startAtString);
                        $endingStart = mb_strlen($value) -
                               mb_strlen($startAtString);
                        if ($found === $endingStart){ break; }
                    } elseif ($value === $startAtString) {
                        break;
                    }                    
                }
                $startPosition++;
            }
        }
        $position = ($startPosition - $this->params->responsePosition) + 1;
        $position = $position <= 0 ? 1 : $position;
        $i = $position - 1;
        $shortList = array();
        $endPosition = min($position + $maximumTerms - 1, count($sortedTerms));
        while ($i < $endPosition){
            $sortedTerms[$i]['value'] = htmlentities($sortedTerms[$i]['value'], ENT_XML1);
            if (isset($sortedTerms[$i]['displayTerm'])) {
                $sortedTerms[$i]['displayTerm'] = htmlentities($sortedTerms[$i]['displayTerm'], ENT_XML1);
            } 
            array_push($shortList, $sortedTerms[$i]);
            if ($this->ignorePosition) {
               $shortList[$i - $position + 1]["position"] = -1; 
            } else {
                if (!isset($shortList[$i - $position + 1]["position"])) {
                    $shortList[$i - $position + 1]["position"] = $i + 1;            
                }
            }
            $i++;
        }
        
        if (count($shortList) > 0) {
            $tmpl->setloop('terms', $shortList);
        }

        $tmpl->setVar('version', $this->params->version);
        $tmpl->setVar('count', $numberOfRecords);
        $tmpl->setVar('transformedQuery', str_replace('<', '&lt;', $sqlstr));
        $tmpl->setVar('clause', $this->params->scanClause);
        $responsePosition = 0;
        $tmpl->setVar('responsePosition', $responsePosition);
        $tmpl->setVar('maximumTerms', $maximumTerms);
        $tmpl->setVar('xcontext', $this->params->xcontext);
        $tmpl->setVar('xfilter', $this->params->xfilter);
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

private function aggregateMultipleScans(array $scans) {
        // for speed reasons in autocomplete
        $this->ignorePosition = true;
        $scanClause = $this->params->queryParts;
        $xmlDoc = new \DOMDocument;
        $xmlResultDoc = new \DOMDocument;
        foreach($scans as $partScan) {
            $scanClause['index'] = $partScan;
            $indexDescrition = $this->getIndexDescription($scanClause);            
            $xmlDoc->loadXML($this->scan($scanClause)->getBody());
            if (!$xmlResultDoc->hasChildNodes()) {
                $this->registerSRUFCSResultNameSpaces($xmlDoc);
                $xmlResultDoc->appendChild($xmlResultDoc->importNode($xmlDoc->documentElement, true));
                $this->registerSRUFCSResultNameSpaces($xmlResultDoc);
                $resultSearch = new \DOMXPath($xmlResultDoc);
            } else {
                $search = new \DOMXPath($xmlDoc);             
                $terms = $search->query('/sru:scanResponse/sru:terms[1]/sru:term');
                $resultTermsNode = $resultSearch->query('/sru:scanResponse/sru:terms[1]')->item(0);
                foreach($terms as $term) {
                    $resultTermsNode->appendChild($xmlResultDoc->importNode($term, true));
                }
                $countTerms = $search->query('//fcs:countTerms[1]')->item(0)->textContent;
                $transformedQueryNode = $xmlResultDoc->importNode($search->query('//fcs:transformedQuery[1]')->item(0), true);
                $resultExtraResponseData = $resultSearch->query('/sru:scanResponse/sru:extraResponseData[1]')->item(0);
                $resultCountTermsNode = $resultSearch->query('//fcs:countTerms[1]')->item(0);
                $resultExtraResponseData->appendChild($transformedQueryNode);
                $resultCountTermsNode->textContent = (int)$countTerms + (int)$resultCountTermsNode->textContent;
            }
            $typeLessTerms = $resultSearch->query('//sru:extraTermData[not(cr:type)]');
            foreach ($typeLessTerms as $typeLessTerm) {
                $typeNode = $xmlResultDoc->createElementNS('http://aac.ac.at/content_repository', 'cr:type');
                $label = $xmlResultDoc->createAttribute('l');
                $label->value = $indexDescrition['title'];
                $typeNode->appendChild($label);
                $typeNode->appendChild($xmlResultDoc->createTextNode($partScan));
                $typeLessTerm->appendChild($typeNode);
            }
        }
        return $xmlResultDoc->saveXML();
}

private function registerSRUFCSResultNameSpaces(\DOMDocument $xmlDoc) {
    $xmlDoc->createAttributeNS('http://clarin.eu/fcs/1.0', 'fcs:create-ns');
    $xmlDoc->createAttributeNS('http://www.loc.gov/zing/srw/', 'sru:create-ns');
    $xmlDoc->createAttributeNS('http://aac.ac.at/content_repository', 'cr:create-ns');
    $xmlDoc->createAttributeNS('http://www.loc.gov/zing/srw/diagnostic/', 'diag:create-ns');    
}

private function fetchSortedArrayFromDB($sqlstr, $isNumber = false) {
    $sortedTerms = array();
    $result = $this->db->query($sqlstr);
    if ($result !== FALSE) {        
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
            $term["sortValue"] = trim(preg_replace('/[?¿!¡()*,."„“\\-\\/|=]/u', '', mb_strtoupper($term["value"], 'UTF-8')));
            $term["sortValue"] = preg_replace('/^Ä/u', 'AzA', $term["sortValue"]);
            $term["sortValue"] = preg_replace('/^Ü/u', 'UzU', $term["sortValue"]);
            $term["sortValue"] = preg_replace('/^Ö/u', 'OzO', $term["sortValue"]);
            // mixes the rest of the diacritic characters with their base parts.
            $term["sortValue"] = $this->remove_accents($term["sortValue"]);
            // sort strings that start with numbers at the back.
            $term["sortValue"] = preg_replace('/^(\d)/u', 'zz${1}', $term["sortValue"]);
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
    }
    return $sortedTerms;
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
 * @param bool $searchRelation If the start word needs to be exactly the specified or
 *                    if it should be just anywhere in the string.
 */
public function populateScanResult($db, $sqlstr, $entry = NULL, $searchRelation = SRUFromMysqlBase::STARTS_WITH, $isNumber = false) {
    $this->db = $db;
    $ret = $this->getScanResult($sqlstr, $entry, $searchRelation, $isNumber);
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
    if ($this->db instanceof SRUDiagnostics) {
        return $this->diagnosticsToResponse($this->db);
    }
    if ($this->db->connect_errno) {
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
    
    private $nonAsciiMap = array();
    
    // Convert an UTF-8 encoded string to a single-byte string suitable for
    // functions such as levenshtein.
    //
    // The function simply uses (and updates) a tailored dynamic encoding
    // (in/out map parameter) where non-ascii characters are remapped to
    // the range [128-255] in order of appearance.
    //
    // Thus it supports up to 128 different multibyte code points max over
    // the whole set of strings sharing this encoding.
    //
    private function utf8_to_extended_ascii($str) {
        // find all multibyte characters (cf. utf-8 encoding specs)
        $matches = array();
        if (!preg_match_all('/[\xC0-\xF7][\x80-\xBF]+/', $str, $matches))
            return $str; // plain ascii string

            
        // update the encoding map with the characters not already met
        // what happens here if there are more than 128 characters in here?
        foreach ($matches[0] as $mbc)
            if (!isset($this->nonAsciiMap[$mbc]))
                $this->nonAsciiMap[$mbc] = chr(128 + count($this->nonAsciiMap));
            if (count($this->nonAsciiMap) > 127) {
                throw new \Exception("System error!");
            }

        // finally remap non-ascii characters
        return strtr($str, $this->nonAsciiMap);
    }
    
    protected function levenshtein($str1, $str2) {
        $s1 = $this->utf8_to_extended_ascii($str1);
        $s2 = $this->utf8_to_extended_ascii($str2);
        return \levenshtein($s1, $s2);
    }

}
