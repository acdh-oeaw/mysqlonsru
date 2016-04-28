<?php
namespace ACDH\FCSSRU\mysqlonsru;

class EXPath2NdxSqlLikeInterpreter extends \Exception {};
/**
 * Description of XPath2NdxSqlLikeInterpreter
 *
 * @author osiam
 */
class XPath2NdxSqlLikeInterpreter {
    
    private $value;
    private $data;
    
    public function xpath2NdxSqlLike($value, &$data) {
        $this->value = str_replace('[{q}]', '' ,$value);
        $this->data = $data;
            $this->value = str_replace('//*', '%', $this->value);
            $this->value = str_replace('//', '%', $this->value);
            $this->treatAttrContains();
            if (preg_match('~\[[^\]]+\]$~', $this->value)) {
                $this->getAndRemoveSearchTerm();
            }
            $this->value = str_replace('%text()', '', $this->value);
            $this->value .= '%';
            $this->value = str_replace('\'', '"', $this->value);
            $this->splitOrAndAllowAnyAttrPredOrder();
        $data = $this->data;
        return $this->value;
    }
    
    private $functionPredicateSearch = '\[(?<relation>[-a-z]+)\((?<node>[^,]+),\s*["\'](?<term>.+)["\']\)\]';

    private function getAndRemoveSearchTerm() {        
        $equalsPredicateSearch = '\[(?<node>[^=\]]+)\s*=\s*["\'](?<term>[^\]]+)["\']\]';
        // find query txt
        $search = array();
        $lookForEqualsPredicate = true;
        if (preg_match_all('~'.$this->functionPredicateSearch.'~', $this->value, $search, PREG_SET_ORDER)) {
            $search = end($search);
            if ($search['node'] === '.') {
                $lookForEqualsPredicate = false;
                $this->value = preg_replace('~' . $this->functionPredicateSearch . '$~', '', $this->value);
                switch ($search['relation']) {
                    case "starts-with":
                        $this->data['txt'] = $search['term'] . '*';
                        break;
                    case "contains":
                        $this->data['txt'] = '*' . $search['term'] . '*';
                        break;
                    case "ends-with":
                        $this->data['txt'] = '*' . $search['term'];
                        break;
                    default: $this->xpath2SQLFailed();
                }
            }
        }
        if ($lookForEqualsPredicate) {
            if (preg_match_all('~' . $equalsPredicateSearch . '~', $this->value, $search, PREG_SET_ORDER)) {
                $search = end($search);
                if ($search['node'] === '.') {
                    $this->value = preg_replace('~' . $equalsPredicateSearch . '$~', '', $this->value);
                    $this->data['txt'] = $search['term'];
                }
            } else {
                $this->xpath2SQLFailed();
            }
        }
    }

    private function splitOrAndAllowAnyAttrPredOrder() {
        $findOr = '(?<firstPred>.+)\s+or\s+(?<lastPred>[^\]]+)';
        $findOrInPred = '~^(?<before>[^[]+\[)'.$findOr.'(?<after>.*)$~';
        $findOr = '~'.$findOr.'~';
        $parts = array();
        if (preg_match($findOrInPred, $this->value, $parts)){
            $this->value = array();
            $predParts = $parts;
            $moreParts = preg_match($findOr, $predParts['firstPred'], $predParts);
            if ($moreParts) {
                while($moreParts) {
                    array_push($this->value, $parts['before'].$predParts['lastPred'].$parts['after']);
                    $firstPred = $predParts['firstPred'];
                    $moreParts = preg_match($findOr, $firstPred, $predParts);
                }
                array_push($this->value, $parts['before'].$firstPred.$parts['after']);
            } else {
               array_push($this->value, $parts['before'].$parts['firstPred'].$parts['after']);
            }
            array_push($this->value, $parts['before'].$parts['lastPred'].$parts['after']);
            foreach ($this->value as $k => $v) {
                $this->value[$k] = $this->allowAnyAttrPredOrder($this->value[$k]);
            }
        } else {
            $this->value = $this->allowAnyAttrPredOrder($this->value);
        }
    }
    
    private function treatAttrContains() {
        if (is_array($this->value)) {
            $this->xpath2SQLFailed();
        }
        $this->value = preg_replace_callback('~'.$this->functionPredicateSearch.'~',
                function($match) {
                    return $this->replaceAttrCointains($match);
                }, $this->value);
    }
    
    private function replaceAttrCointains($predParts) {
        if ($predParts['relation'] !== 'contains') {return $predParts[0];}
        return '%'.$predParts['node'].'=%'.$predParts['term'].'%'; 
    }

    private function allowAnyAttrPredOrder($value) {
        $res = preg_replace('~\[([^\]]+)\]~', '%[$1]%', $value);
        return $res;
    }
    
    private function xpath2SQLFailed() {
        throw new EXPath2NdxSqlLikeInterpreter('Can\'t create SQL from XPath! Check implementation.');   
    }
}
