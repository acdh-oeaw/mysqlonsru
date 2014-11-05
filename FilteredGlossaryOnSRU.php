<?php

namespace ACDH\FCSSRU\mysqlonsru;

use ACDH\FCSSRU\mysqlonsru\GlossaryOnSRU,
    ACDH\FCSSRU\SRUWithFCSParameters,
    ACDH\FCSSRU\HttpResponseSender;

require_once __DIR__ . '/../../vendor/autoload.php';
// this is not autoload enabled yet. There are to many magic global constants that need to be set when loading.
if (!isset($runner)) {
    $resetRunner = true;
    $runner = true;
}
require_once __DIR__ . '/GlossaryOnSRU.php';
if (isset($resetRunner)) {
    unset($runner);
    unset($resetRunner);
}

class FilteredGlossaryOnSRU extends GlossaryOnSRU {
    public function __construct(SRUWithFCSParameters $params = null) {
        parent::__construct($params);
        $this->params->context[0] = rtrim($this->params->context[0], 'F');
    }
    
    protected function addFilter() {
        $this->options["xpath-filters"] = array(
            "-bibl-tunisCourse-" => array('as' => 'signed int', '<=' => '3'),
        );
    }
    
    public function scan() {
        $this->addFilter();
        return parent::scan();
    }
    
    public function search() {
        $this->addFilter();        
        return parent::search();
    }
}

if (!isset($runner)) {
    $worker = new FilteredGlossaryOnSRU(new SRUWithFCSParameters('lax'));
    $response = $worker->run();
    HttpResponseSender::sendResponse($response);
}

