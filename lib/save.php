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
 * saves results based on the arguments defined in ../run.sh
 */
require_once(dirname(__FILE__) . '/NetworkTest.php');
require_once(dirname(__FILE__) . '/benchmark/save/BenchmarkDb.php');
$status = 1;
$args = parse_args(array('iteration:', 'nostore_rrd', 'nostore_traceroute', 'params_file:', 'recursive_order:', 'recursive_count:', 'v' => 'verbose'), array('params_file'), 'save_');
$verbose = isset($args['verbose']);
print_msg(sprintf('Initiating save with arguments [%s]', implode(', ', array_keys($args))), $verbose, __FILE__, __LINE__);

// save to multiple repositories (multiple --params_file parameters)
if (isset($args['params_file']) && count($args['params_file']) > 1) {
  $cmd = __FILE__;
  for($i=1; $i<count($argv); $i++) if ($argv[$i] != '--params_file' && !in_array($argv[$i], $args['params_file'])) $cmd .= ' ' . $argv[$i]; 
  foreach($args['params_file'] as $i => $pfile) {
    $pcmd = sprintf('%s --params_file %s --recursive_order %d --recursive_count %d', $cmd, $pfile, $i+1, count($args['params_file']));
    print($pcmd . "\n\n");
    passthru($pcmd);
  }
  exit;
}

// get result directories => each directory stores 1 iteration of results
$dirs = array();
$dir = count($argv) > 1 && is_dir($argv[count($argv) - 1]) ? $argv[count($argv) - 1] : trim(shell_exec('pwd'));
if (is_dir(sprintf('%s/1', $dir))) {
  $i = 1;
  while(is_dir($sdir = sprintf('%s/%d', $dir, $i++))) $dirs[] = $sdir;
}
else $dirs[] = $dir;

if ($db =& BenchmarkDb::getDb()) {
  // get results from each directory
  foreach($dirs as $i => $dir) {
    $test = new NetworkTest($dir);
    $runOptions = $test->getRunOptions();
    if (!$verbose && isset($runOptions['verbose'])) $verbose = TRUE;
    
    $iteration = isset($args['iteration']) && preg_match('/([0-9]+)/', $args['iteration'], $m) ? $m[1]*1 : $i + 1;
    if ($results = $test->getResults()) {
      print_msg(sprintf('Saving results in directory %s for iteration %d', $dir, $iteration), $verbose, __FILE__, __LINE__);
      foreach(array('nostore_rrd' => 'collectd-rrd.zip', 'nostore_rrd' => 'collectd-rrd.zip', 'nostore_traceroute' => 'traceroute.log') as $arg => $file) {
        $file = sprintf('%s/%s', $dir, $file);
        if (!isset($args[$arg]) && file_exists($file)) {
          $pieces = explode('_', $arg);
          $col = $arg == 'nostore_rrd' ? 'collectd_rrd' : $pieces[count($pieces) - 1];
          $saved = $db->saveArtifact($file, $col);
          if ($saved) print_msg(sprintf('Saved %s successfully', basename($file)), $verbose, __FILE__, __LINE__);
          else if ($saved === NULL) print_msg(sprintf('Unable to save %s', basename($file)), $verbose, __FILE__, __LINE__, TRUE);
          else print_msg(sprintf('Artifact %s will not be saved because --store was not specified', basename($file)), $verbose, __FILE__, __LINE__);
        }
        else if (file_exists($file)) print_msg(sprintf('Artifact %s will not be saved because --%s was set', basename($file), $arg), $verbose, __FILE__, __LINE__);
      }
      foreach(array_keys($results) as $n) {
        $results[$n]['iteration'] = $iteration;
        if ($db->addRow('network', $results[$n])) print_msg(sprintf('Successfully added test result row'), $verbose, __FILE__, __LINE__);
        else print_msg(sprintf('Failed to save test results'), $verbose, __FILE__, __LINE__, TRUE); 
      }
    }
    else print_msg(sprintf('Unable to save results in directory %s - are result files present?', $dir), $verbose, __FILE__, __LINE__, TRUE);
  }
  
  // finalize saving of results
  if ($db->save()) {
    print_msg(sprintf('Successfully saved test results from directory %s', $dir), $verbose, __FILE__, __LINE__);
    $status = 0;
  }
  else {
    print_msg(sprintf('Unable to save test results from directory %s', $dir), $verbose, __FILE__, __LINE__, TRUE);
    $status = 1;
  }
  
  // check for --min_runtime test option
  if ((!isset($args['recursive_order']) || (isset($args['recursive_count']) && $args['recursive_order'] == $args['recursive_count'])) && 
      isset($runOptions['min_runtime']) && isset($runOptions['min_runtime_in_save']) && 
      is_numeric($runOptions['min_runtime']) && isset($runOptions['run_start']) && is_numeric($runOptions['run_start']) &&
      time() < ($runOptions['run_start'] + $runOptions['min_runtime'])) {
    $sleep = ($runOptions['run_start'] + $runOptions['min_runtime']) - time();
    print_msg(sprintf('Testing complete and --min_runtime %d has not been acheived. Sleeping for %d seconds', $runOptions['min_runtime'], $sleep), $verbose, __FILE__, __LINE__);
    sleep($sleep);
    print_msg(sprintf('Sleep complete'), $verbose, __FILE__, __LINE__);
  }
}

exit($status);
?>
