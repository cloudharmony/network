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
 * Used to manage network testing
 */
require_once(dirname(__FILE__) . '/util.php');
ini_set('memory_limit', '16m');
date_default_timezone_set('UTC');

class NetworkTest {
  
  /**
   * name of the file where serializes options should be written to for given 
   * test iteration
   */
  const NETWORK_TEST_OPTIONS_FILE_NAME = '.options';
  
  /**
   * set to TRUE if abort_threshold reached
   */
  private $aborted = FALSE;
  
  /**
   * hash of country code/names
   */
  private $countries;
  
  /**
   * used to track which default runtime parameters were set
   */
  private $defaultsSet = array();
  
  /**
   * optional results directory object was instantiated for
   */
  private $dir;
  
  /**
   * hash of geo regions
   */
  private $geoRegions;
  
  /**
   * run options
   */
  private $options;
  
  /**
   * used to store test results during testing
   */
  private $results = array();
  
  
  /**
   * constructor
   * @param string $dir optional results directory object is being instantiated
   * for. If set, runtime parameters will be pulled from the .options file. Do
   * not set when running a test
   */
  public function NetworkTest($dir=NULL) {
    $this->dir = $dir;
  }
  
  /**
   * returns the content for the country specified. return value will be one of
   * the following:
   *   eu, asia, oceania, america_north, america_south, africa
   * @return string
   */
  private function getContinent($country) {
    $continent = NULL;
    if ($regions = $this->getGeoRegions()) {
      foreach(array('eu', 'oceania', 'asia', 'america_north', 'america_south', 'africa') as $c) {
        if (isset($regions[$c]) && in_array($country, $regions[$c])) {
          $continent = $c;
          print_msg(sprintf('Country %s is in continent %s', $country, $continent), $this->verbose, __FILE__, __LINE__);
          break;
        }
      }
    }
    return $continent;
  }
  
  /**
   * returns countries as a hash of ISO 3166 code/names
   * @return array
   */
  private function &getCountries() {
    if (!isset($this->countries)) {
      $this->countries = string_to_hash(file_get_contents(dirname(__FILE__) . '/config/iso3166.ini'));
    }
    return $this->countries;
  }
  
  /**
   * returns the first matching geo region identifier associated with the 
   * $country and $state specified
   * @param string $country the country
   * @param string $state the state (optional)
   * @return string
   */
  private function getGeoRegion($country, $state=NULL) {
    $geoRegion = NULL;
    if ($state && $country) {
      $loc = sprintf('%s,%s', $state, $country);
      foreach($this->getGeoRegions() as $region => $locations) {
        if (!in_array($region, $this->options['geo_regions'])) continue;
        if (in_array($loc, $locations)) {
          $geoRegion = $region;
          break;
        }
      }
    }
    if (!$geoRegion && $country) {
      foreach($this->getGeoRegions() as $region => $locations) {
        if (!in_array($region, $this->options['geo_regions'])) continue;
        if (in_array($country, $locations)) {
          $geoRegion = $region;
          break;
        }
      }
    }
    if ($geoRegion) print_msg(sprintf('Got geo region %s for country %s%s', $geoRegion, $country, $state ? ' and state ' . $state : ''), $this->verbose, __FILE__, __LINE__);
    return $geoRegion;
  }
  
  /**
   * returns geo regions as a hash indexed by region code where the value is an
   * array of [state],[country] or [country] values
   * @return array
   */
  private function &getGeoRegions() {
    if (!isset($this->geoRegions)) {
      $this->geoRegions = string_to_hash(file_get_contents(dirname(__FILE__) . '/config/geo-regions.ini'));
      foreach(array_keys($this->geoRegions) as $region) $this->geoRegions[$region] = explode('|', $this->geoRegions[$region]);
    }
    return $this->geoRegions;
  }
  
  /**
   * writes test results and finalizes testing
   * @return boolean
   */
  private function endTest() {
    $ended = FALSE;
    $dir = $this->options['output'];
    
    // serialize options
    $ofile = sprintf('%s/%s', $dir, self::NETWORK_TEST_OPTIONS_FILE_NAME);
    if (is_dir($dir) && is_writable($dir)) {
      $this->options['results'] =& $this->results;
      $fp = fopen($ofile, 'w');
      fwrite($fp, serialize($this->options));
      fclose($fp);
      $ended = TRUE;
    }
    
    return $ended;
  }
  
  /**
   * returns test results - an array of hashes each containing the results from
   * 1 test
   * @return array
   */
  public function getResults() {
    $this->getRunOptions();
    return isset($this->options['results']) ? $this->options['results'] : NULL;
  }
  
  /**
   * returns run options represents as a hash
   * @return array
   */
  public function getRunOptions() {
    if (!isset($this->options)) {
      if ($this->dir) {
        $this->options = self::getSerializedOptions($this->dir);
        $this->verbose = isset($this->options['verbose']);
      }
      else {
        // default run argument values
        $sysInfo = get_sys_info();
        $defaults = array(
          'dns_samples' => 10,
          'dns_timeout' => 30,
          'geo_regions' => 'us_east us_west us_central eu_west eu_central eu_east oceania asia_apac asia america_north america_central america_south africa',
          'latency_samples' => 10,
          'latency_timeout' => 3,
          'meta_cpu' => $sysInfo['cpu'],
          'meta_memory' => $sysInfo['memory_gb'] > 0 ? $sysInfo['memory_gb'] . ' GB' : $sysInfo['memory_mb'] . ' MB',
          'meta_os' => $sysInfo['os_info'],
          'output' => trim(shell_exec('pwd')),
          'spacing' => 500,
          'test' => 'latency',
          'throughput_same_continent' => 10,
          'throughput_same_country' => 20,
          'throughput_same_geo_region' => 30,
          'throughput_same_provider' => 10,
          'throughput_same_region' => 100,
          'throughput_same_state' => 50,
          'throughput_samples' => 10,
          'throughput_size' => 5,
          'throughput_threads' => 2,
          'throughput_timeout' => 120,
          'throughput_uri' => '/web-probe'
        );
        $opts = array(
          'abort_threshold:',
          'dns_one_server',
          'dns_samples:',
          'dns_tcp',
          'dns_timeout:',
          'geoiplookup',
          'geo_regions:',
          'latency_samples:',
          'latency_skip:',
          'latency_timeout:',
          'max_runtime:',
          'max_tests:',
          'meta_compute_service:',
          'meta_compute_service_id:',
          'meta_cpu:',
          'meta_instance_id:',
          'meta_location:',
          'meta_memory:',
          'meta_os:',
          'meta_provider:',
          'meta_provider_id:',
          'meta_region:',
          'meta_resource_id:',
          'meta_run_id:',
          'meta_test_id:',
          'output:',
          'params_url:',
          'params_url_service_type:',
          'params_url_header:',
          'randomize',
          'same_continent_only',
          'same_country_only',
          'same_geo_region',
          'same_provider_only',
          'same_region_only',
          'same_service_only',
          'same_state_only',
          'service_lookup',
          'spacing:',
          'suppress_failed',
          'test:',
          'test_endpoint:',
          'test_instance_id:',
          'test_location:',
          'test_private_network_type:',
          'test_provider:',
          'test_provider_id:',
          'test_region:',
          'test_service:',
          'test_service_id:',
          'test_service_type:',
          'throughput_https',
          'throughput_same_continent:',
          'throughput_same_country:',
          'throughput_same_geo_region:',
          'throughput_same_provider:',
          'throughput_same_region:',
          'throughput_same_service:',
          'throughput_same_state:',
          'throughput_samples:',
          'throughput_size:',
          'throughput_threads:',
          'throughput_timeout:',
          'throughput_uri:',
          'traceroute',
          'v' => 'verbose'
        );
        $this->options = parse_args($opts, array('latency_skip', 'params_url_service_type', 'params_url_header', 'test', 'test_endpoint', 'test_instance_id', 'test_location', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type'));
        $this->verbose = isset($this->options['verbose']);
        
        foreach($defaults as $key => $val) {
          if (!isset($this->options[$key])) {
            $this->options[$key] = $val;
            $this->defaultsSet[] = $key;
          }
        }
        
        // expand geo_regions
        if (isset($this->options['geo_regions'])) {
          $geoRegions =array();
          foreach(explode(',', $this->options['geo_regions']) as $r1) {
            foreach(explode(' ', $r1) as $r2) {
              $r2 = strtolower(trim($r2));
              if ($r2 && !in_array($r2, $geoRegions)) $geoRegions[] = $r2;
            }
          }
          if ($geoRegions) $this->options['geo_regions'] = $geoRegions;
        }
        
        // expand tests
        if (!is_array($this->options['test'])) $this->options['test'] = array($this->options['test']);
        foreach($this->options['test'] as $i => $test) {
          $tests = array();
          foreach(explode(',', $test) as $t1) {
            foreach(explode(' ', $t1) as $t2) {
              $t2 = strtolower(trim($t2));
              if ($t2 && !in_array($t2, $tests)) $tests[] = $t2;
            }
          }
          if ($tests) $this->options['test'][$i] = $tests;
          else unset($this->options['test'][$i]);
        }
        
        // get parameters from a URL
        if (isset($this->options['params_url'])) {
          $headers = array();
          if (isset($this->options['params_url_header'])) {
            foreach($this->options['params_url_header'] as $header) {
              if (preg_match('/^(.*):(.*)$/', $header, $m)) $headers[trim($m[1])] = trim($m[2]);
              else print_msg(sprintf('Skipping header %s because it is not properly formatted ([key]:[val])', $header), $this->verbose, __FILE__, __LINE__, TRUE);
            }
          }
          if ($params = json_decode($json = ch_curl($this->options['params_url'], 'GET', $headers, NULL, NULL, '200-299', TRUE), TRUE)) {
            print_msg(sprintf('Successfully retrieved %d runtime parameters from the URL %s', count($params), $this->options['params_url']), $this->verbose, __FILE__, __LINE__);
            foreach($params as $key => $val) {
              if (!isset($this->options[$key]) || in_array($key, $this->defaultsSet)) {
                // TODO: set tests based on probe_type and --test
                print_msg(sprintf('Added runtime parameter %s=%s from --params_url', $key, $val), $this->verbose, __FILE__, __LINE__);
                $this->options[$key] = $val;
              }
              else print_msg(sprintf('Skipping runtime parameter %s=%s from --params_url because it was set on the command line', $key, $val), $this->verbose, __FILE__, __LINE__);
            }
            // remove test endpoints that are not of the --params_url_service_type
            // specified
            if (isset($this->options['params_url_service_type'])) {
              foreach($this->options['test_endpoint'] as $i => $endpoint) {
                if (!isset($this->options['test_service_type'][$i]) || !in_array($this->options['test_service_type'][$i], $this->options['params_url_service_type'])) {
                  print_msg(sprintf('Removing test endpoint %s because it is not included in the allowed service types: %s', $endpoint, implode(', ', $this->options['params_url_service_type'])), $this->verbose, __FILE__, __LINE__);
                  unset($this->options['test_endpoint'][$i]);
                }
              }
            }
          }
          else return array('params_url' => is_string($json) ? sprintf('Response from --params_url %s is not valid JSON: %s', $this->options['params_url'], $json) : sprintf('Unable to retrieve data from --params_url %s', $this->options['params_url']));
        }
        
        // expand test endpoints: first element is public hostname/IP; second is private
        if (isset($this->options['test_endpoint'])) {
          foreach($this->options['test_endpoint'] as $i => $endpoint) {
            $endpoints = array();
            foreach(explode(',', $endpoint) as $e1) {
              foreach(explode(' ', $e1) as $e2) {
                $e2 = strtolower(trim($e2));
                if ($e2 && !in_array($e2, $endpoints)) $endpoints[] = $e2;
                if (count($endpoints) == 2) break;
              }
              if (count($endpoints) == 2) break;
            }
            if ($endpoints) $this->options['test_endpoint'][$i] = $endpoints;
            else unset($this->options['test_endpoint'][$i]);
          }
        }
        
        // perform service lookups using CloudHarmony 'Identify Service' API
        if (isset($this->options['service_lookup'])) {
          foreach($this->options['test_endpoint'] as $i => $endpoints) {
            $hostname = str_replace('*', rand(), get_hostname($endpoints[0]));
            if (!isset($this->options['test_service_id'][$i]) && ($response = get_service_info($hostname, $this->verbose))) {
              if (!isset($this->options['test_provider_id'][$i])) $this->options['test_provider_id'][$i] = $response['providerId'];
              if (!isset($this->options['test_service_id'][$i])) $this->options['test_service_id'][$i] = $response['serviceId'];
              if (!isset($this->options['test_service_type'][$i])) $this->options['test_service_type'][$i] = $response['serviceType'];
              if (!isset($this->options['test_region'][$i]) && isset($response['region'])) $this->options['test_region'][$i] = $response['region'];
              if (!isset($this->options['test_location'][$i]) && isset($response['country'])) $this->options['test_location'][$i] = sprintf('%s%s', isset($response['state']) ? $response['state'] . ',' : '', $response['country']);
            }
          }
          if (!isset($this->options['meta_compute_service_id']) && ($hostname = trim(shell_exec('hostname'))) && ($response = get_service_info($hostname, $this->verbose))) {
            if (!isset($this->options['meta_provider_id'])) $this->options['meta_provider_id'] = $response['providerId'];
            if (!isset($this->options['meta_compute_service_id'])) $this->options['meta_compute_service_id'] = $response['serviceId'];
            if (!isset($this->options['meta_region']) && isset($response['region'])) $this->options['meta_region'] = $response['region'];
            if (!isset($this->options['meta_location']) && isset($response['country'])) $this->options['meta_location'] = sprintf('%s%s', isset($response['state']) ? $response['state'] . ',' : '', $response['country']);
          }
        }
        
        // perform geo ip lookups to determine endpoint locations
        if (isset($this->options['geoiplookup'])) {
          foreach($this->options['test_endpoint'] as $i => $endpoints) {
            $hostname = str_replace('*', rand(), get_hostname($endpoints[0]));
            if (!isset($this->options['test_location'][$i]) && 
               (!isset($this->options['test_service_type'][$i]) || ($this->options['test_service_type'][$i] != 'cdn' && $this->options['test_service_type'][$i] != 'dns')) && 
               ($geoip = geoiplookup($hostname, $this->verbose))) {
              $this->options['test_location'][$i] = sprintf('%s%s', isset($geoip['state']) ? $geoip['state'] . ', ' : '', $geoip['country']);
            }
          }
          if (!isset($this->options['meta_location']) && ($hostname = trim(shell_exec('hostname'))) && ($geoip = geoiplookup($hostname, $this->verbose))) {
            $this->options['meta_location'][$i] = sprintf('%s%s', isset($geoip['state']) ? $geoip['state'] . ', ' : '', $geoip['country']);
          }
        }
        
        // expand meta_location to meta_location_country and meta_location_state
        if (isset($this->options['meta_location'])) {
          $pieces = explode(',', $this->options['meta_location']);
          $this->options['meta_location_country'] = strtoupper(trim($pieces[count($pieces) - 1]));
          if (count($pieces) > 1) $this->options['meta_location_state'] = trim($pieces[0]);
        }
        
        // expand test_location to test_location_country and test_location_state
        if (isset($this->options['test_location'])) {
          $this->options['test_location_country'] = array();
          $this->options['test_location_state'] = array();
          foreach($this->options['test_location'] as $i => $location) {
            $pieces = explode(',', $location);
            $this->options['test_location_country'][$i] = strtoupper(trim($pieces[count($pieces) - 1]));
            $this->options['test_location_state'][$i] = count($pieces) > 1 ? trim($pieces[0]) : NULL;
          }
        }
        
        // set meta_geo_region
        if (isset($this->options['meta_location'])) {
          $pieces = explode(',', $this->options['meta_location']);
          $this->options['meta_location_country'] = strtoupper(trim($pieces[count($pieces) - 1]));
          $this->options['meta_location_state'] = count($pieces) > 1 ? trim($pieces[0]) : NULL;
          if ($geoRegion = getGeoRegion($this->options['meta_location_country'], isset($this->options['meta_location_state']) ? $this->options['meta_location_state'] : NULL)) $this->options['meta_geo_region'] = $geoRegion;
        }
      }
    }
    return $this->options;
  }
  
  /**
   * returns options from the serialized file where they are written when a 
   * test completes
   * @param string $dir the directory where results were written to
   * @return array
   */
  public static function getSerializedOptions($dir) {
    return unserialize(file_get_contents(sprintf('%s/%s', $dir, self::NETWORK_TEST_OPTIONS_FILE_NAME)));
  }
  
  /**
   * initiates stream scaling testing. returns TRUE on success, FALSE otherwise
   * @return boolean
   */
  public function test() {
    $success = FALSE;
    
    $testsCompleted = 0;
    $testsFailed = 0;
    $testStarted = time();
    print_msg(sprintf('Initiating testing for %d test endpoints', count($this->options['test_endpoint'])), $this->verbose, __FILE__, __LINE__);
    
    // randomize testing order
    if (isset($this->options['randomize']) && $this->options['randomize']) {
      print_msg(sprintf('Randomizing test order'), $this->verbose, __FILE__, __LINE__);
      shuffle($this->options['test_endpoint']);
    }
    
    foreach($this->options['test_endpoint'] as $i => $endpoints) {
      // max test time
      if (isset($this->options['max_runtime']) && ($testStarted + $this->options['max_runtime']) <= time()) {
        print_msg(sprintf('--max_time %d seconds reached - aborting testing', $this->options['max_runtime']), $this->verbose, __FILE__, __LINE__);
        break;
      }
      // max tests
      else if (isset($this->options['max_tests']) && $testsCompleted >= $this->options['max_tests']) {
        print_msg(sprintf('--max_tests %d reached - aborting testing', $this->options['max_tests']), $this->verbose, __FILE__, __LINE__);
        break;
      }
      // abort threshold
      else if (isset($this->options['abort_threshold']) && $testsFailed >= $this->options['abort_threshold']) {
        $this->aborted = TRUE;
        print_msg(sprintf('--abort_threshold %d reached - aborting testing', $this->options['abort_threshold']), $this->verbose, __FILE__, __LINE__, TRUE);
        break;
      }
      else if (!$this->validateSameConstraints($i)) {
        print_msg(sprintf('Skipping testing for endpoint %s because one or more --same* constraints did not match', $endpoints[0]), $this->verbose, __FILE__, __LINE__);
        continue;
      }
      
      $tests = isset($this->options['test'][$i]) ? $this->options['test'][$i] : $this->options['test'][0];
      if (isset($this->options['randomize']) && $this->options['randomize']) shuffle($tests);
      print_msg(sprintf('Starting testing of endpoint %s - %d tests have been set', $endpoints[0], count($tests)), $this->verbose, __FILE__, __LINE__);
      foreach($tests as $x => $test) {
        // check for endpoints/service/providers to skip latency testing for
        if ($test == 'latency' && isset($this->options['latency_skip'])) {
          $hostname = get_hostname($endpoints[0]);
          $serviceId = isset($this->options['test_service_id']) ? (isset($this->options['test_service_id'][$i]) ? $this->options['test_service_id'][$i] : $this->options['test_service_id'][0]) : NULL;
          $providerId = isset($this->options['test_provider_id']) ? (isset($this->options['test_provider_id'][$i]) ? $this->options['test_provider_id'][$i] : $this->options['test_provider_id'][0]) : NULL;
          if (in_array($hostname, $this->options['latency_skip']) || in_array($serviceId, $this->options['latency_skip']) || in_array($providerId, $this->options['latency_skip'])) {
            print_msg(sprintf('Skipping latency test for endpoint %s; service %s; provider %s; due to --latency_skip constraint', $endpoints[0], $serviceId, $providerId), $this->verbose, __FILE__, __LINE__);
            continue;
          }
        }
        
        // spacing
        if ($i > 0 && isset($this->options['spacing'])) {
          usleep($this->options['spacing']*1000);
          print_msg(sprintf('Applied test spacing of %d ms', $this->options['spacing']), $this->verbose, __FILE__, __LINE__);
        }
        
        $results = array();
        $privateEndpoint = FALSE;
        print_msg(sprintf('Starting %s test against endpoint %s', $test, $endpoints[0]), $this->verbose, __FILE__, __LINE__);
        
        // iterate through both private and public endpoint addresses
        $testStart = NULL;
        $testStop = NULL;
        foreach(array_reverse($endpoints) as $n => $endpoint) {
          if (count($endpoints) > 1 && $n == 0 && !$this->usePrivateNetwork($i)) {
            print_msg(sprintf('Skipping private network endpoint %s because services are not related', $endpoint), $this->verbose, __FILE__, __LINE__);
            continue;
          }
          $testStart = date('Y-m-d H:i:s');
          switch($test) {
            case 'latency':
              $results['latency'] = $this->testLatency($endpoint);
              break;
            case 'throughput':
            case 'downlink':
              $results['downlink'] = $this->testDownlink($endpoint);
              if ($test == 'downlink') break;
            case 'uplink':
              $results['uplink'] = $this->testUplink($endpoint);
              break;
            case 'dns':
              $results['dns'] = $this->testDns($endpoint);
              break;
          }
          $testStop = date('Y-m-d H:i:s');
          // check if test completed
          $done = FALSE;
          foreach(array_keys($results) as $key) {
            if (is_array($results[$key])) $done = TRUE;
          }
          if ($done) {
            if (count($endpoints) > 1 && $n == 0) $privateEndpoint = TRUE;
            break;
          }
        }
        
        foreach($results as $test => $metrics) {
          $success = TRUE;
          $row = array('test' => $test, 'test_endpoint' => $endpoint, 'test_started' => $testStart, 'test_stopped' => $testStop);
          if ($country = isset($this->options['test_location_country'][$i]) ? $this->options['test_location_country'][$i] : NULL) {
            $state = isset($this->options['test_location_state'][$i]) ? $this->options['test_location_state'][$i] : NULL;
            if ($geoRegion = $this->getGeoRegion($country, $state)) $row['test_geo_region'] = $geoRegion;
          }
          
          // add additional test_* result attributes
          foreach(array('test_instance_id', 'test_location', 'test_location_country', 'test_location_state', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type') as $param) {
            if (isset($this->options[$param]) && (isset($this->options[$param][$i]) || isset($this->options[$param][0]))) $row[$param] = isset($this->options[$param][$i]) ? $this->options[$param][$i] : $this->options[$param][0];
          }
          // private endpoint?
          if ($privateEndpoint) {
            $row['test_private_endpoint'] = TRUE;
            if (isset($this->options['test_private_network_type'])) $row['test_private_network_type'] = isset($this->options['test_private_network_type'][$i]) ? $this->options['test_private_network_type'][$i] : $this->options['test_private_network_type'][0];
          }
          $row['timeout'] = $this->options[sprintf('%s_timeout', $test == 'dns' || $test == 'latency' ? $test : 'throughput')];
          
          if (isset($metrics) && is_array($metrics)) {
            $testsCompleted++;
            print_msg(sprintf('%s test for endpoint %s completed successfully', $test, $endpoint), $this->verbose, __FILE__, __LINE__);
            if (isset($metrics['metrics'])) $row = array_merge($row, $metrics);
            else $row['metrics'] = $metrics;
            // determine status (if one or more non-numeric values in metrics, 
            // status is partial)
            $status = 'success';
            foreach($row['metrics'] as $n => $metric) {
              if (!is_numeric($metric)) {
                $status = 'partial';
                unset($row['metrics'][$n]);
              }
            }
            
            // calculate metric statistical values
            $row['metric'] = get_mean($row['metrics']);
            $row['metric_10'] = get_percentile($row['metrics'], 10, $test == 'latency' || $test == 'dns' ? TRUE : FALSE);
            $row['metric_90'] = get_percentile($row['metrics'], 90, $test == 'latency' || $test == 'dns' ? TRUE : FALSE);
            $row['metric_median'] = get_mean($row['metrics']);
            $row['metric_stdev'] = get_std_dev($row['metrics']);
            $row['samples'] = count($row['metrics']);
            $row['status'] = $status;
            unset($row['metrics']);
            $this->results[] = $row;
          }
          else {
            print_msg(sprintf('%s test for endpoint %s failed', $test, $endpoint), $this->verbose, __FILE__, __LINE__, TRUE);
            $testsFailed++;
            if (!isset($this->options['suppress_failed']) || !$this->options['suppress_failed']) {
              if (isset($this->options['traceroute'])) {
                $hostname = get_hostname($endpoint);
                $file = sprintf('%s/traceroute-%s-%s.log', $this->options['output'], $hostname, $test);
                if (!file_exists($file)) {
                  print_msg(sprintf('Initiating traceroute to host %s - results to be written to %s', $hostname, $file), $this->verbose, __FILE__, __LINE__);
                  exec(sprintf('traceroute %s > %s 2>/dev/null', $hostname, $file));
                }
              }
              $row['status'] = 'failed';
              $this->results[] = $row;
            }   
          }
        }
        
        // stop testing due to various constraints
        if (isset($this->options['max_runtime']) && ($testStarted + $this->options['max_runtime']) <= time()) break;
        else if (isset($this->options['max_tests']) && $testsCompleted >= $this->options['max_tests']) break;
        else if (isset($this->options['abort_threshold']) && $testsFailed >= $this->options['abort_threshold']) break;
      }
    }
    if ($success) $this->endTest();
    
    return $success;
  }
  
  /**
   * performs a DNS test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoint
   * @return array
   */
  private function testDns($endpoint) {
    // params: dns_one_server, dns_recursive, dns_samples, dns_tcp, dns_timeout
    // results: dns_custom, dns_recursive, dns_servers
    $metrics = NULL;
    if ($endpoint = get_hostname($endpoint)) {
      // TODO
    }
    return $metrics;
  }
  
  /**
   * performs a download test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoint
   * @return array
   */
  private function testDownlink($endpoint) {
    // params: throughput_https, throughput_same_continent, throughput_same_country, 
    // throughput_same_geo_region, throughput_same_provider, throughput_same_region, 
    // throughput_same_service, throughput_same_state, throughput_samples, throughput_size,
    // throughput_threads, throughput_timeout, throughput_uri
    // results: throughput_https, throughput_size, throughput_threads
    $metrics = NULL;
    // TODO
    return $metrics;
  }
  
  /**
   * performs a latency test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoint
   * @return array
   */
  private function testLatency($endpoint) {
    $metrics = NULL;
    if ($endpoint = get_hostname($endpoint)) {
      $samples = $this->options['latency_samples'];
      $timeout = $this->options['latency_timeout'];
      $cmd = sprintf('ping -c %d -W %d %s 2>/dev/null; echo $?', $samples, $timeout, $endpoint);
      print_msg(sprintf('Testing latency using ping command: %s', $cmd), $this->verbose, __FILE__, __LINE__);
			if ($buffer = shell_exec($cmd)) {
				$pieces = explode("\n", trim($buffer));
				$ecode = $pieces[count($pieces) - 1];
				// ping successful
				if ($ecode == 0 && preg_match_all('/time\s*=\s*([0-9\.]+)\s+/msU', $buffer, $m)) {
				  $metrics = array();
					foreach($m[1] as $metric) $metrics[] = $metric*1;
          print_msg(sprintf('ping exited successfully with %d metrics: %s', count($metrics), implode(', ', $metrics)), $this->verbose, __FILE__, __LINE__);
			  }
				// ping exited normally, but no metrics produced
				else if ($ecode == 0) print_msg(sprintf('ping exited successfully, but did not produce valid output: %s', $buffer), $this->verbose, __FILE__, __LINE__, TRUE);
				// ping exited with error
			  else print_msg(sprintf('ping failed with exit code %d - %s', $ecode, $ecode == 1 ? 'host is down or does not accept ICMP' : 'unknown error'), $this->verbose, __FILE__, __LINE__, TRUE);
			}
			// command did not produce a value
			else print_msg(sprintf('ping failed because no output was produced'), $this->verbose, __FILE__, __LINE__, TRUE);
    }
    return $metrics;
  }
  
  /**
   * performs a upload test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoint
   * @return array
   */
  private function testUplink($endpoint) {
    // params: throughput_https, throughput_same_continent, throughput_same_country, 
    // throughput_same_geo_region, throughput_same_provider, throughput_same_region, 
    // throughput_same_service, throughput_same_state, throughput_samples, throughput_size,
    // throughput_threads, throughput_timeout, throughput_uri
    // results: throughput_https, throughput_size, throughput_threads
    $metrics = NULL;
    // TODO
    return $metrics;
  }
  
  /**
   * validate run options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @return array
   */
  public function validateRunOptions() {
        
    $validate = array(
      'abort_threshold' => array('min' => 1),
      'dns_samples' => array('max' => 100, 'min' => 1, 'required' => TRUE),
      'dns_timeout' => array('max' => 60, 'min' => 1, 'required' => TRUE),
      'geo_regions' => array('option' => array_keys($this->getGeoRegions())),
      'latency_samples' => array('max' => 100, 'min' => 1, 'required' => TRUE),
      'latency_timeout' => array('max' => 30, 'min' => 1, 'required' => TRUE),
      'max_runtime' => array('min' => 30),
      'max_tests' => array('min' => 1),
      'output' => array('write' => TRUE),
      'params_url' => array('url' => TRUE),
      'params_url_service_type' => array('option' => get_service_types()),
      'spacing' => array('min' => 0),
      'test' => array('required' => TRUE),
      'test_endpoint' => array('required' => TRUE),
      'test_service_type' => array('option' => get_service_types()),
      'throughput_same_continent' => array('max' => 1024, 'min' => 1),
      'throughput_same_country' => array('max' => 1024, 'min' => 1),
      'throughput_same_geo_region' => array('max' => 1024, 'min' => 1),
      'throughput_same_provider' => array('max' => 1024, 'min' => 1),
      'throughput_same_region' => array('max' => 1024, 'min' => 1),
      'throughput_same_service' => array('max' => 1024, 'min' => 1),
      'throughput_same_state' => array('max' => 1024, 'min' => 1),
      'throughput_samples' => array('max' => 100, 'min' => 1, 'required' => TRUE),
      'throughput_size' => array('max' => 1024, 'min' => 1, 'required' => TRUE),
      'throughput_threads' => array('max' => 10, 'min' => 1, 'required' => TRUE),
      'throughput_timeout' => array('max' => 600, 'min' => 1, 'required' => TRUE)
    );
    $validated = validate_options($this->getRunOptions(), $validate);
    
    // validate tests
    if (!isset($validated['test'])) {
      foreach($this->options['test'] as $tests) {
        foreach($tests as $test) {
          if (!in_array($test, array('latency', 'downlink', 'uplink', 'throughput', 'dns'))) {
            $validated['test'] = sprintf('--test %s is not valid [must be one of: latency, downlink, uplink, dns]');
            break;
          }
        }
        if (isset($validated['test'])) break;
      }
    }
    
    // validate test_endpoint association parameters
    if (!isset($validated['test_endpoint']) && count($this->options['test_endpoint']) && !isset($this->options['service_lookup']) && !isset($this->options['geoiplookup'])) {
      foreach(array('test', 'test_instance_id', 'test_location', 'test_private_network_type', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type') as $param) {
        if (!isset($validated[$param]) && isset($this->options[$param]) && count($this->options[$param]) != 1 && count($this->options[$param]) != count($this->options['test_endpoint'])) {
          $validated[$param] = sprintf('The --%s parameter can be specified once [same for all test_endpoint] or %d times [different for each test_endpoint]', $param, count($this->options[$param]));
        }
      }
    }
    
    // validate countries
    $countries =& $this->getCountries();
    if (isset($this->options['meta_location_country']) && !isset($countries[$this->options['meta_location_country']])) $validated['meta_location'] = sprintf('%s is not a valid ISO 3166 country code', $this->options['meta_location_country']);
    if (isset($this->options['test_location_country'])) {
      foreach($this->options['test_location_country'] as $country) {
        if (trim($country) && !isset($countries[$country])) {
          $validated['test_location'] = sprintf('%s is not a valid ISO 3166 country code', $country);
          break;
        }
      }
    }
    
    return $validated;
  }
  
  /**
   * validates test dependencies including:
   *   curl       Used for throughput testing
   *   dig        Used for DNS testing
   *   ping       Used for latency testing
   *   traceroute Used to traceroute failed test endpoints
   * returns an array containing the missing dependencies (array is empty if 
   * all dependencies are valid)
   * @return array
   */
  public function validateDependencies() {
    $dependencies = array();
    if (isset($this->options['geoiplookup'])) $dependencies['geoiplookup'] = 'GeoIP';
    if (isset($this->options['traceroute'])) $dependencies['traceroute'] = 'traceroute';
    foreach($this->options['test'] as $tests) {
      if (in_array('latency', $tests)) $dependencies['ping'] = 'ping';
      if (in_array('downlink', $tests) || in_array('uplink', $tests) || in_array('throughput', $tests)) $dependencies['curl'] = 'curl';
      if (in_array('dns', $tests)) $dependencies['dig'] = 'dig';
    }
    return validate_dependencies($dependencies);
  }
  
  /**
   * returns TRUE if the all --same* constraints match for $endpoint, FALSE 
   * otherwise
   * @param int $i the endpoint parameter index
   * @return boolean
   */
  private function validateSameConstraints($i) {
    $valid = TRUE;
    $endpoint = $this->options['test_endpoint'][$i][0];
    $location = isset($this->options['test_location'][$i]) ? $this->options['test_location'][$i] : (isset($this->options['test_location'][0]) ? $this->options['test_location'][$i] : NULL);
    $country = isset($this->options['test_location_country'][$i]) ? $this->options['test_location_country'][$i] : (isset($this->options['test_location_country'][0]) ? $this->options['test_location_country'][$i] : NULL);
    $state = isset($this->options['test_location_state'][$i]) ? $this->options['test_location_state'][$i] : (isset($this->options['test_location_state'][0]) ? $this->options['test_location_state'][$i] : NULL);
    $provider = isset($this->options['test_provider'][$i]) ? $this->options['test_provider'][$i] : (isset($this->options['test_provider'][0]) ? $this->options['test_provider'][$i] : NULL);
    $providerId = isset($this->options['test_provider_id'][$i]) ? $this->options['test_provider_id'][$i] : (isset($this->options['test_provider_id'][0]) ? $this->options['test_provider_id'][$i] : NULL);
    $service = isset($this->options['test_service'][$i]) ? $this->options['test_service'][$i] : (isset($this->options['test_service'][0]) ? $this->options['test_service'][$i] : NULL);
    $serviceId = isset($this->options['test_service_id'][$i]) ? $this->options['test_service_id'][$i] : (isset($this->options['test_service_id'][0]) ? $this->options['test_service_id'][$i] : NULL);
    $region = isset($this->options['test_region'][$i]) ? $this->options['test_region'][$i] : (isset($this->options['test_region'][0]) ? $this->options['test_region'][$i] : NULL);
    
    $nodeProvider = isset($this->options['meta_provider']) ? $this->options['meta_provider'] : NULL;
    $nodeProviderId = isset($this->options['meta_provider_id']) ? $this->options['meta_provider_id'] : NULL;
    $nodeService = isset($this->options['meta_service']) ? $this->options['meta_service'] : NULL;
    $nodeServiceId = isset($this->options['meta_service_id']) ? $this->options['meta_service_id'] : NULL;
    
    // same_continent_only
    if ($country && isset($this->options['same_continent_only']) && isset($this->options['meta_location_country']) && $this->getContinent($this->options['meta_location_country']) != getContinent($country)) {
      print_msg(sprintf('Skipping test endpoint %s because its country %s is not in the same continent as the test node country %s', $endpoint, $country, $this->options['meta_location_country']), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_country_only
    else if ($country && isset($this->options['meta_location_country']) && (isset($this->options['same_country_only']) || isset($this->options['same_state_only'])) && $country != $this->options['meta_location_country']) {
      print_msg(sprintf('Skipping test endpoint %s because its country %s is not the same as the test node country %s', $endpoint, $country, $this->options['meta_location_country']), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_state_only
    else if ($state && isset($this->options['meta_location_state']) && isset($this->options['same_state_only']) && $state != $this->options['meta_location_state']) {
      print_msg(sprintf('Skipping test endpoint %s because its state %s is not the same as the test node state %s', $endpoint, $state, $this->options['meta_location_state']), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_geo_region
    else if ($country && isset($this->options['meta_location_country']) && isset($this->options['same_geo_region']) && $this->getGeoRegion($country, $state) != $this->getGeoRegion($this->options['meta_location_country'], isset($this->options['meta_location_state']) ? $this->options['meta_location_state'] : NULL)) {
      print_msg(sprintf('Skipping test endpoint %s because its location %s is not in the same geo region as the test node location %s', $endpoint, $location, $this->options['meta_location']), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_provider_only
    else if (($provider || $providerId) && ($nodeProvider || $nodeProviderId) && isset($this->options['same_provider_only']) && $provider != $nodeProvider && $providerId != $nodeProviderId) {
      print_msg(sprintf('Skipping test endpoint %s because its provider %s [%s] is not in the same the test node provider %s [%s]', $endpoint, $provider, $providerId, $nodeProvider, $nodeProviderId), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_service_only
    else if (($service || $serviceId) && ($nodeService || $nodeServiceId) && (isset($this->options['same_service_only']) || isset($this->options['same_region_only'])) && $service != $nodeService && $serviceId != $nodeServiceId) {
      print_msg(sprintf('Skipping test endpoint %s because its service %s [%s] is not in the same the test node service %s [%s]', $endpoint, $service, $serviceId, $nodeService, $nodeServiceId), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // same_region_only
    else if ($region && isset($this->options['meta_region']) && isset($this->options['same_region_only']) && $region != $this->options['meta_region']) {
      print_msg(sprintf('Skipping test endpoint %s because its service region %s is not in the same the test node service region %s', $endpoint, $region, $this->options['meta_region']), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    
    return $valid;
  }
  
}
?>
