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

