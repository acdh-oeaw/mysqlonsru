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
    
    protected function addFilter() {
        $this->options["xpath-filters"] = array(
            "entry-xr-bibl-tunisCourse-" => array('as' => 'signed int', '<' => '2'),
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

