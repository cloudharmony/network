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
require_once(dirname(__FILE__) . '/benchmark/util.php');
ini_set('memory_limit', '16m');
date_default_timezone_set('UTC');

class NetworkTest {
  
  /**
   * Regex for the conditional_spacing runtime argument
   */
  const CONDITIONAL_SPACING_REGEX = '/^([><])([0-9]+)=([0-9]+)$/';
  
  /**
   * file name (and prefix for output files) to use when generating a custom
   * command file
   */
  const NETWORK_TEST_CUSTOM_CMDS_FILE_NAME = 'cust-cmds';
  
  /**
   * name of the file where serializes options should be written to for given 
   * test iteration
   */
  const NETWORK_TEST_OPTIONS_FILE_NAME = '.options';
  
  /**
   * the max size in bytes for small file tests (see --throughput_small_file)
   * 128KB
   */
  const SMALL_FILE_LIMIT = 131072;
  
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
   * stores a reference to config/downlink-files.ini
   */
  private $dowlinkFiles;
  
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
   * used to track which traceroutes have been performed
   */
  private $traceroutes = array();
  
  
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
   * returns a hash containing 2 elements:
   *   name: the name of a test downlink file that is closest in $size
   *   size: the size of this file in bytes
   * @param int $size the desired size (bytes)
   * @param string $serviceType optional service type => CDNs will only get 
   * image or javascript test files
   * @return string
   */
  private function getDownlinkFile($size, $serviceType=NULL) {
    if (!isset($this->dowlinkFiles)) {
      $this->dowlinkFiles = string_to_hash(file_get_contents(dirname(__FILE__) . '/config/downlink-files.ini'));
    }
    $dfile = NULL;
    $ddiff = NULL;
    $dsize = NULL;
    foreach($this->dowlinkFiles as $f => $s) {
      $diff = abs($size - $s);
      if ($ddiff === NULL || $diff < $ddiff) {
        if ($serviceType == 'cdn') {
          $pieces = explode('.', $f);
          $ext = $pieces[count($pieces) - 1];
          if (!in_array($ext, array('png', 'jpg', 'gif', 'js'))) {
            print_msg(sprintf('Skiping test file %s of type %s because it is not supported on CDNs', $f, $ext), $this->verbose, __FILE__, __LINE__);
            continue;
          }
        }
        $ddiff = $diff;
        $dfile = $f;
        $dsize = $s;
      }
    }
    print_msg(sprintf('Selected downlink file %s [%s MB] for size %s MB', $dfile, round(($dsize/1024)/1024, 2), round(($size/1024)/1024, 2)), $this->verbose, __FILE__, __LINE__);
    return $dfile ? array('name' => $dfile, 
                          'size' => isset($this->options['test_files_dir']) ? filesize(sprintf('%s/%s', $this->options['test_files_dir'], $dfile)) : $dsize) : NULL;
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
   * evaluates a string containing an expression. The substring [cpus] will be 
   * replaced with the number of CPU cores
   * @param string $expr the expression to evaluate
   * @return float
   */
  private function evaluateExpression($expr) {
    $sysInfo = get_sys_info();
    $expr = str_replace('[cpus]', isset($sysInfo['cpu_cores']) ? $sysInfo['cpu_cores'] : 2, $expr);
    eval(sprintf('$value=round(%s);', $expr));
    $value *= 1;
    return $value;
  }
  
  /**
   * returns test results - an array of hashes each containing the results from
   * 1 test
   * @return array
   */
  public function getResults() {
    $results = NULL;
    require_once(sprintf('%s/benchmark/save/BenchmarkDb.php', dirname(__FILE__)));
    if ($db =& BenchmarkDb::getDb()) {
      $this->getRunOptions();
      if ($results = isset($this->options['results']) ? $this->options['results'] : NULL) {
        $schema = $db->getSchema('network');
        foreach(array_keys($this->options) as $key) {
          if (isset($schema[$key]) && preg_match('/^meta/', $key)) {
            foreach(array_keys($results) as $i) {
              if (!isset($results[$i][$key])) {
                $results[$i][$key] = trim(is_array($this->options[$key]) ? implode(' ', $this->options[$key]) : $this->options[$key]);
                if (!$results[$i][$key]) unset($results[$i][$key]);
              }
              // meta attributes for inverse records
              else if ($results[$i][$key] === FALSE) unset($results[$i][$key]);
            }
          }
        }
      } 
    }
    return $results;
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
          'collectd_rrd_dir' => '/var/lib/collectd/rrd',
          'dns_retry' => 2,
          'dns_samples' => 10,
          'dns_timeout' => 5,
          'geo_regions' => 'us_west us_central us_east canada eu_west eu_central eu_east oceania asia america_south africa',
          'latency_interval' => 0.2,
          'latency_samples' => 100,
          'latency_timeout' => 3,
          'meta_cpu' => $sysInfo['cpu'],
          'meta_memory' => $sysInfo['memory_gb'] > 0 ? $sysInfo['memory_gb'] . ' GB' : $sysInfo['memory_mb'] . ' MB',
          'meta_os' => $sysInfo['os_info'],
          'output' => trim(shell_exec('pwd')),
          'spacing' => 200,
          'test' => 'latency',
          'throughput_size' => 5,
          'throughput_threads' => 2,
          'throughput_uri' => '/web-probe'
        );
        $opts = array(
          'abort_threshold:',
          'collectd_rrd',
          'collectd_rrd_dir:',
          'conditional_spacing:',
          'discard_fastest:',
          'discard_slowest:',
          'dns_one_server',
          'dns_retry:',
          'dns_samples:',
          'dns_tcp',
          'dns_timeout:',
          'geoiplookup',
          'geo_regions:',
          'latency_interval:',
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
          'min_runtime:',
          'min_runtime_in_save',
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
          'sleep_before_start:',
          'spacing:',
          'suppress_failed',
          'test:',
          'test_cmd_downlink:',
          'test_cmd_uplink:',
          'test_cmd_uplink_del:',
          'test_cmd_url_strip:',
          'test_endpoint:',
          'test_files_dir:',
          'test_instance_id:',
          'test_location:',
          'test_private_network_type:',
          'test_provider:',
          'test_provider_id:',
          'test_region:',
          'test_service:',
          'test_service_id:',
          'test_service_type:',
          'throughput_header:',
          'throughput_https',
          'throughput_inverse',
          'throughput_keepalive',
          'throughput_same_continent:',
          'throughput_same_country:',
          'throughput_same_geo_region:',
          'throughput_same_provider:',
          'throughput_same_region:',
          'throughput_same_service:',
          'throughput_same_state:',
          'throughput_samples:',
          'throughput_size:',
          'throughput_slowest_thread',
          'throughput_small_file',
          'throughput_threads:',
          'throughput_time',
          'throughput_timeout:',
          'throughput_tolerance:',
          'throughput_uri:',
          'throughput_use_mean',
          'throughput_webpage:',
          'throughput_webpage_check',
          'traceroute',
          'v' => 'verbose'
        );
        $this->options = parse_args($opts, array('latency_skip', 'params_url_service_type', 'params_url_header', 'test', 'test_endpoint', 'test_instance_id', 'test_location', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type', 'throughput_header', 'throughput_webpage'));
        $this->options['run_start'] = time();
        $this->verbose = isset($this->options['verbose']);
        
        // convert [cpus] substring if specified in --throughput_threads
        $this->options['throughput_threads'] = $this->evaluateExpression($this->options['throughput_threads']);
        
        // set default same size constraints if --throughput_size is not set
        if (!isset($this->options['throughput_size'])) {
          foreach(array('throughput_same_continent' => 10, 'throughput_same_country' => 20, 
                        'throughput_same_geo_region' => 30, 'throughput_same_provider' => 10,
                        'throughput_same_region' => 100, 'throughput_same_state' => 50) as $k => $v) $defaults[$k] = $v;
        }
        
        foreach($defaults as $key => $val) {
          if (!isset($this->options[$key])) {
            $this->options[$key] = $val;
            $this->defaultsSet[] = $key;
          }
        }
        if (isset($this->options['throughput_size']) && $this->options['throughput_size'] == 0) $this->options['throughput_time'] = TRUE;
        if (!isset($this->options['throughput_samples'])) $this->options['throughput_samples'] = isset($this->options['throughput_small_file']) || isset($this->options['throughput_time']) ? 10 : 5;
        if (!isset($this->options['throughput_timeout'])) $this->options['throughput_timeout'] = isset($this->options['throughput_small_file']) || isset($this->options['throughput_time']) ? 5 : 180;
        
        // throughput tolerance
        if (!isset($this->options['throughput_tolerance']) || !is_numeric($this->options['throughput_tolerance']) || $this->options['throughput_tolerance'] < 0 || $this->options['throughput_tolerance'] > 1) $this->options['throughput_tolerance'] = 0.6;
        
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
                print_msg(sprintf('Added runtime parameter %s=%s from --params_url', $key, is_array($val) ? implode(',', $val) : $val), $this->verbose, __FILE__, __LINE__);
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
                $e2 = trim($e2);
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
            $this->options['meta_location'] = sprintf('%s%s', isset($geoip['state']) ? $geoip['state'] . ', ' : '', $geoip['country']);
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
        
        // expand throughput_webpage
        if (isset($this->options['throughput_webpage'])) {
          foreach($this->options['throughput_webpage'] as $i => $resource) {
            $resources = array();
            foreach(explode(',', $resource) as $e1) {
              foreach(explode(' ', $e1) as $e2) {
                $e2 = trim($e2);
                $resources[] = $e2;
              }
            }
            if ($resources) $this->options['throughput_webpage'][$i] = $resources;
            else unset($this->options['throughput_webpage'][$i]);
          }
        }
        
        // set meta_geo_region
        if (isset($this->options['meta_location'])) {
          $pieces = explode(',', $this->options['meta_location']);
          $this->options['meta_location_country'] = strtoupper(trim($pieces[count($pieces) - 1]));
          $this->options['meta_location_state'] = count($pieces) > 1 ? trim($pieces[0]) : NULL;
          if ($geoRegion = $this->getGeoRegion($this->options['meta_location_country'], isset($this->options['meta_location_state']) ? $this->options['meta_location_state'] : NULL)) $this->options['meta_geo_region'] = $geoRegion;
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
    $file = sprintf('%s/%s', $dir, self::NETWORK_TEST_OPTIONS_FILE_NAME);
    return file_exists($file) ? unserialize(file_get_contents($file)) : NULL;
  }
  
  /**
   * initiates stream scaling testing. returns TRUE on success, FALSE otherwise
   * @return boolean
   */
  public function test() {
    $rrdStarted = isset($this->options['collectd_rrd']) ? ch_collectd_rrd_start($this->options['collectd_rrd_dir'], isset($this->options['verbose'])) : FALSE;
    $success = FALSE;
    
    $testsCompleted = 0;
    $testsFailed = 0;
    $testStarted = time();

    // apply sleep period before starting testing
    if (isset($this->options['sleep_before_start'])) {
      $pieces = explode('-', $this->options['sleep_before_start']);
      $min = trim($pieces[0]);
      $max = isset($pieces[1]) ? trim($pieces[1]) : NULL;
      if (is_numeric($min) && (!$max || (is_numeric($max) && $max > $min))) {
        $sleep = $min && $max ? rand($min, $max) : $min;
        print_msg(sprintf('Sleeping fore %d seconds before starting testing due to --sleep_before_start %s', $sleep, $this->options['sleep_before_start']), $this->verbose, __FILE__, __LINE__);
        sleep($sleep);
      }
      else print_msg(sprintf('--sleep_before_start %s is not valid', $this->options['sleep_before_start']), $this->verbose, __FILE__, __LINE__, TRUE);
    }
    
    print_msg(sprintf('Initiating testing for %d test endpoints', count($this->options['test_endpoint'])), $this->verbose, __FILE__, __LINE__);
    
    // randomize testing order
    $keys = array_keys($this->options['test_endpoint']);
    if (isset($this->options['randomize']) && $this->options['randomize']) {
      print_msg(sprintf('Randomizing test order'), $this->verbose, __FILE__, __LINE__);
      shuffle($keys);
    }
    
    foreach($keys as $testNum => $i) {
      $endpoints = $this->options['test_endpoint'][$i];
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
      // same constraints did not match
      else if (!$this->validateSameConstraints($i)) {
        print_msg(sprintf('Skipping testing for endpoint %s because one or more --same* constraints did not match', $endpoints[0]), $this->verbose, __FILE__, __LINE__);
        continue;
      }
      
      $tests = array_key_exists($i, $this->options['test']) ? $this->options['test'][$i] : $this->options['test'][0];
      $isThroughput = FALSE;
      // replace throughput with downlink + uplink
      if (in_array('throughput', $tests)) {
        $isThroughput = TRUE;
        if (!in_array('downlink', $tests)) $tests[] = 'downlink';
        if (!in_array('uplink', $tests)) $tests[] = 'uplink';
        unset($tests[array_search('throughput', $tests)]);
      }
      $serviceId = isset($this->options['test_service_id']) ? (array_key_exists($i, $this->options['test_service_id']) ? $this->options['test_service_id'][$i] : $this->options['test_service_id'][0]) : NULL;
      $providerId = isset($this->options['test_provider_id']) ? (array_key_exists($i, $this->options['test_provider_id']) ? $this->options['test_provider_id'][$i] : $this->options['test_provider_id'][0]) : NULL;
      $serviceType = isset($this->options['test_service_type']) ? (array_key_exists($i, $this->options['test_service_type']) ? $this->options['test_service_type'][$i] : $this->options['test_service_type'][0]) : NULL;
      if ($serviceId && !$serviceType && count($pieces = explode(':', $serviceId)) == 2 && in_array($pieces[1], array('servers', 'vps', 'compute', 'storage', 'cdn', 'dns'))) $serviceType = $pieces[1] == 'servers' || $pieces[1] == 'vps' ? 'compute' : $pieces[1];
      if ($serviceType) {
        $supportedTests = array();
        switch($serviceType) {
          case 'compute':
          case 'paas':
            $supportedTests[] = 'uplink';
            $supportedTests[] = 'downlink';
            $supportedTests[] = 'latency';
            break;
          case 'storage':
            if (isset($this->options['test_cmd_uplink'])) $supportedTests[] = 'uplink';
          case 'cdn':
            $supportedTests[] = 'downlink';
            $supportedTests[] = 'latency';
            break;
          case 'dns':
            $supportedTests[] = 'dns';
            break;
        }
        // no tests to run
        $btests = array();
        foreach($tests as $test) $btests[] = $test;
        $tests = array_intersect($tests, $supportedTests);
        if (!count($tests)) {
          print_msg(sprintf('Skipping testing for endpoint %s because supported tests [%s] are not included in --test [%s]. providerId: %s; serviceId: %s; serviceType: %s', $endpoints[0], implode(', ', $supportedTests), implode(', ', $btests), $providerId, $serviceId, $serviceType), $this->verbose, __FILE__, __LINE__);
          continue;
        } 
      }
      
      if (isset($this->options['randomize']) && $this->options['randomize']) shuffle($tests);
      print_msg(sprintf('Starting [%s] testing of endpoint %s [%d of %d]. providerId: %s; serviceId: %s; serviceType: %s', implode(', ', $tests), $endpoints[0], $testNum + 1, count($keys), $providerId, $serviceId, $serviceType), $this->verbose, __FILE__, __LINE__);
      
      foreach($tests as $test) {
        // check for endpoints/service/providers to skip latency testing for
        if ($test == 'latency' && isset($this->options['latency_skip'])) {
          $hostname = get_hostname($endpoints[0]);
          if (in_array($hostname, $this->options['latency_skip']) || in_array($serviceId, $this->options['latency_skip']) || in_array($providerId, $this->options['latency_skip'])) {
            print_msg(sprintf('Skipping latency test for endpoint %s; service %s; provider %s; due to --latency_skip constraint', $endpoints[0], $serviceId, $providerId), $this->verbose, __FILE__, __LINE__);
            continue;
          }
        }
        
        // spacing
        if ($testNum > 0 && isset($this->options['spacing'])) {
          usleep($this->options['spacing']*1000);
          print_msg(sprintf('Applied test spacing of %d ms', $this->options['spacing']), $this->verbose, __FILE__, __LINE__);
        }
        
        $results = array();
        $privateEndpoint = FALSE;
        print_msg(sprintf('Starting %s test against endpoint %s', $test, $endpoints[0]), $this->verbose, __FILE__, __LINE__);
        
        // iterate through both private and public endpoint addresses
        $testStart = NULL;
        $testStartTimestamp = NULL;
        $testStop = NULL;
        $testStopTimestamp = NULL;
        foreach(array_reverse($endpoints) as $n => $endpoint) {
          if ($test != 'dns' && count($endpoints) > 1 && $n == 0 && !$this->usePrivateNetwork($i)) {
            print_msg(sprintf('Skipping private network endpoint %s because services are not related', $endpoint), $this->verbose, __FILE__, __LINE__);
            continue;
          }
          $testStart = date('Y-m-d H:i:s');
          $testStartTimestamp = microtime(TRUE);
          switch($test) {
            case 'latency':
              $results['latency'] = $this->testLatency($endpoint);
              break;
            case 'downlink':
              $results['downlink'] = $this->testThroughput($endpoint, $i);
              break;
            case 'uplink':
              $results['uplink'] = $this->testThroughput($endpoint, $i, TRUE);
              break;
            case 'dns':
              $results['dns'] = $this->testDns($endpoints);
              break;
          }
          $testStop = date('Y-m-d H:i:s');
          $testStopTimestamp = microtime(TRUE);
          // check if test completed
          $done = $test == 'dns' ? TRUE : FALSE;
          foreach(array_keys($results) as $key) {
            if (is_array($results[$key])) $done = TRUE;
          }
          if ($done && $test != 'dns') {
            if (count($endpoints) > 1 && $n == 0) $privateEndpoint = TRUE;
            break;
          }
        }
        
        foreach($results as $test => $metrics) {
          $success = TRUE;
          $row = array('test' => $test, 'test_endpoint' => $endpoint, 'test_ip' => gethostbyname(get_hostname(str_replace('*', rand(), $endpoint))), 'test_started' => $testStart, 'test_stopped' => $testStop);
          if ($country = isset($this->options['test_location_country'][$i]) ? $this->options['test_location_country'][$i] : NULL) {
            $state = isset($this->options['test_location_state'][$i]) ? $this->options['test_location_state'][$i] : NULL;
            if ($geoRegion = $this->getGeoRegion($country, $state)) $row['test_geo_region'] = $geoRegion;
          }
          
          // add additional test_* result attributes
          foreach(array('test_instance_id', 'test_location', 'test_location_country', 'test_location_state', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type') as $param) {
            if (isset($this->options[$param]) && (array_key_exists($i, $this->options[$param]) || isset($this->options[$param][0]))) $row[$param] = array_key_exists($i, $this->options[$param]) ? $this->options[$param][$i] : $this->options[$param][0];
          }
          // private endpoint?
          if ($privateEndpoint) {
            $row['test_private_endpoint'] = TRUE;
            if (isset($this->options['test_private_network_type'])) $row['test_private_network_type'] = array_key_exists($i, $this->options['test_private_network_type']) ? $this->options['test_private_network_type'][$i] : $this->options['test_private_network_type'][0];
          }
          $row['timeout'] = $this->options[sprintf('%s_timeout', $test == 'dns' || $test == 'latency' ? $test : 'throughput')];
          
          if (isset($metrics) && is_array($metrics)) {
            $testsCompleted++;
            if (isset($metrics['metrics'])) $row = array_merge($row, $metrics);
            else $row['metrics'] = $metrics;
            // determine status from tests_failed and tests_success
            $status = 'fail';
            foreach($row['metrics'] as $n => $metric) {
              if (is_numeric($metric)) $status = 'success';
            }
            if (isset($row['tests_failed']) && $row['tests_failed'] > 0) $status = isset($row['tests_success']) && !$row['tests_success'] ? 'fail' : 'partial';
            else if (isset($row['tests_success']) && $row['tests_success'] > 0) $status = 'success';
            
            // calculate metric statistical values
            $row['samples'] = count($row['metrics']);
            $lowerBetter = $test == 'latency' || $test == 'dns' || isset($this->options['throughput_time']);
            $orderedMetrics = implode(',', $row['metrics']);
            $lowerBetter ? rsort($row['metrics']) : sort($row['metrics']);
            $discardSlowest = isset($this->options['discard_slowest']) ? $this->options['discard_slowest'] : 0;
            $discardFastest = isset($this->options['discard_fastest']) ? $this->options['discard_fastest'] : 0;
            if ($discardSlowest || $discardFastest) {
              print_msg(sprintf('Trimming %s of slowest and %s of fastest metrics [%s]; Lower better: %s', $discardSlowest . '%', $discardFastest . '%', implode(', ', $row['metrics']), $lowerBetter), $this->verbose, __FILE__, __LINE__);
              $row['metrics'] = trim_points($row['metrics'], $discardSlowest, $discardFastest);
              print_msg(sprintf('Trimmed metrics [%s]', implode(', ', $row['metrics'])), $this->verbose, __FILE__, __LINE__);
            }
            $row['metric'] = get_median($row['metrics']);
            $row['metric_10'] = get_percentile($row['metrics'], 10, $lowerBetter);
            $row['metric_25'] = get_percentile($row['metrics'], 25, $lowerBetter);
            $row['metric_75'] = get_percentile($row['metrics'], 75, $lowerBetter);
            $row['metric_90'] = get_percentile($row['metrics'], 90, $lowerBetter);
            $row['metric_fastest'] = $row['metrics'][count($row['metrics']) - 1];
            $row['metric_mean'] = get_mean($row['metrics']);
            $row['metric_slowest'] = $row['metrics'][0];
            $row['metric_max'] = $lowerBetter ? $row['metric_slowest'] : $row['metric_fastest'];
            $row['metric_min'] = $lowerBetter ? $row['metric_fastest'] : $row['metric_slowest'];
            $row['metric_stdev'] = get_std_dev($row['metrics']);
            $row['metric_rstdev'] = round(($row['metric_stdev']/$row['metric'])*100, 4);
            $row['metric_sum'] = array_sum($row['metrics']);
            $row['metric_sum_squares'] = get_sum_squares($row['metrics']);
            // calculate throughput based on time - not curl reported speed
            if (($test == 'downlink' || $test == 'uplink') && isset($metrics['throughput_transfer']) && $metrics['throughput_transfer'] > 0) {
              $secs = $testStopTimestamp - $testStartTimestamp;
              print_msg(sprintf('Calculating metric_timed using duration of %s secs and transfer of %s MB', $secs, $metrics['throughput_transfer']), $this->verbose, __FILE__, __LINE__);
              if ($this->options['spacing'] && $row['samples'] > 1) {
                $sub = (($row['samples'] - 1) * $this->options['spacing'])/1000;
                print_msg(sprintf('Subtracting %s secs due to spacing - new duration is %s secs', $sub, $secs - $sub), $this->verbose, __FILE__, __LINE__);
                $secs -= $sub;
              }
              $row['metric_timed'] = round(($metrics['throughput_transfer']*8)/$secs, 4);
              $row['throughput_custom_cmd'] = ($test == 'downlink' && isset($this->options['test_cmd_downlink'])) || 
                                              ($test == 'uplink' && isset($this->options['test_cmd_uplink']));
            }
            $row['metric_unit'] = $lowerBetter ? 'ms' : 'Mb/s';
            $row['metric_unit_long'] = $lowerBetter ? 'milliseconds' : 'megabits per second';
            $row['metrics'] = $orderedMetrics;
            $row['status'] = $status;
            print_msg(sprintf('%s test for endpoint %s completed successfully', $test, $endpoint), $this->verbose, __FILE__, __LINE__);
            print_msg(sprintf('status: %s; samples: %d; median: %s; mean: %s; 10th: %s; 90th: %s; fastest: %s; slowest: %s; min: %s; max: %s; timed: %s; sum/squares: %s/%s; stdev: %s', $row['status'], $row['samples'], $row['metric'], $row['metric_mean'], $row['metric_10'], $row['metric_90'], $row['metric_fastest'], $row['metric_slowest'], $row['metric_min'], $row['metric_max'], $row['metric_timed'], $row['metric_sum'], $row['metric_sum_squares'], $row['metric_stdev']), $this->verbose, __FILE__, __LINE__);
            print_msg(sprintf('metrics: [%s]', $row['metrics']), $this->verbose, __FILE__, __LINE__);
            $this->results[] = $row;
            
            // add inverse throughput record
            if (isset($this->options['throughput_inverse']) && !$isThroughput && 
                isset($this->options['meta_compute_service_id']) && isset($row['test_service_id']) && 
                isset($row['test_service_type']) && $row['test_service_type'] == 'compute') {
              print_msg(sprintf('Generating %s inverse throughput record from [%s] [%s]', $row['test'], implode(', ', array_keys($row)), implode(', ', $row)), $this->verbose, __FILE__, __LINE__);
              $nrow = array();
              foreach($row as $k => $v) $nrow[$k] = $v;
              $nrow['test'] = $row['test'] == 'uplink' ? 'downlink' : 'uplink';
              // meta attributes that should not be set
              foreach(array('meta_cpu', 'meta_memory', 'meta_memory_gb', 'meta_memory_mb', 'meta_os_info', 'meta_resource_id') as $k) $nrow[$k] = FALSE;
              foreach(array('meta_compute_service' => 'test_service', 
                            'meta_compute_service_id' => 'test_service_id', 
                            'meta_geo_region' => 'test_geo_region', 
                            'meta_instance_id' => 'test_instance_id',
                            'meta_hostname' => 'test_endpoint', 
                            'meta_location' => 'test_location',
                            'meta_location_country' => 'test_location_country', 
                            'meta_location_state' => 'test_location_state',
                            'meta_provider' => 'test_provider',
                            'meta_provider_id' => 'test_provider_id',
                            'meta_region' => 'test_region') as $meta => $attr) {
                if (isset($nrow[$attr])) $nrow[$meta] = $meta == 'meta_hostname' ? get_hostname($nrow[$attr]) : $nrow[$attr];
                else $nrow[$meta] = FALSE;
                
                $v = isset($this->options[$meta]) ? $this->options[$meta] : ($meta == 'meta_hostname' ? trim(shell_exec('hostname')) : NULL);
                if ($v) $nrow[$attr] = $v;
                else if (isset($nrow[$attr])) unset($nrow[$attr]);
              }
              if (!isset($this->myip)) $this->myip = trim(shell_exec('curl -q http://app.cloudharmony.com/myip 2>/dev/null'));
              if ($this->myip) $nrow['test_ip'] = $this->myip;
              else if (isset($nrow['test_ip'])) unset($nrow['test_ip']);
              $this->results[] = $nrow;
              print_msg(sprintf('Added %s inverse throughput record [%s] [%s]', $nrow['test'], implode(', ', array_keys($nrow)), implode(', ', $nrow)), $this->verbose, __FILE__, __LINE__);
            }
          }
          else {
            print_msg(sprintf('%s test for endpoint %s failed', $test, $endpoint), $this->verbose, __FILE__, __LINE__, TRUE);
            $testsFailed++;
            if (!isset($this->options['suppress_failed']) || !$this->options['suppress_failed']) {
              if (isset($this->options['traceroute'])) {
                $hostname = get_hostname($endpoint);
                $file = sprintf('%s/traceroute.log', $this->options['output']);
                if (!isset($this->traceroutes[$hostname])) {
                  $this->traceroutes[$hostname] = TRUE;
                  print_msg(sprintf('Initiating traceroute to host %s - results to be written to %s', $hostname, $file), $this->verbose, __FILE__, __LINE__);
                  exec(sprintf('traceroute %s >> %s 2>/dev/null', $hostname, $file));
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
    if ($success) {
      if ($rrdStarted) ch_collectd_rrd_stop($this->options['collectd_rrd_dir'], $this->options['output'], isset($this->options['verbose']));
      $this->endTest();
      if (isset($this->options['min_runtime']) && !isset($this->options['min_runtime_in_save']) && ($testStarted + $this->options['min_runtime']) > time()) {
        $sleep = ($testStarted + $this->options['min_runtime']) - time();
        print_msg(sprintf('Testing complete and --min_runtime %d has not been acheived. Sleeping for %d seconds', $this->options['min_runtime'], $sleep), $this->verbose, __FILE__, __LINE__);
        sleep($sleep);
      }
    }
    
    return $success;
  }
  
  /**
   * Initiates a downlink test iteration using a custom CLI command. Simulates
   * handling of the same inputs and outputs as the default multi-process curl
   * method ch_curl_mt. Return value is a hash containing the following keys
   *   urls:     ordered array of URLs (same order as $requests)
   *   results:  ordered array of result values - each a hash with the 
   *             following keys:
   *             speed:              transfer rate (bytes/sec)
   *             time:               total time for the operation (secs)
   *             transfer:           total bytes transferred
   *             url:                actual command used
   *   status:   200 on success, 500 on failure (or CLI specific HTTP error 
   *             code if found in stderr)
   *   lowest_status: the lowest status code
   *   highest_status: the highest status code
   * @param array $requests array defining the http requests to invoke. Each 
   * element in this array is a hash with the following possible keys:
   *   method:  ignored
   *   headers: ignored
   *   url:     the URL to test (http:// or https:// prefixes will be removed)
   *   input:   ignored
   *   body:    ignored
   *   range:   ignored
   * @param int $timeout the max allowed time in seconds for each request (i.e. 
   * --max-time). Default is 60. If < 1, no timeout will be set
   */
  private function testCustomDownlink($requests, $timeout=60) {
    $response = NULL;
    $tempDir = $this->options['output'];
    if (!is_array($requests)) $requests = array($requests);
    $cfile = sprintf('%s/%s', $tempDir, self::NETWORK_TEST_CUSTOM_CMDS_FILE_NAME);
    if ($fp = fopen($cfile, 'w')) {
      fwrite($fp, "#!/bin/bash\n");
      $i=1;
      $urls = array();
      $commands = array();
      foreach($requests as $n => $request) {
        $url = trim(str_replace('http://', '', str_replace('https://', '', $request['url'])));
        if (!$url) continue;
        $urls[$n] = $url;
        $cmd = str_replace('[file]', $url, $this->options['test_cmd_downlink']);
        if (isset($this->options['test_cmd_url_strip'])) $cmd = str_replace($this->options['test_cmd_url_strip'], '', $cmd);
        $commands[$n] = $cmd;
        $ofile = sprintf('%s.out%d', $cfile, $i);
        fwrite($fp, sprintf("%s >%s && timeout %d %s 2>>%s | wc -c >>%s 2>/dev/null && %s >>%s &\n", 
                            'date +%s%N', $ofile, $timeout, $cmd, $ofile, $ofile, 'date +%s%N', $ofile));
        $i++;
      }
      fwrite($fp, "wait\n");
      fclose($fp);
      exec(sprintf('chmod 755 %s', $cfile));
      if ($commands) {
        print_msg(sprintf('Initiating custom downlink command (e.g. %s) %s using %d concurrent requests and script %s', $this->options['test_cmd_downlink'], 
                                                                                                                        $cmd,
                                                                                                                        count($commands), 
                                                                                                                        $cfile), $this->verbose, __FILE__, __LINE__);
        exec($cfile);
        print_msg('Custom downlink command execution complete', $this->verbose, __FILE__, __LINE__);
        $i=1;
        foreach($requests as $n => $request) {
          if (isset($commands[$n])) {
            if (file_exists($ofile = sprintf('%s.out%d', $cfile, $i))) {
              if (!$response) $response = array('urls' => array(), 'results' => array(), 'status' => array(), 'lowest_status' => NULL, 'highest_status' => NULL);
              $pieces = explode("\n", file_get_contents($ofile));
              $start = isset($pieces[0]) && is_numeric($pieces[0]) && $pieces[0] > 0 ? $pieces[0]*1 : NULL;
              $bytes = is_numeric($pieces[1]) ? $pieces[1] : NULL;
              $stop = is_numeric($pieces[2]) && $pieces[2] > $start ? $pieces[2]*1 : NULL;
              $error = is_string($pieces[1]) ? $pieces[1] : (is_string($pieces[2]) ? $pieces[2] : NULL);
              if ($start && $stop && $bytes) {
                $ms = ($stop - $start)/1000000;
                $secs = round($ms/1000, 8);
                $response['urls'][] = $request['url'];
                $r = array('speed' => round($bytes/$secs, 4), 'time' => $secs, 'transfer' => $bytes, 'url' => $commands[$n]);
                $response['results'][] = $r;
                $response['status'][] = 200;
                print_msg(sprintf('Successfully obtained downlink results for request %d: %s', $i, json_encode($r)), $this->verbose, __FILE__, __LINE__);
              }
              else {
                print_msg(sprintf('Unable to get required start/stop/bytes from output file: %s', implode(';', $pieces)), $this->verbose, __FILE__, __LINE__, TRUE);
                $response['urls'][] = $request['url'];
                $response['results'][] = NULL;
                $response['status'][] = preg_match('/([4-5][0-9]{2})/', $error, $m) ? $m[1]*1 : 500;
              }
            }
            else print_msg(sprintf('Unable to get output from outfile %s', $ofile), $this->verbose, __FILE__, __LINE__, TRUE);
          }
          $i++;
        }
        exec(sprintf('rm -f %s*', $cfile));
      }
      else {
        print_msg(sprintf('Unable to generate custom command script using %s', $this->options['test_cmd_downlink']), $this->verbose, __FILE__, __LINE__, TRUE);
      }
    }
    else print_msg(sprintf('Unable to open file %s for writing', $cfile), $this->verbose, __FILE__, __LINE__, TRUE);
    
    // lowest and highest status
    if ($response) {
      foreach($response['status'] as $status) {
        if (is_numeric($status)) {
          if (!isset($response['lowest_status']) || $status < $response['lowest_status']) $response['lowest_status'] = $status;
          if (!isset($response['highest_status']) || $status > $response['highest_status']) $response['highest_status'] = $status;
        }
      }
    }
    
    return $response;
  }
  
  /**
   * Initiates a uplink test iteration using a custom CLI command. Simulates
   * handling of the same inputs and outputs as the default multi-process curl
   * method ch_curl_mt. Return value is a hash containing the following keys
   *   urls:     ordered array of URLs (same order as $requests)
   *   results:  ordered array of result values - each a hash with the 
   *             following keys:
   *             speed:              transfer rate (bytes/sec)
   *             time:               total time for the operation
   *             transfer:           total bytes transferred
   *             url:                actual command used
   *   status:   200 on success, 500 on failure
   *   lowest_status: the lowest status code
   *   highest_status: the highest status code
   * @param array $requests array defining the http requests to invoke. Each 
   * element in this array is a hash with the following possible keys:
   *   method:  ignored
   *   headers: ignored
   *   url:     the URL to test (http:// or https:// prefixes will be removed)
   *   input:   ignored
   *   body:    optional string or file to use for the uplink file. 
   *            Alternatively, if this is a numeric value, a file will be 
   *            created containing random bytes corresponding with the numeric 
   *            value
   *   range:   ignored
   * @param int $timeout the max allowed time in seconds for each request (i.e. 
   * --max-time). Default is 60. If < 1, no timeout will be set
   */
  private function testCustomUplink($requests, $timeout=60) {    
    $response = NULL;
    $tempDir = $this->options['output'];
    if (!is_array($requests)) $requests = array($requests);
    $cfile = sprintf('%s/%s', $tempDir, self::NETWORK_TEST_CUSTOM_CMDS_FILE_NAME);
    if ($fp = fopen($cfile, 'w')) {
      fwrite($fp, "#!/bin/bash\n");
      $i=1;
      $urls = array();
      $commands = array();
      $tfiles = array();
      $bytes = array();
      foreach($requests as $n => $request) {
        $source = isset($request['body']) ? $request['body'] : NULL;
        if (!$source) {
          print_msg(sprintf('Uplink request for URL %s does not contain body', $request['url']), $this->verbose, __FILE__, __LINE__, TRUE);
          continue;
        }
        else if (substr($source, 0, 1) == '/' && is_dir(dirname($source)) && !is_readable(dirname($source))) {
          print_msg(sprintf('Directory %s for file %s for uplink request is not readable', dirname($source), basename($source)), $this->verbose, __FILE__, __LINE__, TRUE);
          continue;
        }
        else if (!is_file($source)) {
          $size = is_numeric($source) && $source > 0 ? $source : strlen($source);
          $source = sprintf('%s/uplink_input_%d', $tempDir, $size);
          if (!file_exists($source)) {
            exec($cmd = sprintf('dd if=/dev/urandom of=%s bs=%d count=%d 2>/dev/null', $source, $size > 1024 ? 1024 : $size, $size > 1024 ? round($size/1024) : 1));
            $expectedSize = $size > 1024 ? 1024*round($size/1024) : $size;
            if (file_exists($source) && filesize($source) != $expectedSize) unlink($source);
            if (file_exists($source)) register_shutdown_function('unlink', $source);
            else $source = NULL;
          }
          if (!$source) {
            print_msg(sprintf('Unable to generate random %d bytes file (expected %d) for uplink testing in directory %s using command %s', 
                              $size, $expectedSize, $tempDir, $cmd), $this->verbose, __FILE__, __LINE__, TRUE);
            continue;
          }
        }
        $url = trim(str_replace('/up.html', '', str_replace('http://', '', str_replace('https://', '', $request['url']))));
        if (!$url) continue;
        $urls[$n] = $url;
        $tfiles[$n] = sprintf('%s.%d.%d', basename($source), $i, rand());
        $cmd = str_replace('[source]', $source, str_replace('[file]', $url, $this->options['test_cmd_uplink'] . '/' . $tfiles[$n]));
        if (isset($this->options['test_cmd_url_strip'])) $cmd = str_replace($this->options['test_cmd_url_strip'], '', $cmd);
        $commands[$n] = $cmd;
        $bytes[$n] = filesize($source);
        $ofile = sprintf('%s.out%d', $cfile, $i);
        fwrite($fp, sprintf("%s >%s && timeout %d %s &>/dev/null && %s >>%s &\n", 
                            'date +%s%N', $ofile, $timeout, $cmd, 'date +%s%N', $ofile));
        $i++;
      }
      fwrite($fp, "wait\n");
      // Add purge command(s) for test files created as a result of this test iteration
      $purged = FALSE;
      foreach($requests as $n => $request) {
        if (isset($commands[$n]) && (!$purged || !preg_match('/\*/', $this->options['test_cmd_uplink_del']))) {
          $purged = TRUE;
          $url = trim(str_replace('/up.html', '', str_replace('http://', '', str_replace('https://', '', $request['url']))));
          $cmd = str_replace('[file]', $url, $this->options['test_cmd_uplink_del'] . '/' . $tfiles[$n]);
          if (isset($this->options['test_cmd_url_strip'])) $cmd = str_replace($this->options['test_cmd_url_strip'], '', $cmd);
          fwrite($fp, sprintf("%s &>>/dev/null &\n", $cmd));
        }
      }
      fwrite($fp, "wait\n");
      fclose($fp);
      exec(sprintf('chmod 755 %s', $cfile));
      if ($commands) {
        print_msg(sprintf('Initiating custom uplink command (e.g. %s) %s using %d concurrent requests and script %s', $this->options['test_cmd_uplink'], 
                                                                                                                      $cmd,
                                                                                                                      count($commands), 
                                                                                                                      $cfile), $this->verbose, __FILE__, __LINE__);
        exec($cfile);
        print_msg('Custom uplink command execution complete', $this->verbose, __FILE__, __LINE__);
        $i=1;
        foreach($requests as $n => $request) {
          if (isset($commands[$n])) {
            if (file_exists($ofile = sprintf('%s.out%d', $cfile, $i))) {
              if (!$response) $response = array('urls' => array(), 'results' => array(), 'status' => array(), 'lowest_status' => NULL, 'highest_status' => NULL);
              $pieces = explode("\n", file_get_contents($ofile));
              $start = isset($pieces[0]) && is_numeric($pieces[0]) && $pieces[0] > 0 ? $pieces[0]*1 : NULL;
              $stop = is_numeric($pieces[1]) && $pieces[1] > $start ? $pieces[1]*1 : NULL;
              if ($start && $stop && $bytes[$n]) {
                $ms = ($stop - $start)/1000000;
                $secs = round($ms/1000, 8);
                $response['urls'][] = $request['url'];
                $r = array('speed' => round($bytes[$n]/$secs, 4), 'time' => $secs, 'transfer' => $bytes[$n], 'url' => $commands[$n]);
                $response['results'][] = $r;
                $response['status'][] = 200;
                print_msg(sprintf('Successfully obtained uplink results for request %d: %s', $i, json_encode($r)), $this->verbose, __FILE__, __LINE__);
              }
              else {
                print_msg(sprintf('Unable to get required start/stop/bytes from output file: %s', implode(';', $pieces)), $this->verbose, __FILE__, __LINE__, TRUE);
                $response['urls'][] = $request['url'];
                $response['results'][] = NULL;
                $response['status'][] = 500;
              }
            }
            else print_msg(sprintf('Unable to get output from outfile %s', $ofile), $this->verbose, __FILE__, __LINE__, TRUE);
          }
          $i++;
        }
        exec(sprintf('rm -f %s*', $cfile));
      }
      else {
        print_msg(sprintf('Unable to generate custom command script using %s', $this->options['test_cmd_uplink']), $this->verbose, __FILE__, __LINE__, TRUE);
      }
    }
    else print_msg(sprintf('Unable to open file %s for writing', $cfile), $this->verbose, __FILE__, __LINE__, TRUE);
    
    // lowest and highest status
    if ($response) {
      foreach($response['status'] as $status) {
        if (is_numeric($status)) {
          if (!isset($response['lowest_status']) || $status < $response['lowest_status']) $response['lowest_status'] = $status;
          if (!isset($response['highest_status']) || $status > $response['highest_status']) $response['highest_status'] = $status;
        }
      }
    }
    
    return $response;
  }
  
  /**
   * performs a DNS test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoints test endpoint [0] + optional DNS servers [1-N]
   * @return array
   */
  private function testDns($endpoints) {
    $metrics = NULL;
    if ($endpoint = get_hostname($endpoints[0])) {
      $retries = $this->options['dns_retry'];
      $samples = $this->options['dns_samples'];
      $timeout = $this->options['dns_timeout'];
      // determine name servers to use
      if ($nameservers = count($endpoints) > 1 ? array_slice($endpoints, 1) : array()) print_msg(sprintf('Initiating DNS for hostname %s using explicit name servers: %s', $endpoint, implode(', ', $nameservers)), $this->verbose, __FILE__, __LINE__);
      else {
        // recursive DNS => use /etc/resolv.conf
        if (isset($this->options['dns_recursive'])) {
          foreach(file('/etc/resolv.conf') as $line) {
            if (preg_match('/^nameserver\s+(.*)$/', trim($line), $m)) $nameservers[] = $m[1];
          }
          if ($nameservers) print_msg(sprintf('Initiating DNS for hostname %s using recursive name servers (from /etc/resolv.conf): %s', $endpoint, implode(', ', $nameservers)), $this->verbose, __FILE__, __LINE__);
          else print_msg(sprintf('Unable to determine recursive name servers for hostname %s from /etc/resolv.conf', $endpoint), $this->verbose, __FILE__, __LINE__, TRUE);
        }
        // authoritative name servers (using dig NS)
        else {
          $countries =& $this->getCountries();
          $pieces = explode('.', str_replace('.*', '', $endpoint));
          $last = count($pieces) - 1;
          $domain = isset($countries[strtoupper($pieces[$last])]) && count($pieces) > 3 && $pieces[$last - 2] != 'test' ? sprintf('%s.%s.%s', $pieces[$last - 2], $pieces[$last - 1], $pieces[$last]) : sprintf('%s.%s', $pieces[$last - 1], $pieces[$last]);
          if ($buffer = trim(shell_exec(sprintf('dig +short NS %s 2>/dev/null', $domain)))) {
            foreach(explode("\n", $buffer) as $nameserver) {
              if (substr($nameserver, -1) == '.') $nameserver = substr($nameserver, 0, -1);
              if (!in_array($nameserver, $nameservers)) $nameservers[] = $nameserver;
            }
          }
          if ($nameservers) print_msg(sprintf('Initiating DNS for hostname %s using authoritative name servers: %s', $endpoint, implode(', ', $nameservers)), $this->verbose, __FILE__, __LINE__);
          else print_msg(sprintf('Unable to determine authoritative name servers for hostname %s', $endpoint), $this->verbose, __FILE__, __LINE__, TRUE);
        }
      }
      
      if ($nameservers) {
        $tests_failed = 0;
        $tests_success = 0;
        $metrics = array();
        shuffle($nameservers);
        while(count($metrics) < $samples) {
          $oneServerIndex = NULL;
          foreach($nameservers as $i => $nameserver) {
            // only test one (fixed) name server
            if (isset($this->options['dns_one_server']) && isset($oneServerIndex) && $oneServerIndex != $i) continue;
            
            $metric = NULL;
            $lookup = str_replace('*', rand(), $endpoint);
            $cmd = sprintf('dig +short +%stcp +time=%d%s +%srecurse @%s %s', isset($this->options['dns_tcp']) ? '' : 'no', $timeout, !isset($this->options['dns_tcp']) && $retries != 2 ? ' +retry=' . $retries : '', isset($this->options['dns_recursive']) ? '' : 'no', $nameserver, $lookup);
            print_msg(sprintf('Performing DNS query %d of %d for hostname %s using name server %s [%s]', count($metrics) + 1, $samples, $lookup, $nameserver, $cmd), $this->verbose, __FILE__, __LINE__);
            $start = microtime(TRUE);
            if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $buffer = trim(exec($cmd . ' 2>/dev/null')), $m)) {
              $metric = round((microtime(TRUE) - $start)*1000, 3);
              print_msg(sprintf('Successfully performed DNS query for hostname %s [%s] using name server %s in %s ms', $lookup, $m[1], $nameserver, $metric), $this->verbose, __FILE__, __LINE__);
            }
            else print_msg(sprintf('Unable to perform DNS query for hostname %s using name server %s. Output: %s', $lookup, $nameserver, $buffer), $this->verbose, __FILE__, __LINE__, TRUE);
            
            if ($metric) {
              $tests_success++;
              $oneServerIndex = $i;
              $metrics[] = $metric;
              if (count($metrics) >= $samples) break;
              // spacing
              if (count($metrics) > 1) {
                if (isset($this->options['spacing'])) {
                  usleep($this->options['spacing']*1000);
                  print_msg(sprintf('Applied DNS test spacing of %d ms', $this->options['spacing']), $this->verbose, __FILE__, __LINE__);
                }
                $this->applyConditionalSpacing($metric);
              }
            }
            else $tests_failed++;
          }
          if (!count($metrics)) {
            print_msg(sprintf('Unable to perform DNS query for hostname %s and nameservers: %s', $lookup, implode(', ', $nameservers)), $this->verbose, __FILE__, __LINE__, TRUE);
            break;
          }
        }
        if (count($metrics)) {
          $metrics = array('dns_servers' => isset($this->options['dns_one_server']) ? 1 : count($nameservers), 'metrics' => $metrics, 'tests_failed' => $tests_failed, 'tests_success' => $tests_success);
          print_msg(sprintf('Successfully performed %d DNS queries using %d name servers for endpoint %s. Metrics: [%s]', count($metrics['metrics']), $metrics['dns_servers'], $endpoint, implode(', ', $metrics['metrics'])), $this->verbose, __FILE__, __LINE__);
        }
        else print_msg(sprintf('Unable to perform any DNS queries for endpoint %s', $endpoint), $this->verbose, __FILE__, __LINE__, TRUE);
      }
    }
    return $metrics;
  }
  
  /**
   * performs a latency test for $endpoint. returns either an array of numeric 
   * metrics, or a hash with a 'metrics' key (and may include other meta 
   * attributes to be included in results for this test). returns NULL if the
   * test failed
   * @param string $endpoint test endpoint
   * @return array
   */
  private function testLatency($endpoint) {
    $metrics = NULL;
    if ($endpoint = get_hostname($endpoint)) {
      $interval = $this->options['latency_interval'];
      $samples = $this->options['latency_samples'];
      $timeout = $this->options['latency_timeout'];
      $cmd = sprintf('ping -i %s -c %d -W %d %s 2>/dev/null; echo $?', $interval, $samples, $timeout, $endpoint);
      print_msg(sprintf('Testing latency using ping command: %s', $cmd), $this->verbose, __FILE__, __LINE__);
			if ($buffer = shell_exec($cmd)) {
				$pieces = explode("\n", trim($buffer));
				$ecode = $pieces[count($pieces) - 1];
				// ping successful
				if ($ecode == 0 && preg_match_all('/time\s*=\s*([0-9\.]+)\s+/msU', $buffer, $m)) {
				  $metrics = array('metrics' => array());
					foreach($m[1] as $metric) $metrics['metrics'][] = $metric*1;
					$metrics['tests_failed'] = $samples - count($metrics['metrics']);
					$metrics['tests_success'] = count($metrics['metrics']);
          print_msg(sprintf('ping exited successfully with %d successful, %d failed. Metrics: %s', $metrics['tests_success'], $metrics['tests_failed'], implode(', ', $metrics['metrics'])), $this->verbose, __FILE__, __LINE__);
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
   * performs a throughput test for $endpoint. returns either an array of 
   * numeric metrics, or a hash with a 'metrics' key (and may include other 
   * meta attributes to be included in results for this test). returns NULL if 
   * the test failed
   * @param string $endpoint test endpoint
   * @param int $idx the endpoint test index
   * @param boolean $uplink if TRUE, uplink test will be performed, otherwise
   * downlink
   * @return array
   */
  private function testThroughput($endpoint, $idx, $uplink=FALSE) {
    // params: throughput_same_continent, throughput_same_country, 
    // throughput_same_geo_region, throughput_same_provider, throughput_same_region, 
    // throughput_same_service, throughput_same_state
    $metrics = NULL;
    $type = $uplink ? 'uplink' : 'downlink';
    
    // determine base endpoint URLs
    $endpoints = array();
    if (preg_match('/^http/', $endpoint)) $endpoints[] = $endpoint;
    else {
      foreach(isset($this->options['throughput_https']) ? array('https', 'http') : array('http') as $proto) $endpoints[] = sprintf('%s://%s', $proto, $endpoint);
    }
    
    if (!isset($this->options['throughput_webpage'])) {
      foreach(array_keys($endpoints) as $i) {
        // add base URI suffix
        if (!preg_match('/^https?:\/\/.*\//', $endpoints[$i])) $endpoints[$i] = sprintf('%s%s%s', $endpoints[$i], substr($this->options['throughput_uri'], 0, 1) != '/' ? '/' : '', $this->options['throughput_uri']);
        // remove trailing /
        else if (substr($endpoints[$i], -1, 1) == '/') $endpoints[$i] = substr($endpoints[$i], 0, strlen($endpoints[$i]) - 1);
      } 
    }
    
    $samples = $this->options['throughput_samples'];
    $ping = FALSE;
    if (!isset($this->options['throughput_small_file']) && !isset($this->options['throughput_webpage'])) {
      $sizeMb = $this->options['throughput_size'];

      // adjust size for same constraints
      if ($sizeMb > 0) {
        foreach(array('continent', 'country', 'geo_region', 'provider', 'region', 'service', 'state') as $param) {
          $key = sprintf('throughput_same_%s', $param);
          if (isset($this->options[$key]) && $this->options[$key] > $sizeMb) {
            print_msg(sprintf('Evaluating --%s parameter for size %d MB [current size %d MB]', $key, $this->options[$key], $sizeMb), $this->verbose, __FILE__, __LINE__);
            if ($this->validateSameConstraints($idx, $param)) {
              $sizeMb = $this->options[$key];
              print_msg(sprintf('--%s parameter matches for endpoint %s. Test size increased to %d MB', $key, $endpoint, $sizeMb), $this->verbose, __FILE__, __LINE__);
            }
            else print_msg(sprintf('Skipping parameter --%s because it does not match endpoint %s', $key, $endpoint), $this->verbose, __FILE__, __LINE__);
          }
          else if (isset($this->options[$key])) print_msg(sprintf('Skipping --%s parameter for size %d MB because it is less than the current size %d MB', $key, $this->options[$key], $sizeMb), $this->verbose, __FILE__, __LINE__);
        }
        print_msg(sprintf('Using test size %d MB for endpoint %s', $sizeMb, $endpoint), $this->verbose, __FILE__, __LINE__);
      }
      else $ping = TRUE;

      $size = $ping ? 8 : ($sizeMb*1024)*1024;
    }
    $threads = $this->options['throughput_threads'];
    $timeout = $this->options['throughput_timeout'];
    $serviceType = isset($this->options['test_service_type']) && array_key_exists($idx, $this->options['test_service_type']) ? $this->options['test_service_type'][$idx] : (isset($this->options['test_service_type'][0]) ? $this->options['test_service_type'][0] : NULL);
    $expectedBytes = 0;
    foreach($endpoints as $endpoint) {
      print_msg(sprintf('Attempting %s test using base URI %s; samples: %d; size: %s MB; threads: %d; timeout: %d', $type, $endpoint, $samples, isset($this->options['throughput_small_file']) ? 'rand small file' : (isset($this->options['throughput_webpage']) ? 'full page test' : $sizeMb), $threads, $timeout), $this->verbose, __FILE__, __LINE__);
      $requests = array();
      for($i=0; $i<$threads; $i++) {
        $request = array('method' => $uplink ? 'POST' : 'GET');
        // add custom request headers
        if (isset($this->options['throughput_header']) && is_array($this->options['throughput_header']) && $this->options['throughput_header']) $request['headers'] = $this->options['throughput_header'];
        
        if ($uplink) $request['url'] = $url = sprintf('%s/up.html', $endpoint);
        if (!isset($this->options['throughput_small_file']) && !isset($this->options['throughput_webpage'])) {
          $file = $this->getDownlinkFile($size, $serviceType);
          if ($uplink) {
            if (isset($this->options['test_files_dir'])) {
              $request['body'] = sprintf('%s/%s', $this->options['test_files_dir'], $file['name']);
              $expectedBytes += $file['size'];
            }
            else {
              $request['body'] = $size;
              $expectedBytes += $size;
            }
          }
          else {
            $request['url'] = $url = sprintf('%s/%s', $endpoint, $file['name']);
            $expectedBytes += $file['size'];   
          }
        }
        $requests[] = $request;
        print_msg(sprintf('Added curl request [%s] [%s] to test', implode(', ', array_keys($request)), json_encode($request)), $this->verbose, __FILE__, __LINE__);
      }
      for($i=0; $i<$samples; $i++) {
        // full page test
        if (isset($this->options['throughput_webpage'])) {
          $url = $endpoint;
          $resources = isset($this->options['throughput_webpage'][$idx]) ? $this->options['throughput_webpage'][$idx] : $this->options['throughput_webpage'][0];
          // prefix resources with endpoint if necessary
          foreach(array_keys($resources) as $k) {
            if (!preg_match('/^http/', $resources[$k])) $resources[$k] = sprintf('%s%s%s', $endpoint, substr($resources[$k], 0, 1) == '/' ? '' : '/', $resources[$k]);
          }
          sort($resources);
          if (count($resources) < $threads) {
            print_msg(sprintf('Reducing number of threads from %d to %d to match number of page resources', $threads, count($resources)), $this->verbose, __FILE__, __LINE__);
            $requests = array_slice($requests, 0, count($resources));
            $threads = count($resources);
            $this->options['throughput_threads'] = $threads;
          }
          $resourcesPerThread = round(count($resources)/$threads);
          $resourcesFirstThread = count($resources) - (($threads - 1) * $resourcesPerThread);
          if (!$resourcesFirstThread) {
            $resourcesFirstThread = $resourcesPerThread;
            print_msg(sprintf('Reducing number of threads from %d to %d so requests are evenly distributed', $threads, $threads - 1), $this->verbose, __FILE__, __LINE__);
            array_pop($requests);
            $threads--;
            $this->options['throughput_threads'] = $threads;
          }
          print_msg(sprintf('Dividing resources [%s] evenly for %d threads [%d resources for first thread; %d resources others]', implode(', ', $resources), $threads, $resourcesFirstThread, $resourcesPerThread), $this->verbose, __FILE__, __LINE__);
          $slicePtr = 0;
          foreach(array_keys($requests) as $n) {
            $requests[$n]['url'] = array_slice($resources, $slicePtr, $n == 0 ? $resourcesFirstThread : $resourcesPerThread);
            $slicePtr += ($n == 0 ? $resourcesFirstThread : $resourcesPerThread);
            print_msg(sprintf('Thread %d assigned requests [%s]', $n+1, implode(', ', $requests[$n]['url'])), $this->verbose, __FILE__, __LINE__);
          }
          $expectedBytes = NULL;
        }
        // choose random small file URLs/upload sizes
        else if (isset($this->options['throughput_small_file'])) {
          $expectedBytes = 0;
          foreach(array_keys($requests) as $n) {
            $size = rand(1, self::SMALL_FILE_LIMIT);
            $file = $this->getDownlinkFile($size, $serviceType);
            if ($uplink) {
              if (isset($this->options['test_files_dir'])) {
                $requests[$n]['body'] = sprintf('%s/%s', $this->options['test_files_dir'], $file['name']);
                $expectedBytes += $file['size'];
              }
              else {
                $requests[$n]['body'] = $size;
                $expectedBytes += $size;
              }
            }
            else {
              $requests[$n]['url'] = $url = sprintf('%s/%s', $endpoint, $file['name']);
              $expectedBytes += $file['size'];
            }
            print_msg(sprintf('Added small file request for size %d and URL %s', $size, $requests[$n]['url']), $this->verbose, __FILE__, __LINE__);
          }
        }
        // duplicate requests for # of samples when throughput_keepalive is set
        if (isset($this->options['throughput_keepalive']) && $samples > 1 && !isset($this->options['throughput_webpage'])) {
          print_msg(sprintf('Duplicating requests for %d samples because throughput_keepalive is set', $samples), $this->verbose, __FILE__, __LINE__);
          foreach(array_keys($requests) as $n) {
            $requests[$n]['url'] = array($requests[$n]['url']);
            for($x=1; $x<$samples; $x++) {
              if (isset($this->options['throughput_small_file']) && !$uplink) {
                $size = rand(1, self::SMALL_FILE_LIMIT);
                $file = $this->getDownlinkFile($size, $serviceType);
                $requests[$n]['url'][] = sprintf('%s/%s', $endpoint, $file['name']);
                $expectedBytes += $file['size'];
              }
              else $requests[$n]['url'][] = $requests[$n]['url'][0];
            }
            print_msg(sprintf('New URLs for request %d: %s', $n+1, implode('; ', $requests[$n]['url'])), $this->verbose, __FILE__, __LINE__);
          }
          if (!isset($this->options['throughput_small_file']) || $uplink) $expectedBytes *= $samples;
        }
        
        // spacing
        if ($metrics && $i > 1) {
          if (isset($this->options['spacing'])) {
            print_msg(sprintf('Applying throughput test spacing of %d ms', $this->options['spacing']), $this->verbose, __FILE__, __LINE__);
            usleep($this->options['spacing']*1000);
          }
          $this->applyConditionalSpacing($metrics['metrics'][count($metrics['metrics']) - 1]);
        }
        
        if ($uplink && isset($this->options['test_cmd_uplink'])) $response = $this->testCustomUplink($requests, $timeout);
        else if (!$uplink && isset($this->options['test_cmd_downlink'])) $response = $this->testCustomDownlink($requests, $timeout);
        else $response = ch_curl_mt($requests, $timeout, $this->options['output'], FALSE, preg_match('/^https/', $url) ? TRUE : FALSE);
        
        if ($response) {
          if (isset($response['lowest_status']) && isset($response['highest_status']) && isset($response['results']) && count($response['results'])) {
            if ($response['lowest_status'] >= 200 && $response['highest_status'] < 300) {
              print_msg(sprintf('curl request(s) for samples %d of %d completed successfully - highest response status is %d and %d results exist', $i+1, $samples, $response['highest_status'], count($response['results'])), $this->verbose, __FILE__, __LINE__);
              $speeds = array();
              $times = array();
              $bytes = 0;
              $numRequests = count($response['results']);
              $slowestThread = 0;
              $fastestThread = NULL;
              foreach($response['results'] as $n => $result) {
                $rspeeds = array();
                $rtimes = array();
                $rbytes = 0;
                print_msg(sprintf('Adding curl result %s', implode(', ', array_keys($result))), $this->verbose, __FILE__, __LINE__);
                foreach(is_array($result['speed']) ? $result['speed'] : array($result['speed']) as $s) $rspeeds[] = round((($s*8)/1024)/1024, 6);
                foreach(is_array($result['time']) ? $result['time'] : array($result['time']) as $t) $rtimes[] = $t*1000;
                foreach(is_array($result['transfer']) ? $result['transfer'] : array($result['transfer']) as $b) $rbytes += $b;
                print_msg(sprintf('Request %d speeds: [%s]; times: [%s]; bytes: %d', $n+1, implode(', ', $rspeeds), implode(', ', $rtimes), $rbytes), $this->verbose, __FILE__, __LINE__);
                
                $speeds[] = get_mean($rspeeds, 6);
                $time = array_sum($rtimes);
                $times[] = $time;
                if ($time > $slowestThread) $slowestThread = $time;
                if (!isset($fastestThread) || $time < $fastestThread) $fastestThread = $time;
                $bytes += $rbytes;
              }
              print_msg(sprintf('Got curl results. speed: [%s]; time: [%s]; total transfer: %d', implode(', ', $speeds), implode(', ', $times), $bytes), $this->verbose, __FILE__, __LINE__);
              $mbTransferred = round(($bytes/1024)/1024, 6);
              if (isset($expectedBytes) && !$ping && $bytes < ($expectedBytes*$this->options['throughput_tolerance'])) print_msg(sprintf('Megabytes transfered %s does not match expected %s', $mbTransferred, round(($expectedBytes/1024)/1024, 6)), $this->verbose, __FILE__, __LINE__, TRUE);
              else {
                if (!isset($metrics)) {
                  $metrics = array('metrics' => array(), 'throughput_size' => array(), 'throughput_threads' => $threads);
                  if (preg_match('/^https/', $url)) $metrics['throughput_https'] = TRUE;
                  if (isset($this->options['throughput_small_file'])) $metrics['throughput_small_file'] = TRUE;
                  if (!$ping) $metrics['throughput_transfer'] = 0;
                }
                $meanMbs = get_mean($speeds, 6);
                $medianMbs = get_median($speeds, 6);
                $meanTime = get_mean($times, 6);
                $medianTime = get_median($times, 6);
                $totalMbs = isset($this->options['throughput_slowest_thread']) ? round(($mbTransferred*8)/($slowestThread/1000), 2) : (isset($this->options['throughput_use_mean']) ? $meanMbs : $medianMbs)*$numRequests;
                $totalTime = (isset($this->options['throughput_use_mean']) ? $meanTime : $medianTime)*$numRequests;
                $metrics['metrics'][] = isset($this->options['throughput_time']) ? (isset($this->options['throughput_webpage']) ? $totalTime : (isset($this->options['throughput_use_mean']) ? $meanTime : $medianTime)) : $totalMbs;
                $metrics['throughput_size'][] = round((($bytes/1024)/1024)/$numRequests, 6);
                if (!$ping) $metrics['throughput_transfer'] += $mbTransferred;
                print_msg(sprintf('Test sample %d of %d for URL %s successful. Mean/median rate is [%s %s] Mb/s. Mean/median time is [%s %s] ms. Total rate is %s Mb/s. Total time is %s secs. Slowest thread was %s secs. Fastest thread was %s secs. %s MB transfer on %d reqs', $i+1, $samples, $url, $meanMbs, $medianMbs, $meanTime, $medianTime, round($totalMbs, 4), round($totalTime/1000, 4), round($slowestThread/1000, 4), round($fastestThread/1000, 4), $mbTransferred, $numRequests), $this->verbose, __FILE__, __LINE__);
              }
            }
            else print_msg(sprintf('curl request(s) failed for URL %s because lowest and highest status %d/%d is not in the 2XX range', $url, $response['lowest_status'], $response['highest_status']), $this->verbose, __FILE__, __LINE__, TRUE);
          }
          else print_msg(sprintf('curl request(s) did not return highest_status or results for URL %s', $url), $this->verbose, __FILE__, __LINE__, TRUE);
        }
        else if ($uplink && isset($this->options['test_cmd_uplink'])) {
          print_msg(sprintf('test_cmd_uplink %s failed for resource %s', $this->options['test_cmd_uplink'], $url), $this->verbose, __FILE__, __LINE__, TRUE);
        }
        else if (!$uplink && isset($this->options['test_cmd_downlink'])) {
          print_msg(sprintf('test_cmd_downlink %s failed for resource %s', $this->options['test_cmd_downlink'], $url), $this->verbose, __FILE__, __LINE__, TRUE);
        }
        else {
          print_msg(sprintf('curl request(s) failed for URL %s', $url), $this->verbose, __FILE__, __LINE__, TRUE);
        }
        
        if (!$metrics && (!$response || !isset($response['lowest_status']) || $response['lowest_status'] >= 300)) break;
        if (isset($this->options['throughput_keepalive']) && !isset($this->options['throughput_webpage'])) {
          print_msg(sprintf('Throughput test ending because throughput_keepalive was set'), $this->verbose, __FILE__, __LINE__);
          break;
        }
      }
      if ($metrics) {
        $metrics['throughput_size'] = $ping ? 0 : round(array_sum($metrics['throughput_size'])/count($metrics['throughput_size']), 6);
        if (isset($this->options['throughput_time'])) $metrics['throughput_time'] = TRUE;
				$metrics['tests_failed'] = isset($this->options['throughput_keepalive']) && !isset($this->options['throughput_webpage']) && count($metrics['metrics']) ? 0 : $samples - count($metrics['metrics']);
				$metrics['tests_success'] = isset($this->options['throughput_keepalive']) && !isset($this->options['throughput_webpage']) && count($metrics['metrics']) ? $samples : count($metrics['metrics']);
        print_msg(sprintf('%s testing completed with %d successful, %d failed using endpoint %s. Metrics are [%s] %s', $type, $metrics['tests_success'], $metrics['tests_failed'], $endpoint, implode(', ', $metrics['metrics']), isset($this->options['throughput_time']) ? 'ms' : 'Mb/s'), $this->verbose, __FILE__, __LINE__);
        break;
      }
    }
    
    return $metrics;
  }
  
  /**
   * returns TRUE if use of the private network address should be attempting 
   * during testing (same provider, service and region)
   * @param int $i the endpoint parameter index
   * @return boolean
   */
  private function usePrivateNetwork($i) {
    $usePrivate = FALSE;
    $serviceId = isset($this->options['test_service_id']) ? (array_key_exists($i, $this->options['test_service_id']) ? $this->options['test_service_id'][$i] : $this->options['test_service_id'][0]) : NULL;
    $region = isset($this->options['test_region']) ? (array_key_exists($i, $this->options['test_region']) ? $this->options['test_region'][$i] : $this->options['test_region'][0]) : NULL;
    if ($serviceId && isset($this->options['meta_compute_service_id']) && $this->options['meta_compute_service_id'] == $serviceId && 
       ((!$region && !isset($this->options['meta_region'])) || ($region && isset($this->options['meta_region']) && $region == $this->options['meta_region']))) $usePrivate = TRUE;
    return $usePrivate;
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
      'conditional_spacing' => array('regex' => self::CONDITIONAL_SPACING_REGEX),
      'discard_fastest' => array('max' => 40, 'min' => 0),
      'discard_slowest' => array('max' => 40, 'min' => 0),
      'dns_retry' => array('max' => 10, 'min' => 1, 'required' => TRUE),
      'dns_samples' => array('max' => 100, 'min' => 1, 'required' => TRUE),
      'dns_timeout' => array('max' => 60, 'min' => 1, 'required' => TRUE),
      'geo_regions' => array('option' => array_keys($this->getGeoRegions())),
      'latency_interval' => array('max' => 10, 'min' => 0, 'required' => TRUE),
      'latency_samples' => array('max' => 100, 'min' => 1, 'required' => TRUE),
      'latency_timeout' => array('max' => 30, 'min' => 1, 'required' => TRUE),
      'max_runtime' => array('min' => 10),
      'max_tests' => array('min' => 1),
      'min_runtime' => array('min' => 10),
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
      'throughput_size' => array('max' => 1024, 'min' => 0, 'required' => TRUE),
      'throughput_threads' => array('max' => 512, 'min' => 1, 'required' => TRUE),
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
    
    // validate custom uplink/downlink commands
    if (isset($this->options['test_cmd_downlink']) && !preg_match('/\[file\]/', $this->options['test_cmd_downlink'])) {
      $validated['test_cmd_downlink'] = '--test_cmd_downlink must contain the substring [file]';
    }
    if (isset($this->options['test_cmd_uplink']) && (!preg_match('/\[file\]/', $this->options['test_cmd_uplink']) || 
                                                     !preg_match('/\[source\]/', $this->options['test_cmd_uplink']))) {
      $validated['test_cmd_uplink'] = '--test_cmd_uplink must contain substrings [file] AND [source]';
    }
    if (isset($this->options['test_cmd_uplink']) && !isset($this->options['test_cmd_uplink_del'])) {
      $validated['test_cmd_uplink_del'] = '--test_cmd_uplink_del is required if --test_cmd_uplink has been set';
    }
    if (isset($this->options['test_cmd_uplink_del']) && !preg_match('/\[file\]/', $this->options['test_cmd_uplink_del']) && 
        !preg_match('/\*/', $this->options['test_cmd_uplink_del'])) {
      $validated['test_cmd_uplink_del'] = '--test_cmd_uplink_del must contain the substring [file] OR a wildcard';
    }
    if (isset($this->options['throughput_keepalive']) && (isset($this->options['test_cmd_downlink']) || isset($this->options['test_cmd_uplink']))) {
      $validated['throughput_keepalive'] = '--throughput_keepalive cannot be used in conjunction with test_cmd_downlink or test_cmd_uplink';
    }
    if (isset($this->options['throughput_webpage']) && (isset($this->options['test_cmd_downlink']) || isset($this->options['test_cmd_uplink']))) {
      $validated['throughput_webpage'] = '--throughput_webpage cannot be used in conjunction with test_cmd_downlink or test_cmd_uplink';
    }
    
    // validate test_files_dir
    if (isset($this->options['test_files_dir'])) {
      if (!is_dir($this->options['test_files_dir'])) {
        $validated['test_files_dir'] = sprintf('--test_files_dir %s is not a directory', $this->options['test_files_dir']);
      }
      else if (!is_readable($this->options['test_files_dir'])) {
        $validated['test_files_dir'] = sprintf('--test_files_dir %s is not readable', $this->options['test_files_dir']);
      }
      else {
        if (!isset($this->dowlinkFiles)) {
          $this->dowlinkFiles = string_to_hash(file_get_contents(dirname(__FILE__) . '/config/downlink-files.ini'));
        }
        foreach($this->dowlinkFiles as $f => $s) {
          $file = sprintf('%s/%s', $this->options['test_files_dir'], $f);
          if (!file_exists($file)) {
            $validated['test_files_dir'] = sprintf('--test_files_dir %s does not contain the file %s', $this->options['test_files_dir'], $f);
            break;
          }
          else if ((abs($s - filesize($file))/$s) > 0.1) {
            $validated['test_files_dir'] = sprintf('The test file %s in --test_files_dir %s is more than 10% different in size from expected (%d vs %d)', 
                                                   $f, $this->options['test_files_dir'], filesize($file), $s);
            break;
          }
        }
      }
    }
    
    // validate test_endpoint association parameters
    if (!isset($validated['test_endpoint']) && count($this->options['test_endpoint']) && !isset($this->options['service_lookup']) && !isset($this->options['geoiplookup'])) {
      foreach(array('test_instance_id', 'test_location', 'test_private_network_type', 'test_provider', 'test_provider_id', 'test_region', 'test_service', 'test_service_id', 'test_service_type', 'throughput_webpage') as $param) {
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
    
    if (isset($this->options['throughput_webpage']) && isset($this->options['throughput_webpage_check'])) {
      $nurls = NULL;
      $mismatch = FALSE;
      foreach($this->options['throughput_webpage'] as $i => $webpages) {
        if (!isset($nurls)) $nurls = count($webpages);
        else if (count($webpages) != $nurls) {
          $mismatch = TRUE;
          break;
        }
      }
      if ($mismatch) $validated['throughput_webpage_check'] = sprintf('Use of --throughput_webpage_check requires the number of webpages in each --throughput_webpage parameter to be equal. The first such parameter contained %d URLs, while the %d parameter contained %d URLs', $nurls, $i+1, count($webpages));
      // test each individual URL
      else {
        $sizes = array();
        $remove = array();
        $headers = isset($this->options['throughput_header']) && is_array($this->options['throughput_header']) && $this->options['throughput_header'] ? $this->options['throughput_header'] : NULL;
        foreach($this->options['test_endpoint'] as $idx => $endpoint) {
          $webpages = isset($this->options['throughput_webpage'][$idx]) ? $this->options['throughput_webpage'][$idx] : $this->options['throughput_webpage'][0];
          foreach($webpages as $i => $webpage) {
            if (!isset($remove[$i])) {
              $url = preg_match('/^http/', $webpage) ? $webpage : sprintf('%s%s%s', $endpoint[0], substr($webpage, 0, 1) == '/' ? '' : '/', $webpage);
              if (file_exists($ofile = ch_curl($url, 'GET', $headers, NULL, NULL, '200-299', 2))) {
                if (!isset($sizes[$i])) $sizes[$i] = filesize($ofile);
                else {
                  $diff = $sizes[$i]*0.05;
                  if (abs($sizes[$i] - filesize($ofile)) > $diff) {
                    print_msg(sprintf('Unable to validate endpoint %d URL %d %s because size %d does not match initial request size %d - URL will be removed from all endpoints', $idx+1, $i+1, $url, filesize($ofile), $sizes[$i]), $this->verbose, __FILE__, __LINE__, TRUE);
                    $remove[$i] = TRUE;
                  }
                  else print_msg(sprintf('Successfully validated endpoint %d URL %d %s', $idx+1, $i+1, $url), $this->verbose, __FILE__, __LINE__); 
                }
                exec(sprintf('rm -f %s', $ofile));
              }
              else {
                print_msg(sprintf('Unable to validate endpoint %d URL %d %s - URL will be removed from all endpoints', $idx+1, $i+1, $url), $this->verbose, __FILE__, __LINE__, TRUE);
                $remove[$i] = TRUE;
              }
            }
          }
        }
        foreach($this->options['throughput_webpage'] as $i => $webpages) {
          foreach($webpages as $n => $webpage) {
            if (isset($remove[$n])) {
              print_msg(sprintf('Removing webpage %s from --throughput_webpage %d', $webpage, $i+1), $this->verbose, __FILE__, __LINE__);
              unset($this->options['throughput_webpage'][$i][$n]);
            }
          }
          if (!count($this->options['throughput_webpage'][$i])) {
            $validated['throughput_webpage'] = sprintf('throughput_webpage at index %d has not URIs', $i);
            break;
          }
        }
      }
    }
    
    // validate collectd rrd options
    if (isset($this->options['collectd_rrd'])) {
      if (!ch_check_sudo()) $validated['collectd_rrd'] = 'sudo privilege is required to use this option';
      else if (!is_dir($this->options['collectd_rrd_dir'])) $validated['collectd_rrd_dir'] = sprintf('The directory %s does not exist', $this->options['collectd_rrd_dir']);
      else if ((shell_exec('ps aux | grep collectd | wc -l')*1 < 2)) $validated['collectd_rrd'] = 'collectd is not running';
      else if ((shell_exec(sprintf('find %s -maxdepth 1 -type d 2>/dev/null | wc -l', $this->options['collectd_rrd_dir']))*1 < 2)) $validated['collectd_rrd_dir'] = sprintf('The directory %s is empty', $this->options['collectd_rrd_dir']);
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
    if (isset($this->options['collectd_rrd'])) $dependencies['zip'] = 'zip';
    foreach($this->options['test'] as $tests) {
      if (in_array('latency', $tests)) $dependencies['ping'] = 'ping';
      if (in_array('downlink', $tests) || in_array('uplink', $tests) || in_array('throughput', $tests)) $dependencies['curl'] = 'curl';
      if (in_array('dns', $tests)) $dependencies['dig'] = 'dig';
    }
    return validate_dependencies($dependencies);
  }
  
  /**
   * applies conditional spacing if applicable. Returns TRUE if spacing was 
   * applied, FALSE otherwise
   * @param float $metric the prior test interval result
   * @return boolean
   */
  private function applyConditionalSpacing($metric) {
    $applied = FALSE;
    if (is_numeric($metric) && $metric >= 0 && isset($this->options['conditional_spacing']) && preg_match(self::CONDITIONAL_SPACING_REGEX, $this->options['conditional_spacing'], $m)) {
      $op = $m[1];
      $threshold = $m[2]*1;
      $sleep = $m[3]*1;
      switch($op) {
        case '<':
          $applied = $metric < $threshold;
          break;
        case '>':
          $applied = $metric > $threshold;
          break;
      }
      if ($applied) {
        print_msg(sprintf('Applying sleep interval of %s ms due to conditional_spacing parameter evaluating to true: %s %s %s', $sleep, $metric, $op, $threshold), $this->verbose, __FILE__, __LINE__);
        usleep($sleep*1000);
      }
    }
    return $applied;
  }
  
  /**
   * returns TRUE if the all --same* constraints match for $endpoint, FALSE 
   * otherwise
   * @param int $i the endpoint parameter index
   * @param string $type optional explicit same constraint to match - otherwise 
   * all those specified by the same_* parameters will be checked
   * @return boolean
   */
  private function validateSameConstraints($i, $type=NULL) {
    $endpoint = $this->options['test_endpoint'][$i][0];
    $location = isset($this->options['test_location']) && array_key_exists($i, $this->options['test_location']) ? $this->options['test_location'][$i] : (isset($this->options['test_location'][0]) ? $this->options['test_location'][0] : NULL);
    $country = isset($this->options['test_location_country']) && array_key_exists($i, $this->options['test_location_country']) ? $this->options['test_location_country'][$i] : (isset($this->options['test_location_country'][0]) ? $this->options['test_location_country'][0] : NULL);
    $state = isset($this->options['test_location_state']) && array_key_exists($i, $this->options['test_location_state']) ? $this->options['test_location_state'][$i] : (isset($this->options['test_location_state'][0]) ? $this->options['test_location_state'][0] : NULL);
    $provider = isset($this->options['test_provider']) && array_key_exists($i, $this->options['test_provider']) ? $this->options['test_provider'][$i] : (isset($this->options['test_provider'][0]) ? $this->options['test_provider'][0] : NULL);
    $providerId = isset($this->options['test_provider_id']) && array_key_exists($i, $this->options['test_provider_id']) ? $this->options['test_provider_id'][$i] : (isset($this->options['test_provider_id'][0]) ? $this->options['test_provider_id'][0] : NULL);
    $service = isset($this->options['test_service']) && array_key_exists($i, $this->options['test_service']) ? $this->options['test_service'][$i] : (isset($this->options['test_service'][0]) ? $this->options['test_service'][0] : NULL);
    $serviceId = isset($this->options['test_service_id']) && array_key_exists($i, $this->options['test_service_id']) ? $this->options['test_service_id'][$i] : (isset($this->options['test_service_id'][0]) ? $this->options['test_service_id'][0] : NULL);
    $serviceType = isset($this->options['test_service_type']) && array_key_exists($i, $this->options['test_service_type']) ? $this->options['test_service_type'][$i] : (isset($this->options['test_service_type'][0]) ? $this->options['test_service_type'][0] : NULL);
    $region = isset($this->options['test_region']) && array_key_exists($i, $this->options['test_region']) ? $this->options['test_region'][$i] : (isset($this->options['test_region'][0]) ? $this->options['test_region'][0] : NULL);
    
    $nodeCountry = isset($this->options['meta_location_country']) ? $this->options['meta_location_country'] : NULL;
    $nodeState = isset($this->options['meta_location_state']) ? $this->options['meta_location_state'] : NULL;
    $nodeRegion = isset($this->options['meta_region']) ? $this->options['meta_region'] : NULL;
    $nodeLocation = isset($this->options['meta_location']) ? $this->options['meta_location'] : NULL;
    $nodeProvider = isset($this->options['meta_provider']) ? $this->options['meta_provider'] : NULL;
    $nodeProviderId = isset($this->options['meta_provider_id']) ? $this->options['meta_provider_id'] : NULL;
    $nodeService = isset($this->options['meta_service']) ? $this->options['meta_service'] : NULL;
    $nodeServiceId = isset($this->options['meta_service_id']) ? $this->options['meta_service_id'] : NULL;
    
    $checkContinent = $serviceType != 'cdn' && $serviceType != 'dns' && ((!$type && isset($this->options['same_continent_only'])) || $type == 'continent');
    $checkCountry = $serviceType != 'cdn' && $serviceType != 'dns' && ((!$type && isset($this->options['same_country_only'])) || $type == 'country');
    $checkState = (!$type && isset($this->options['same_state_only'])) || $type == 'state';
    $checkGeoRegion = $serviceType != 'cdn' && $serviceType != 'dns' && ((!$type && isset($this->options['same_geo_region'])) || $type == 'geo_region');
    $checkProvider = (!$type && isset($this->options['same_provider_only'])) || $type == 'provider';
    $checkService = (!$type && isset($this->options['same_service_only'])) || $type == 'service';
    $checkRegion = (!$type && isset($this->options['same_region_only'])) || $type == 'region';
    
    $valid = TRUE;
    
    // continent
    if ($checkContinent && $this->getContinent($nodeCountry) != $this->getContinent($country)) {
      print_msg(sprintf('Same continent constraint does not match for test endpoint %s because its country %s is not in the same continent as the test node country %s', $endpoint, $country, $nodeCountry), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // country
    else if (($checkCountry || $checkState) && $country != $nodeCountry) {
      print_msg(sprintf('Same country constraint does not match for test endpoint %s because its country %s is not the same as the test node country %s', $endpoint, $country, $nodeCountry), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // state
    else if ($checkState && $state != $nodeState) {
      print_msg(sprintf('Same state constraint does not match for test endpoint %s because its state %s is not the same as the test node state %s', $endpoint, $state, $nodeState), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // geo_region
    else if ($checkGeoRegion && $this->getGeoRegion($country, $state) != $this->getGeoRegion($nodeCountry, $nodeState)) {
      print_msg(sprintf('Same geo_region constraint does not match for test endpoint %s because its location %s is not in the same geo region as the test node location %s', $endpoint, $location, $nodeLocation), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // provider
    else if (($checkProvider || $checkService || $checkRegion) && $provider != $nodeProvider && $providerId != $nodeProviderId) {
      print_msg(sprintf('Same provider constraint does not match for test endpoint %s because its provider %s [%s] is not in the same the test node provider %s [%s]', $endpoint, $provider, $providerId, $nodeProvider, $nodeProviderId), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // service
    else if (($checkService || $checkRegion) && $service != $nodeService && $serviceId != $nodeServiceId) {
      print_msg(sprintf('Same service constraint does not match for test endpoint %s because its service %s [%s] is not in the same the test node service %s [%s]', $endpoint, $service, $serviceId, $nodeService, $nodeServiceId), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    // region
    else if ($checkRegion && $region != $nodeRegion) {
      print_msg(sprintf('Same service region constraint does not match for test endpoint %s because its service region %s is not in the same the test node service region %s', $endpoint, $region, $nodeRegion), $this->verbose, __FILE__, __LINE__);
      $valid = FALSE;
    }
    
    return $valid;
  }
  
}
?>
