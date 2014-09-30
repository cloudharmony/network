#!/usr/bin/php -q
<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

/**
 * Renders results as key/value pairs. Keys are suffixed with a numeric 
 * value that is unique for each test (if more than 1 test performed)
 */
require_once(dirname(__FILE__) . '/NetworkTest.php');

$status = 1;
$dir = count($argv) > 1 && is_file($argv[count($argv) - 1]) ? dirname($argv[count($argv) - 1]) : trim(shell_exec('pwd'));
$test = new NetworkTest($dir);
$options = parse_args(array('v' => 'verbose'));
if ($rows = $test->getResults()) {
  foreach($rows as $i => $results) {
    $status = 0;
    $suffix = count($rows) > 1 ? $i + 1 : '';
    foreach($results as $key => $val) printf("%s%s=%s\n", $key, $suffix, $val);
  }
}
exit($status);
?>