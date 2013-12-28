<?php
/***********************************************************************************************************
                                       Module functions 
************************************************************************************************************/

define('MODULE_STATUS_NOTINSTALLED', 0);
define('MODULE_STATUS_DISABLED', 1);
define('MODULE_STATUS_ENABLED', 2);
define('MODULE_STATUS_NEEDUPGRADE', 3);
define('MODULE_STATUS_BROKEN', -1);

class module_functions {
	public $security_array = null;
	
	function &create() {
		static $obj;
		global $db;
		if (!isset($obj) || !is_object($obj)) {
			$obj = new module_functions();
		}
		return $obj;
	}
	
	/** 
	 * Get the latest module.xml file for this FreePBX version.
	 *
	 * Caches in the database for 5 mintues.
	 * If $module is specified, only returns the data for that module.
	 * If the module is not found (or none are available for whatever reason),
	 * then null is returned.
	 *
	 * Sets the global variable $module_getonlinexml_error to true if an error
	 * occurred getting the module from the repository, false if no error occurred,
	 * or null if the repository wasn't checked. Note that this may change in the 
	 * future if we decide we need to return more error codes, but as long as it's
	 * a php zero-value (false, null, 0, etc) then no error happened.
	 * 
	 * @param string $module rawname of module to get xml for
	 * @param bool $override_xml Different xml path to use for repos instead of the included default
	 * @return mixed combined module xml array if true, null if no data
	 */
	function getonlinexml($module = false, $override_xml = false) { // was getModuleXml()
		global $amp_conf, $db, $module_getonlinexml_error;  // okay, yeah, this sucks, but there's no other good way to do it without breaking BC
		$module_getonlinexml_error = null;
		$got_new = false;
		$skip_cache = false;
		$sec_array=false;
		$type = array('all','module','track','security','old');

		$result = sql("SELECT * FROM module_xml WHERE id = 'beta'",'getRow',DB_FETCHMODE_ASSOC);
		$beta = $result['data'];
		$result = sql("SELECT * FROM module_xml WHERE id = 'security'",'getRow',DB_FETCHMODE_ASSOC);
		$security = $result['data'];
		$result = sql("SELECT * FROM module_xml WHERE id = 'previous'",'getRow',DB_FETCHMODE_ASSOC);
		$previous = $result['data'];
		//get this last for timestamp
		$result = sql("SELECT * FROM module_xml WHERE id = 'xml'",'getRow',DB_FETCHMODE_ASSOC);
		$data = $result['data'];

		// Check if the cached module xml is for the same repo as being requested
		// if not, then we get it anyhow
		//
		$repo_url = ($override_xml === false) ? $amp_conf['MODULE_REPO'] : $override_xml;
		$result2 = sql("SELECT * FROM module_xml WHERE id = 'module_repo'",'getRow',DB_FETCHMODE_ASSOC);
		$last_repo = $result2['data'];
		if ($last_repo !== $repo_url) {
			sql("DELETE FROM module_xml WHERE id = 'module_repo'");
			$data4sql = $db->escapeSimple($repo_url);
			sql("INSERT INTO module_xml (id,time,data) VALUES ('module_repo',".time().",'".$data4sql."')");
			$skip_cache = true;
		}

		// if the epoch in the db is more than 2 hours old, or the xml is less than 100 bytes, then regrab xml
		// Changed to 5 minutes while not in release. Change back for released version.
		//
		$skip_cache |= $amp_conf['MODULEADMIN_SKIP_CACHE'];
		$version = getversion();
		// we need to know the freepbx major version we have running (ie: 12.0.1 is 12.0)
		preg_match('/(\d+\.\d+)/',$version,$matches);
		$base_version = $matches[1];
		if((time() - $result['time']) > 300 || $skip_cache || strlen($data) < 100 ) {
			if ($override_xml) {
				$data = $this->get_url_contents($override_xml,"/modules-" . $base_version . ".xml");
			} else {
				// We pass in true to add options to accomodate future needs of things like php versions to get properly zended
				// tarballs of the same version for modules that are zended.
				//
				$data = $this->get_remote_contents("/modules-" . $base_version . ".xml", true);
				$beta = $this->get_remote_contents("/beta-" . $base_version . ".xml", true);
				$security = $this->get_remote_contents("/security-" . $base_version . ".xml", true);
				$previous = $this->get_remote_contents("/old-" . $base_version . ".xml", true);
			}

			$module_getonlinexml_error = ($data === false)?true:false;


			$old_xml = array();
			$got_new = false;
			if (!empty($data)) {
				// Compare the download to our current XML to see if anything changed for the notification system.
				//
				$sql = "SELECT data FROM module_xml WHERE id = 'xml'";
				$old_xml = sql($sql, "getOne");
				$got_new = true;
				// update the db with the new xml
				$data4sql = $db->escapeSimple($data);
				sql("REPLACE INTO module_xml (id,time,data) VALUES('xml',".time().",'".$data4sql."')");
			}
			if (!empty($beta)) {
				// Compare the download to our current XML to see if anything changed for the notification system.
				//
				$sql = "SELECT data FROM module_xml WHERE id = 'beta'";
				$old_beta_xml = sql($sql, "getOne");
				$got_new = true;
				// update the db with the new xml
				$data4sql = $db->escapeSimple($beta);
				sql("REPLACE INTO module_xml (id,time,data) VALUES('beta',".time().",'".$data4sql."')");
			}
			if (!empty($security)) {
				// Compare the download to our current XML to see if anything changed for the notification system.
				//
				$sql = "SELECT data FROM module_xml WHERE id = 'security'";
				$old_security_xml = sql($sql, "getOne");
				$got_new = true;
				// update the db with the new xml
				$data4sql = $db->escapeSimple($security);
				sql("REPLACE INTO module_xml (id,time,data) VALUES('security',".time().",'".$data4sql."')");
			}
			if (!empty($previous)) {
				// Compare the download to our current XML to see if anything changed for the notification system.
				//
				$sql = "SELECT data FROM module_xml WHERE id = 'previous'";
				$old_previous_xml = sql($sql, "getOne");
				$got_new = true;
				// update the db with the new xml
				$data4sql = $db->escapeSimple($previous);
				sql("REPLACE INTO module_xml (id,time,data) VALUES('previous',".time().",'".$data4sql."')");
			}
		}

		if (empty($data)) {
			// no data, probably couldn't connect online, and nothing cached
			return null;
		}

		$parser = new xml2ModuleArray($data);
		$xmlarray = $parser->parseAdvanced($data);
		
		$parser = new xml2ModuleArray($previous);
		$previousxml = $parser->parseAdvanced($previous);
		
		$parser = new xml2ModuleArray($beta);
		$betaxml = $parser->parseAdvanced($beta);
				
		$parser = new xml2ModuleArray($security);
		$securityxml = $parser->parseAdvanced($security);
		if(!empty($securityxml) && is_array($securityxml)) {
			foreach($securityxml['xml']['security']['issue'] as $item) {
				$this->security_array[$item['id']] = $item;
			}
		}

		//this is why xml to array is terrible on php, prime example here.
		$xmlarray['xml']['module'] = !isset($xmlarray['xml']['module']['rawname']) ? $xmlarray['xml']['module'] : array($xmlarray['xml']['module']);

		if ($got_new) {
			$this->update_notifications($old_xml, $xmlarray, ($old_xml == $data4sql));
		}

		if (is_array($sec_array) && !empty($xmlarray['xml']['security'])) {
			foreach ($xmlarray['xml']['security']['issue'] as $issue) {
				$sec_array[$issue['id']] = $issue;
			}
		}

		$exposures = $this->get_security($securityxml, $base_version);
		$this->update_security_notifications($exposures);

		if (isset($xmlarray['xml']['module'])) {

			if ($module != false) {
				foreach ($xmlarray['xml']['module'] as $mod) {
					if ($module == $mod['rawname']) {
						$releases = !empty($previousxml['xml']['modules'][$module]['releases']['module']) ? $previousxml['xml']['modules'][$module]['releases']['module'] : array();
						$mod['previous'] = isset($releases['rawname']) ? array($releases) : $releases;
						if(!empty($betaxml['xml']['modules'][$module])) {
							$betalist = isset($betaxml['xml']['modules'][$module]['rawname']) ? array($betaxml['xml']['modules'][$module]) : $betaxml['xml']['modules'][$module];
							foreach($betalist as $release) {
								$mod['releasetracks'][$release['releasetracktype']] = $release;
							}
						} else {
							$mod['releasetracks'] = array();
						}
						return $mod;
					}
				}
				return null;
			} else {
				$modules = array();
				foreach ($xmlarray['xml']['module'] as $mod) {
					$modules[$mod['rawname']] = $mod;
					if (isset($exposures[$mod['rawname']])) {
						$modules[$mod['rawname']]['vulnerabilities'] = $exposures[$mod['rawname']];
					}
					$releases = !empty($previousxml['xml']['modules'][$mod['rawname']]['releases']['module']) ? $previousxml['xml']['modules'][$mod['rawname']]['releases']['module'] : array();
					$modules[$mod['rawname']]['previous'] = isset($releases['rawname']) ? array($releases) : $releases;
					if(!empty($betaxml['xml']['modules'][$mod['rawname']])) {
						$betalist = isset($betaxml['xml']['modules'][$mod['rawname']]['rawname']) ? array($betaxml['xml']['modules'][$mod['rawname']]) : $betaxml['xml']['modules'][$mod['rawname']];
						foreach($betalist as $release) {
							$modules[$mod['rawname']]['releasetracks'][$release['releasetracktype']] = $release;
						}
					} else {
						$modules[$mod['rawname']]['releasetracks'] = array();
					}
				}
				return $modules;
			}
		}
		return null;
	}

	/** 
	 * Return any existing security vulnerabilities in currently installed modules if
	 * present in the xmlarray
	 *
	 * @param array the parsed xml array containing the security information
	 * @param string the current base version of freepbx if already available, or leave out
	 * @return array an array of vulnerable modules along with some vulnerability data
	 */
	function get_security($xmlarray, $base_version=null) {

		if ($base_version === null) {
			$version = getversion();
			// we need to know the freepbx major version we have running (ie: 2.1.2 is 2.1)
			preg_match('/(\d+\.\d+)/',$version,$matches);
			$base_version = $matches[1];
		}

		if (!empty($xmlarray['xml']['security'])) {
			$exposures = array();
			$modinfo = $this->getinfo();

			// check each listed vulnerability to see if there are vulnerable modules for this version
			//
			foreach ($xmlarray['xml']['security']['issue'] as $sinfo) {
				$vul = $sinfo['id'];
				if (!empty($sinfo['versions']['v' . $base_version])) {
					// If this version has vulnerabilities, check each vulnerable module to see if we have any
					//
					if (strtolower($sinfo['versions']['v' . $base_version]['vulnerable']) == 'yes' && !empty($sinfo['versions']['v' . $base_version]['fixes'])) foreach ($sinfo['versions']['v' . $base_version]['fixes'] as $rmod => $mver) {
						$rmod = trim($rmod);
						$mver = trim($mver);
						// If we have $rmod on our system then we will check if it is a vulnerable version
						//
						if (!empty($modinfo[trim($rmod)])) {
							$thisver = isset($modinfo[$rmod]['dbversion']) ? $modinfo[$rmod]['dbversion'] : $modinfo[$rmod]['version'];
							if (version_compare_freepbx($thisver, $mver, 'lt')) {
								if (!isset($exposures[$rmod])) {
									// First exposure we have seen that affects this module so hash it
									//
									$exposures[$rmod] = array('vul' => array($vul), 'minver' => $mver, 'curver' => $thisver);
								} else {
									// We already know this module is suceptible so check a higher version then already recorded
									// is need to secure this module
									//
									$exposures[$rmod]['vul'][] = $vul;
									if (version_compare_freepbx($mver, $exposures[$rmod]['minver'], 'gt')) {
										$exposures[$rmod]['minver'] = $mver;
									}
								}
							}
						}
					}
				}
			}
			return $exposures;
		}
	}


	/**  
	 * Determines if there are updates we don't already know about and posts to notification
	 * server about those updates.
	 *
	 * @param array $old_xml The old xml taken from the DB cache
	 * @param array $xmlarray The new XML taken from the online resource
	 * @param array $passive Whether to allow notification to be reset
	 *
	 */
	function update_notifications(&$old_xml, &$xmlarray, $passive) {
		global $db;

		$notifications =& notifications::create($db); 

		$reset_value = $passive ? 'PASSIVE' : false;
		$old_parser = new xml2ModuleArray($old_xml);
		$old_xmlarray = $old_parser->parseAdvanced($old_xml);

		$new_modules = array();
		if (count($xmlarray)) {
			foreach ($xmlarray['xml']['module'] as $mod) {
				$new_modules[$mod['rawname']] = $mod;
			}
		}
		$old_modules = array();
		if (count($old_xmlarray)) {
			foreach ($old_xmlarray['xml']['module'] as $mod) {
				$old_modules[$mod['rawname']] = $mod;
			}
		}

		// If keys (rawnames) are different then there are new modules, create a notification.
		// This will always be the case the first time it is run since the xml is empty.
		//
		$diff_modules = array_diff_assoc($new_modules, $old_modules);
		$cnt = count($diff_modules);
		if ($cnt) {
			$active_repos = $this->get_active_repos();
			$extext = _("The following new modules are available for download. Click delete icon on the right to remove this notice.")."<br />";
			foreach ($diff_modules as $mod) {
				// If it's a new module in a repo we are not interested in, then don't send a notification.
				if (isset($active_repos[$mod['repo']]) && $active_repos[$mod['repo']]) {
					$extext .= $mod['rawname']." (".$mod['version'].")<br />";
				} else {
					$cnt--;
				}
			}
			if ($cnt) {
				$notifications->add_notice('freepbx', 'NEWMODS', sprintf(_('%s New modules are available'),$cnt), $extext, '', $reset_value, true);
			}
		}

		// Now check if any of the installed modules need updating
		//
		$this->upgrade_notifications($new_modules, $reset_value);
	}

	/** 
	 * Compare installed (enabled or disabled) modules against the xml to generate or
	 * update the notification table of which modules have available updates. If the list
	 * is empty then delete the notification.
	 *
	 * @param array $new_modules New Module XML
	 * @param array $passive Whether to allow notification to be reset
	 */
	function upgrade_notifications(&$new_modules, $passive_value) {
		global $db;
		$notifications =& notifications::create($db); 

		$installed_status = array(MODULE_STATUS_ENABLED, MODULE_STATUS_DISABLED);
		$modules_local = $this->getinfo(false, $installed_status);

		$modules_upgradable = array();
		foreach (array_keys($modules_local) as $name) {
			if (isset($new_modules[$name])) {
				if (version_compare_freepbx($modules_local[$name]['version'], $new_modules[$name]['version']) < 0) {
					$modules_upgradable[] = array(
						'name' => $name,
						'local_version' => $modules_local[$name]['version'],
						'online_version' => $new_modules[$name]['version'],
					);
				}
			}
		}
		$cnt = count($modules_upgradable);
		if ($cnt) {
			if ($cnt == 1) {
				$text = _("There is 1 module available for online upgrade");
			} else {
				$text = sprintf(_("There are %s modules available for online upgrades"),$cnt);
			}
			$extext = "";
			foreach ($modules_upgradable as $mod) {
				$extext .= sprintf(_("%s (current: %s)"), $mod['name'].' '.$mod['online_version'], $mod['local_version'])."\n";
			}
			$notifications->add_update('freepbx', 'NEWUPDATES', $text, $extext, '', $passive_value);
		} else {
			$notifications->delete('freepbx', 'NEWUPDATES');
		}
	}

	/** 
	 * Updates the notification panel of any known vulnerable modules present
	 * on the system. Since these are security notifications, emails will be
	 * sent out informing of the issues if enabled.
	 *
	 * @param array $exposures vulnerability information array returned by module_get_security()
	 */
	function update_security_notifications($exposures) {
		global $db;
		$notifications =& notifications::create($db); 

		if (!empty($exposures)) {
			$cnt = count($exposures);
			if ($cnt == 1) {
				$text = _("There is 1 module vulnerable to security threats");
			} else {
				$text = sprintf(_("There are %s modules vulnerable to security threats"), $cnt);
			}
			$extext = "";
			foreach($exposures as $m => $vinfo) {
				$extext .= sprintf(
					_("%s (Cur v. %s) should be upgraded to v. %s to fix security issues: %s\n"), 
					$m, $vinfo['curver'], $vinfo['minver'], implode($vinfo['vul'],', ')
				);
			}
			$notifications->add_security('freepbx', 'VULNERABILITIES', $text, $extext, '');
		} else {
			$notifications->delete('freepbx', 'VULNERABILITIES');
		}
	}

	/** 
	 * Get Active Locally Set Repos
	 *
	 * @return array Array of Active Repos array("<reponame>" => 1)
	 */
	function get_active_repos() {
		global $active_repos;
		global $db;
		
		if (!isset($active_repos) || !$active_repos) {
			$repos = sql("SELECT `data` FROM `module_xml` WHERE `id` = 'repos_json'","getOne");
			if(!empty($repos)) {
				$repos = json_decode($repos,TRUE);
			} else {
				$repos_serialized = sql("SELECT `data` FROM `module_xml` WHERE `id` = 'repos_serialized'","getOne");
				$repos = unserialize($repos_serialized);
				$repos_json = $db->escapeSimple(json_encode($repos));
				sql("REPLACE INTO `module_xml` (`id`, `time`, `data`) VALUES ('repos_json', '".time()."','".$repos_json."')");
				sql("DELETE FROM `module_xml` WHERE `id` = 'repos_serialized'");				
			}
			if (isset($repos) && $repos) {
				$active_repos = $repos;
			} else {
				$active_repos = array('standard' => 1);
				$this->set_active_repo('standard',1);
			}
			return $active_repos;
		} else {
			return $active_repos;
		}
	}

	/** 
	 * Enable or disable an online repository
	 *
	 * @param string $repo The repository name
	 * @param int $active 1 for true 0 for false
	 */
	function set_active_repo($repo,$active=1) {
		global $db;
		$repos = $this->get_active_repos();
		if(!empty($active)) {
			$repos[$repo] = 1;
		} elseif(isset($repos[$repo])) {
			unset($repos[$repo]);
		}
		$repos_json = $db->escapeSimple(json_encode($repos));
		$o = sql("REPLACE INTO `module_xml` (`id`, `time`, `data`) VALUES ('repos_json', '".time()."','".$repos_json."')");
		return $o;
	}

	/** 
	 * Store available remote repositories
	 *
	 * @param array $repos Array of remote repositories
	 */
	function set_remote_repos($repos) {
		global $db;
		$old_remote_repos = $this->get_remote_repos();
		$active_repos = $this->get_active_repos();
		foreach($repos as $repo) {
			//If there is a new repo detected and it's not in our former list of remote repos
			//and it was not previously medled with locally then enable it automatically
			if(!in_array($repo,$old_remote_repos) && !isset($active_repos[$repo])) {
				$this->set_active_repo($repo,1);
			}
		}
		$repos_json = $db->escapeSimple(json_encode($repos));
		sql("REPLACE INTO `module_xml` (`id`, `time`, `data`) VALUES ('remote_repos_json', '".time()."','".$repos_json."')");
	}
	
	/** 
	 * Get the list of locally stored remote repository names
	 *
	 * @return array Array of remote repositories
	 */
	function get_remote_repos() {
		global $db;
		$repos = sql("SELECT `data` FROM `module_xml` WHERE `id` = 'remote_repos_json'","getOne");
		return !empty($repos) ? json_decode($repos,TRUE) : array();
	}
	
	function set_track($modulename,$track) {
		global $db;
		$sql = "SELECT data FROM module_xml WHERE id = 'track'";
		$track = sql($sql, "getOne");
		
		
		$track = !empty($track) ? json_decode($track,TRUE) : array();
		$track[$modulename] = $track;
		$track = json_encode($track);
		
		sql("REPLACE INTO module_xml (id,time,data) VALUES('track',".time().",'".$track."')");
	}
	
	function set_tracks($modules) {
		global $db;
		$sql = "SELECT data FROM module_xml WHERE id = 'track'";
		$track = sql($sql, "getOne");
		
		$track = !empty($track) ? json_decode($track,TRUE) : array();
		foreach($modules as $module => $t) {
			$track[$module] = $t;
		}
		$track = json_encode($track);
		
		sql("REPLACE INTO module_xml (id,time,data) VALUES('track',".time().",'".$track."')");
	}
	
	function get_track($modulename) {
		global $db;
		if(empty($this->module_tracks)) {
			$sql = "SELECT data FROM module_xml WHERE id = 'track'";
			$this->module_tracks = sql($sql, "getOne");
		}
		
		$this->module_tracks = !empty($this->module_tracks) ? json_decode($this->module_tracks,TRUE) : array();
		return !empty($this->module_tracks[$modulename]) ? $this->module_tracks[$modulename] : 'stable';
	}

	/** 
	 * Looks through the modules directory and modules database and returns all available
	 * information about one or all modules
	 *
	 * @param string $module (optional) The module name to query, or false for all module
	 * @param mixed $status (optional) The status(es) to show, using MODULE_STATUS_* constants. Can
	 *                either be one value, or an array of values.
	 */
	function getinfo($module = false, $status = false, $forceload = false) {

		global $amp_conf, $db;
		$modules = array();
	
		if ($module) {
			// get info on only one module
			$xml = $this->_readxml($module);
			if (!is_null($xml)) {
				$modules[$module] = $xml;
				// if status is anything else, it will be updated below when we read the db
				$modules[$module]['status'] = MODULE_STATUS_NOTINSTALLED;
			}
		
			// query to get just this one
			$sql = 'SELECT * FROM modules WHERE modulename = "'.$module.'"';
		} else {
			// create the modulelist so it is static and does not need to be recreated
			// in subsequent calls
			//
			$modulelist =& modulelist::create($db);
			if ($forceload) {
				$modulelist->invalidate();
			}
			if (!$modulelist->is_loaded()) {
				// initialize list with "builtin" module
				$module_list = array('builtin');

				// read modules dir for module names
				$dir = opendir($amp_conf['AMPWEBROOT'].'/admin/modules');
				while ($file = readdir($dir)) {
					if (($file != ".") && ($file != "..") && ($file != "CVS") && 
				    	($file != ".svn") && ($file != "_cache") && 
				    	is_dir($amp_conf['AMPWEBROOT'].'/admin/modules/'.$file)) {
						$module_list[] = $file;
					}
				}

				// read the xml for each
				foreach ($module_list as $file) {
					$xml = $this->_readxml($file);
					if (!is_null($xml)) {
						$modules[$file] = $xml;
						// if status is anything else, it will be updated below when we read the db
						$modules[$file]['status'] = MODULE_STATUS_NOTINSTALLED;
	          // I think this is the source of reading every module from a file. The assumption is all modules
	          // from the online repo will always have a repo defined but local ones may not so we need to define
	          // them here.

	          //TODO: should we have a master list of supported repos and validate against that, or do it dynamically
	          //      we do with other stuff
	          if (!isset($modules[$file]['repo']) || !$modules[$file]['repo']) {
	            $modules[$file]['repo'] = 'local';
	          }
					}
				}
				closedir($dir);

				// query to get everything
				$sql = 'SELECT * FROM modules';
			}
		}
		// determine details about this module from database
		// modulename should match the directory name
	
		if ($module || !$modulelist->is_loaded()) {
			$results = $db->getAll($sql,DB_FETCHMODE_ASSOC);
			if(DB::IsError($results)) {
				die_freepbx($sql."<br>\n".$results->getMessage());
			}
	
			if (is_array($results)) {
				foreach($results as $row) {
					if (isset($modules[ $row['modulename'] ])) {
						if ($row['enabled'] != 0) {
					
							// check if file and registered versions are the same
							// version_compare returns 0 if no difference
							if (version_compare_freepbx($row['version'], $modules[ $row['modulename'] ]['version']) == 0) {
								$modules[ $row['modulename'] ]['status'] = MODULE_STATUS_ENABLED;
							} else {
								$modules[ $row['modulename'] ]['status'] = MODULE_STATUS_NEEDUPGRADE;
							}
					
						} else {
							$modules[ $row['modulename'] ]['status'] = MODULE_STATUS_DISABLED;
						}
					} else {
						// no directory for this db entry
						$modules[ $row['modulename'] ]['status'] = MODULE_STATUS_BROKEN;
						$modules[ $row['modulename'] ]['repo'] = 'broken';
					}
					$modules[ $row['modulename'] ]['dbversion'] = $row['version'];
					$modules[ $row['modulename'] ]['track'] = $this->get_track($row['modulename']);
				}
			}

			// "builtin" module is always enabled
			$modules['builtin']['status'] = MODULE_STATUS_ENABLED;
		}
		
		if (!$module && !$modulelist->is_loaded()) {
			$modulelist->initialize($modules);
		}

		if ($status === false) {
			if (!$module) {
				return $modulelist->module_array;
			} else {
				return $modules;
			}
		} else {
			if (!$module) {
				$modules =  $modulelist->module_array;
			}
			if (!is_array($status)) {
				// make a one element array so we can use in_array below
				$status = array($status);
			}
			foreach (array_keys($modules) as $name) {
				if (!in_array($modules[$name]['status'], $status)) {
					// not found in the $status array, remove it
					unset($modules[$name]);
				}
			}
			return $modules;
		}
	}

	/** Check if a module meets dependencies. 
	 * @param  mixed  The name of the module, or the modulexml Array
	 * @return mixed  Returns true if dependencies are met, or an array 
	 *                containing a list of human-readable errors if not.
	 *                NOTE: you must use strict type checking (===) to test
	 *                for true, because  array() == true !
	 */
	function checkdepends($modulename) {

		// check if we were passed a modulexml array, or a string (name)
		// ensure $modulexml is the modules array, and $modulename is the name (as a string)
		if (is_array($modulename)) {
			$modulexml = $modulename;
			$modulename = $modulename['rawname'];
		} else {
			$modulexml = $this->getinfo($modulename);
			$modulexml = $modulexml[$modulename];
		}

		$errors = array();

		// special handling for engine
		$engine_dependency = false; // if we've found ANY engine dependencies to check
		$engine_matched = false; // if an engine dependency has matched
		$engine_errors = array(); // the error strings for engines

		if (isset($modulexml['depends'])) {
			foreach ($modulexml['depends'] as $type => $requirements) {
				// if only a single item, make it an array so we can use the same code as for multiple items
				// this is because if there is  <module>a</module><module>b</module>  we will get array('module' => array('a','b'))
				if (!is_array($requirements)) {
					$requirements = array($requirements);
				}

				foreach ($requirements as $value) {
					switch ($type) {
						case 'version':
							if (preg_match('/^(lt|le|gt|ge|==|=|eq|!=|ne)?\s*(\d*[beta|alpha|rc|RC]?\d+(\.[^\.]+)*)$/i', $value, $matches)) {
								// matches[1] = operator, [2] = version
								$installed_ver = getversion();
								$operator = (!empty($matches[1]) ? $matches[1] : 'ge'); // default to >=
								$compare_ver = $matches[2];
								if (version_compare_freepbx($installed_ver, $compare_ver, $operator) ) {
									// version is good
								} else {
									$errors[] = $this->_comparison_error_message('FreePBX', $compare_ver, $installed_ver, $operator);
								}
							}
						break;
						case 'phpversion':
							/* accepted formats
							<depends>
							<phpversion>5.1.0<phpversion>       TRUE: if php is >= 5.1.0
							<phpversion>gt 5.1.0<phpversion>    TRUE: if php is > 5.1.0
							</depends>
							*/
							if (preg_match('/^(lt|le|gt|ge|==|=|eq|!=|ne)?\s*(\d*[beta|alpha|rc|RC]?\d+(\.[^\.]+)*)$/i', $value, $matches)) {
								// matches[1] = operator, [2] = version
								$installed_ver = phpversion();
								$operator = (!empty($matches[1]) ? $matches[1] : 'ge'); // default to >=
								$compare_ver = $matches[2];
								if (version_compare($installed_ver, $compare_ver, $operator) ) {
									// php version is good
								} else {
									$errors[] = $this->_comparison_error_message('PHP', $compare_ver, $installed_ver, $operator);
								}
							} 
						break;
						case 'phpcomponent':
							/* accepted formats
							<depends>
							<phpcomponent>zlib<phpversion>        TRUE: if extension zlib is loaded
							<phpcomponent>zlib 1.2<phpversion>    TRUE: if extension zlib is loaded and >= 1.2
							<phpcomponent>zlib gt 1.2<phpversion> TRUE: if extension zlib is loaded and > 1.2	
							</depends>
							*/
							$phpcomponents = explode('||',$value);
							$newerrors = array();
							foreach($phpcomponents as $value) {
								if (preg_match('/^([a-z0-9_]+|Zend (Optimizer|Guard Loader))(\s+(lt|le|gt|ge|==|=|eq|!=|ne)?\s*(\d+(\.\d*[beta|alpha|rc|RC]*\d+)+))?$/i', $value, $matches)) {
									// matches[1] = extension name, [3]=comparison operator, [4] = version
									$compare_ver = isset($matches[4]) ? $matches[4] : '';
									if (extension_loaded($matches[1])) {
										if (empty($compare_ver)) {
											// extension is loaded and no version specified
										} else {
											if (($installed_ver = phpversion($matches[1])) != '') {
												$operator = (!empty($matches[3]) ? $matches[3] : 'ge'); // default to >=
												if (version_compare($installed_ver, $compare_ver, $operator) ) {
													// version is good
												} else {
													$newerrors[] = $this->_comparison_error_message("PHP Component ".$matches[1], $compare_ver, $installed_ver, $operator);
												}
											} else {
												$newerrors[] = $this->_comparison_error_message("PHP Component ".$matches[1], $compare_ver, "<no version info>", $operator);
											}
										}
									} else {
										if ($compare_version == '') {
											$newerrors[] = sprintf(_('PHP Component %s is required but missing from you PHP installation.'), $matches[1]);
										} else {
											$newerrors[] = sprintf(_('PHP Component %s version %s is required but missing from you PHP installation.'), $matches[1], $compare_version);
										}
									}
								}	
							}
							if (count($newerrors) == count($phpcomponents)) {
								$errors = array_merge($errors,$newerrors);
							}
						break;
						case 'module':
							// Modify to allow versions such as 2.3.0beta1.2
							if (preg_match('/^([a-z0-9_]+)(\s+(lt|le|gt|ge|==|=|eq|!=|ne)?\s*(\d+(\.\d*[beta|alpha|rc|RC]*\d+)+))?$/i', $value, $matches)) {
								// matches[1] = modulename, [3]=comparison operator, [4] = version
								$modules = $this->getinfo($matches[1]);
								if (isset($modules[$matches[1]])) {
									$mod = $modules[$matches[1]]['rawname'];
									$needed_module = "<strong>".(isset($modules[$matches[1]]['name'])?$modules[$matches[1]]['name']:$matches[1])."</strong>";
									switch ($modules[$matches[1]]['status'] ) {
										case MODULE_STATUS_ENABLED:
											if (!empty($matches[4])) {
												// also doing version checking
												$installed_ver = $modules[$matches[1]]['dbversion'];
												$compare_ver = $matches[4];
												$operator = (!empty($matches[3]) ? $matches[3] : 'ge'); // default to >=

												if (version_compare_freepbx($installed_ver, $compare_ver, $operator) ) {
													// version is good
												} else {
													$errors[$mod] = $this->_comparison_error_message($needed_module.' module', $compare_ver, $installed_ver, $operator);
												}
											}
										break;
										case MODULE_STATUS_BROKEN:
											$errors[$mod] = sprintf(_('Module %s is required, but yours is broken. You should reinstall it and try again.'), $needed_module);
										break;
										case MODULE_STATUS_DISABLED:
											$errors[$mod] = sprintf(_('Module %s is required, but yours is disabled.'), $needed_module);
										break;
										case MODULE_STATUS_NEEDUPGRADE:
											$errors[$mod] = sprintf(_('Module %s is required, but yours is disabled because it needs to be upgraded. Please upgrade %s first, and then try again.'), $needed_module, $needed_module);
										break;
										default:
										case MODULE_STATUS_NOTINSTALLED:
											$errors[$mod] = sprintf(_('Module %s is required, yours is not installed.'), $needed_module);
										break;
									}
								} else {
									$errors[$matches[1]] = sprintf(_('Module %s is required.'), $matches[1]);
								}
							}
						break;
						case 'file': // file exists
							// replace embedded amp_conf %VARIABLES% in string

							$file = $this->_ampconf_string_replace($value);

							if (!file_exists( $file )) {
								$errors[] = sprintf(_('File %s must exist.'), $file);
							}
						break;
						case 'engine':
							/****************************
							*  NOTE: there is special handling for this check. We want to "OR" conditions, instead of
							*        "AND"ing like the rest of them. 
							*/

							// we found at least one engine, so mark that we're matching this 
							$engine_dependency = true;

							if (preg_match('/^([a-z0-9_]+)(\s+(lt|le|gt|ge|==|=|eq|!=|ne)?\s*(\d+(\.[^\.]+)*))?$/i', $value, $matches)) {
								// matches[1] = engine, [3]=comparison operator, [4] = version
								$operator = (!empty($matches[3]) ? $matches[3] : 'ge'); // default to >=

								$engine = engine_getinfo();
								if (($engine['engine'] == $matches[1]) && (empty($matches[4]) || version_compare($engine['version'], $matches[4], $operator))) {
									$engine_matched = true;
								} else {
									// add it to the error messages
									if ($matches[4]) {
										// version specified
										$operator_friendly = str_replace(array('gt','ge','lt','le','eq','ne'), array('>','>=','<','<=','=','not ='), $operator);
										$engine_errors[] = $matches[1].' ('.$operator_friendly.' '.$matches[4].')';
									} else {
										// no version
										$engine_errors[] = $matches[1];
									}
								}
							}
						break;
					}
				}
			}

			// special handling for engine
			// if we've had at least one engine dependency check, and no engine dependencies matched, we have an error
			if ($engine_dependency && !$engine_matched) {

				$engineinfo = engine_getinfo();
				$yourengine = $engineinfo['engine'].' '.$engineinfo['version'];
				// print it nicely
				if (count($engine_errors) == 1) {
					$errors[] = sprintf(_('Requires engine %s, you have: %s'),$engine_errors[0],$yourengine);
				} else {
					$errors[] = sprintf(_('Requires one of the following engines: %s; you have: %s'),implode(', ', $engine_errors),$yourengine);
				}
			}
		}

		if (count($errors) > 0) {
			return $errors;
		} else {
			return true;
		}
	}

	function _comparison_error_message($module, $reqversion, $version, $operator) {
		switch ($operator) {
			case 'lt': case '<':
				return sprintf(_('A %s version below %s is required, you have %s'), $module, $reqversion, $version);
			break;
			case 'le': case '<=';
				return sprintf(_('%s version %s or below is required, you have %s'), $module, $reqversion, $version);
			break;
			case 'gt': case '>';
				return sprintf(_('A %s version newer than %s required, you have %s'), $module, $reqversion, $version);
			break;
			case 'ne': case '!=': case '<>':
				return sprintf(_('Your %s version (%s) is incompatible.'), $version, $reqversion);
			break;
			case 'eq': case '==': case '=': 
				return sprintf(_('Only %s version %s is compatible, you have %s'), $module, $reqversion, $version);
			break;
			default:
			case 'ge': case '>=':
				return sprintf(_('%s version %s or higher is required, you have %s'), $module, $reqversion, $version);
		}
	}

	/** Finds all the enabled modules that depend on a given module
	 * @param  mixed  The name of the module, or the modulexml Array
	 * @return array  Array containing the list of modules, or false if no dependencies
	 */
	function reversedepends($modulename) {
		// check if we were passed a modulexml array, or a string (name)
		// ensure $modulename is the name (as a string)
		if (is_array($modulename)) {
			$modulename = $modulename['rawname'];
		}
	
		$modules = $this->getinfo(false, MODULE_STATUS_ENABLED);
	
		$depends = array();
	
		foreach (array_keys($modules) as $name) {
			if (isset($modules[$name]['depends'])) {
				foreach ($modules[$name]['depends'] as $type => $requirements) {
					if ($type == 'module') {
						// if only a single item, make it an array so we can use the same code as for multiple items
						// this is because if there is  <module>a</module><module>b</module>  we will get array('module' => array('a','b'))
						if (!is_array($requirements)) {
							$requirements = array($requirements);
						}
					
						foreach ($requirements as $value) {
							if (preg_match('/^([a-z0-9_]+)(\s+(>=|>|=|<|<=|!=)?\s*(\d(\.\d)*))?$/i', $value, $matches)) {
								// matches[1] = modulename, [3]=comparison operator, [4] = version
							
								// note, we're not checking version here. Normally this function is used when
								// uninstalling a module, so it doesn't really matter anyways, and version
								// dependency should have already been checked when the module was installed
								if ($matches[1] == $modulename) {
									$depends[] = $name;
								}
							}
						}
					}
				}
			}
		}
	
		return (count($depends) > 0) ? $depends : false;
	}

	/** Enables a module
	 * @param string    The name of the module to enable
	 * @param bool      If true, skips status and dependency checks
	 * @return  mixed   True if succesful, array of error messages if not succesful
	 */
	function enable($modulename, $force = false) { // was enableModule
		$modules = $this->getinfo($modulename);
	
		if ($modules[$modulename]['status'] == MODULE_STATUS_ENABLED) {
			return array(_("Module ".$modulename." is already enabled"));
		}
	
		// doesn't make sense to skip this on $force - eg, we can't enable a non-installed or broken module
		if ($modules[$modulename]['status'] != MODULE_STATUS_DISABLED) {
			return array(_("Module ".$modulename." cannot be enabled"));
		}
	
		if (!$force) { 
			if (($errors = $this->checkdepends($modules[$modulename])) !== true) {
				return $errors;
			}
		}
	
		// disabled (but doesn't needupgrade or need install), and meets dependencies
		$this->_setenabled($modulename, true);
		needreload();
		return true;
	}

	/** Downloads the latest version of a module
	 * and extracts it to the directory
	 * @param string    The location of the module to install
	 * @param bool      If true, skips status and dependency checks
	 * @param string    The name of a callback function to call with progress updates.
	                    function($action, $params). Possible actions:
	                      getinfo: while downloading modules.xml
	                      downloading: while downloading file; params include 'read' and 'total'
	                      untar: before untarring
	                      done: when complete
	 * @return  mixed   True if succesful, array of error messages if not succesful
	 */

	// was fetchModule 
	function download($modulexml, $force = false, $progress_callback = null, $override_svn = false, $override_xml = false) { 
		global $amp_conf;

		if(!file_exists($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
			if(!mkdir($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
				$errors[] = sprintf(_("Could Not Create Cache Folder: %s"),$amp_conf['AMPWEBROOT']."/admin/modules/_cache");
				return $errors;
			}
		}
		
		if(empty($modulexml['rawname'])) {
			$errors[] = _("Retrieved Module XML Was Empty");
			return $errors;
		}
		$modulename = $modulexml['rawname'];

		if ($time_limit = ini_get('max_execution_time')) {
			set_time_limit($time_limit);
		}
	
		// size of download blocks to fread()
		// basically, this controls how often progress_callback is called
		$download_chunk_size = 12*1024;
	
		// invoke progress callback
		if (function_exists($progress_callback)) {
			$progress_callback('getinfo', array('module'=>$modulename));
		}
	
		$file = basename($modulexml['location']);
		$filename = $amp_conf['AMPWEBROOT']."/admin/modules/_cache/".$file;
		// if we're not forcing the download, and a file with the target name exists..
		if (!$force && file_exists($filename)) {
			// We might already have it! Let's check the MD5.
			if ((isset($modulexml['sha1sum']) && $modulexml['sha1sum'] == sha1_file($filename)) || (isset($modulexml['md5sum']) && $modulexml['md5sum'] == md5_file($filename))) {
				// Note, if there's no MD5 information, it will redownload
				// every time. Otherwise theres no way to avoid a corrupt
				// download
			
				// invoke progress callback
				if (function_exists($progress_callback)) {
					$progress_callback('untar', array('module'=>$modulename, 'size'=>filesize($filename)));
				}
			
				/* We will explode the tarball in the cache directory and then once successful, remove the old module before before
				 * moving the new one over. This way, things like removed files end up being removed instead of laying around
				 *
				 * TODO: save old module being replaced, if there is an old one.
				 */
				exec("rm -rf ".$amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename", $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not remove %s to install new version'), $amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'.$modulename));
				}
				exec("tar zxf ".escapeshellarg($filename)." -C ".escapeshellarg($amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'), $output, $exitcode);
				if ($exitcode != 0) {
					freepbx_log(FPBX_LOG_ERROR,sprintf(_("failed to open %s module archive into _cache directory."),$filename));
					return array(sprintf(_('Could not untar %s to %s'), $filename, $amp_conf['AMPWEBROOT'].'/admin/modules/_cache'));
				} else {
					// since untarring was successful, remvove the tarball so they do not accumulate
					if (unlink($filename) === false) {
						freepbx_log(FPBX_LOG_WARNING,sprintf(_("failed to delete %s from cache directory after opening module archive."),$filename));
					}
				}
				exec("rm -rf ".$amp_conf['AMPWEBROOT']."/admin/modules/$modulename", $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not remove old module %s to install new version'), $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename));
				}
				exec("mv ".$amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename ".$amp_conf['AMPWEBROOT']."/admin/modules/$modulename", $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not move %s to %s'), $amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename", $amp_conf['AMPWEBROOT'].'/admin/modules/'));
				}
			
				// invoke progress_callback
				if (function_exists($progress_callback)) {
					$progress_callback('done', array('module'=>$modulename));
				}
			
				return true;
			} else {
				unlink($filename);
			}
		}
	
		if (!($fp = @fopen($filename,"w"))) {
			return array(sprintf(_("Error opening %s for writing"), $filename));
		}

		if ($override_svn) {
			$url_list = array($override_svn.$modulexml['location']);
		} else {
			$urls = $this->generate_remote_urls("/modules/".$modulexml['location'], true);
			foreach($urls['mirrors'] as $url) {
				$url_list[] = $url.$urls['path'];
			}
		}
	
		// Check each URL until get_headers_assoc() returns something intelligible. We then use
		// that URL and hope the file is there, we won't check others.
		//
		$headers = false;
		foreach ($url_list as $u) {
			$headers = get_headers_assoc($u);
			if (!empty($headers)) {
				$url = $u;
				break;
			}
			freepbx_log(FPBX_LOG_ERROR,sprintf(_('Failed download module tarball from %s, server may be down'),$u));
		}
		if (!$headers || !$url) {
			return array(sprintf(_("Unable to connect to servers from URLs provided: %s"), implode(',',$url_list)));
		}
	
		// TODO: do we want to make more robust past this point:
		// At this point we have settled on a specific URL that we can reach, if the file isn't there we won't try
		// other servers to check for it. The assumption is that no backup server will have it either at this point
		// If we wanted to make this more robust we could go back and try other servers. This code is a bit tangled
		// so some better factoring might help.
		//
		$totalread = 0;
		// invoke progress_callback
		if (function_exists($progress_callback)) {
			$progress_callback('downloading', array('module'=>$modulename, 'read'=>$totalread, 'total'=>$headers['content-length']));
		}
		
		$streamopts = array(
			'http' => 
				array(
					'method' => "POST",
					'content' => !empty($urls['query']) ? $urls['query'] : ''
				)
		);

		$streamcontext = stream_context_create($streamopts);
	
		// Check MODULEADMINWGET first so we don't execute the fopen() if set
		//
		if ($amp_conf['MODULEADMINWGET'] || !$dp = @fopen($url,'r',false,$streamcontext)) {
			$p = (!empty($urls['query'])) ? "--post-data '".$urls['query']."'" : "";
			exec("wget --tries=1 --timeout=600 $p -O $filename $url 2> /dev/null", $filedata, $retcode);
			if ($retcode != 0) {
				return array(sprintf(_("Error opening %s for reading"), $url));
			} else {
				if (!$dp = @fopen($filename,'r')) {
					return array(sprintf(_("Error opening %s for reading"), $url));
				}
			}
		}
	
		$filedata = '';
		while (!feof($dp)) {
			$data = fread($dp, $download_chunk_size);
			$filedata .= $data;
			$totalread += strlen($data);
			if (function_exists($progress_callback)) {
				$progress_callback('downloading', array('module'=>$modulename, 'read'=>$totalread, 'total'=>$headers['content-length']));
			}
		}
		fwrite($fp,$filedata);
		fclose($dp);
		fclose($fp);
	
	
		if (is_readable($filename) !== TRUE ) {
			return array(sprintf(_('Unable to save %s'),$filename));
		}
	
		// Check the MD5 info against what's in the module's XML
		if (!isset($modulexml['md5sum']) || empty($modulexml['md5sum'])) {
			//echo "<div class=\"error\">"._("Unable to Locate Integrity information for")." {$filename} - "._("Continuing Anyway")."</div>";
		} else if ($modulexml['md5sum'] != md5 ($filedata)) {
			unlink($filename);
			return array(sprintf(_('File Integrity failed for %s - aborting'), $filename));
		}
		
		// Check the SHA1 info against what's in the module's XML
		if (!isset($modulexml['sha1sum']) || empty($modulexml['sha1sum'])) {
			//echo "<div class=\"error\">"._("Unable to Locate Integrity information for")." {$filename} - "._("Continuing Anyway")."</div>";
		} else if ($modulexml['sha1sum'] != sha1 ($filedata)) {
			unlink($filename);
			return array(sprintf(_('File Integrity failed for %s - aborting'), $filename));
		}
	
		// invoke progress callback
		if (function_exists($progress_callback)) {
			$progress_callback('untar', array('module'=>$modulename, 'size'=>filesize($filename)));
		}

		/* We will explode the tarball in the cache directory and then once successful, remove the old module before before
		 * moving the new one over. This way, things like removed files end up being removed instead of laying around
		 */
		exec("rm -rf ".$amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename", $output, $exitcode);
		if ($exitcode != 0) {
			return array(sprintf(_('Could not remove %s to install new version'), $amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'.$modulename));
		}
		exec("tar zxf ".escapeshellarg($filename)." -C ".escapeshellarg($amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'), $output, $exitcode);
		if ($exitcode != 0) {
	    freepbx_log(FPBX_LOG_ERROR,sprintf(_("failed to open %s module archive into _cache directory."),$filename));
			return array(sprintf(_('Could not untar %s to %s'), $filename, $amp_conf['AMPWEBROOT'].'/admin/modules/_cache'));
		} else {
	    // since untarring was successful, remvove the tarball so they do not accumulate
	    if (unlink($filename) === false) {
	      freepbx_log(FPBX_LOG_WARNING,sprintf(_("failed to delete %s from cache directory after opening module archive."),$filename));
	    }
	  }
		exec("rm -rf ".$amp_conf['AMPWEBROOT']."/admin/modules/$modulename", $output, $exitcode);
		if ($exitcode != 0) {
			return array(sprintf(_('Could not remove old module %s to install new version'), $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename));
		}
		exec("mv ".$amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename ".$amp_conf['AMPWEBROOT']."/admin/modules/$modulename", $output, $exitcode);
		if ($exitcode != 0) {
			return array(sprintf(_('Could not move %s to %s'), $amp_conf['AMPWEBROOT']."/admin/modules/_cache/$modulename", $amp_conf['AMPWEBROOT'].'/admin/modules/'));
		}

		// invoke progress_callback
		if (function_exists($progress_callback)) {
			$progress_callback('done', array('module'=>$modulename));
		}

		return true;
	}

	function handledownload($module_location, $progress_callback = null) { 
		global $amp_conf;
		
		if(!file_exists($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
			if(!mkdir($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
				$errors[] = sprintf(_("Could Not Create Cache Folder: %s"),$amp_conf['AMPWEBROOT']."/admin/modules/_cache");
				return $errors;
			}
		}

		if ($time_limit = ini_get('max_execution_time')) {
			set_time_limit($time_limit);
		}
	
		// size of download blocks to fread()
		// basically, this controls how often progress_callback is called
		$download_chunk_size = 12*1024;
	
		// invoke progress callback
		if (function_exists($progress_callback)) {
			$progress_callback('getinfo', array('module'=>$modulename));
		}
	
		$file = basename($module_location);
		$filename = $amp_conf['AMPWEBROOT']."/admin/modules/_cache/".$file;
	
		// Check each URL until get_headers_assoc() returns something intelligible. We then use
		// that URL and hope the file is there, we won't check others.
		$headers = get_headers_assoc($module_location);
		if (empty($headers)) {
			return array(sprintf(_('Failed download module tarball from %s, server may be down'),$module_location));
		}
	
		if (!($fp = @fopen($filename,"w"))) {
			return array(sprintf(_("Error opening %s for writing"), $filename));
		}
	
		// TODO: do we want to make more robust past this point:
		// At this point we have settled on a specific URL that we can reach, if the file isn't there we won't try
		// other servers to check for it. The assumption is that no backup server will have it either at this point
		// If we wanted to make this more robust we could go back and try other servers. This code is a bit tangled
		// so some better factoring might help.
		//
		$totalread = 0;
		// invoke progress_callback
		if (function_exists($progress_callback)) {
			$progress_callback('downloading', array('read'=>$totalread, 'total'=>$headers['content-length']));
		}
	
		// Check MODULEADMINWGET first so we don't execute the fopen() if set
		//
		if ($amp_conf['MODULEADMINWGET'] || !$dp = @fopen($module_location,'r')) {
			exec("wget --tries=1 --timeout=600 -O $filename $module_location 2> /dev/null", $filedata, $retcode);
			if ($retcode != 0) {
				return array(sprintf(_("Error opening %s for reading"), $url));
			} else {
				if (!$dp = @fopen($filename,'r')) {
					return array(sprintf(_("Error opening %s for reading"), $url));
				}
			}
		}
	
		$filedata = '';
		while (!feof($dp)) {
			$data = fread($dp, $download_chunk_size);
			$filedata .= $data;
			$totalread += strlen($data);
			if (function_exists($progress_callback)) {
				$progress_callback('downloading', array('read'=>$totalread, 'total'=>$headers['content-length']));
			}
		}
		fwrite($fp,$filedata);
		fclose($dp);
		fclose($fp);

		$errors = $this->_process_archive($filename);
		if(count($errors)) {
			return $errors;
		}
		
		return true;
	}

	function handleupload($uploaded_file) {
		global $amp_conf;
		$errors = array();
		if(!file_exists($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
			if(!mkdir($amp_conf['AMPWEBROOT']."/admin/modules/_cache")) {
				$errors[] = sprintf(_("Could Not Create Cache Folder: %s"),$amp_conf['AMPWEBROOT']."/admin/modules/_cache");
				return $errors;
			}
		}
		
		if(!empty($uploaded_file['error'])) {
			switch ($uploaded_file['error']) { 
				case UPLOAD_ERR_INI_SIZE: 
					$message = _("The uploaded file exceeds the upload_max_filesize directive in php.ini"); 
				break; 
				case UPLOAD_ERR_FORM_SIZE: 
					$message = _("The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form"); 
				break; 
				case UPLOAD_ERR_PARTIAL: 
					$message = _("The uploaded file was only partially uploaded"); 
				break; 
				case UPLOAD_ERR_NO_FILE: 
					$message = _("No file was uploaded"); 
				break; 
				case UPLOAD_ERR_NO_TMP_DIR: 
					$message = _("Missing a temporary folder"); 
				break; 
				case UPLOAD_ERR_CANT_WRITE: 
					$message = _("Failed to write file to disk"); 
				break; 
				case UPLOAD_ERR_EXTENSION: 
					$message = _("File upload stopped by extension"); 
				break; 
				default: 
					$message = _("Unknown upload error"); 
				break; 
			}
			return array($message);
		}
	
		if (!isset($uploaded_file['tmp_name']) || !file_exists($uploaded_file['tmp_name'])) {
			$errors[] = _("Error finding uploaded file - check your PHP and/or web server configuration");
			return $errors;
		}
	
		$filename = $amp_conf['AMPWEBROOT']."/admin/modules/_cache/".$uploaded_file['name'];
	
		move_uploaded_file($uploaded_file['tmp_name'], $filename);
	
		$errors = $this->_process_archive($filename);
		if(count($errors)) {
			return $errors;
		}
		// finally, module installation is successful
		return true;
	}
	
	function _process_archive($filename) {
		global $amp_conf;

		if (is_readable($filename) !== TRUE ) {
			return array(sprintf(_('Unable to save %s'),$filename));
		}
		
		// invoke progress callback
		if (function_exists($progress_callback)) {
			$progress_callback('untar', array('module'=>$modulename, 'size'=>filesize($filename)));
		}
		
		$temppath = $amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'.uniqid("upload");
		if (! @mkdir($temppath) ) {
			return array(sprintf(_("Error creating temporary directory: %s"), $temppath));
		}
		
		$extension = pathinfo($filename,PATHINFO_EXTENSION);
		switch(true) {
			case preg_match('/^(tar\.gz|tgz|tar)$/', $extension):
				exec("/usr/bin/env tar zxf ".escapeshellarg($filename)." -C ".escapeshellarg($temppath), $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not remove temp storage at %s'), $temppath));
				}
			break;
			case preg_match('/^(zip)$/', $extension):
				exec("/usr/bin/env unzip  ".escapeshellarg($filename)." -d ".escapeshellarg($temppath), $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not remove temp storage at %s'), $temppath));
				}
			break;
			case preg_match('/^(bz2|bz|tbz2|tbz)$/', $extension):
				exec("/usr/bin/env tar xjf ".escapeshellarg($filename)." -C ".escapeshellarg($temppath), $output, $exitcode);
				if ($exitcode != 0) {
					return array(sprintf(_('Could not remove temp storage at %s'), $temppath));
				}
			break;
			default:
				return array(sprintf(_('Unknown file format of %s for %s, supported formats: tar,tgz,tar.gz,zip,bzip'),$extension,basename($filename)));
			break;
		}
		
		// since untarring was successful, remvove the tarball so they do not accumulate
	    if (unlink($filename) === false) {
			freepbx_log(FPBX_LOG_WARNING,sprintf(_("failed to delete %s from cache directory after opening module archive."),$filename));
	    }
		
		if(!file_exists($temppath.'/module.xml')) {
			$dirs = glob($temppath.'/*',GLOB_ONLYDIR);
			if(count($dirs) > 1 || !count($dirs)) {
				return array(sprintf(_('Incorrect Number of Directories for %s'),$filename));
			}
			$archivepath = $dirs[0];
		} else {
			$archivepath = $temppath;
		}
		
		if(!file_exists($archivepath.'/module.xml')) {
			return array(sprintf(_('Missing module.xml in %s'),$filename));
		}
		
		$xml = simplexml_load_file($archivepath.'/module.xml');
		$modulename = (string)$xml->rawname;
		if(empty($modulename)) {
			return array(sprintf(_('Module Name is blank in %s'),$filename));
		}
		
		if(file_exists($amp_conf['AMPWEBROOT']."/admin/modules/".$modulename)) {
			exec("rm -rf ".$amp_conf['AMPWEBROOT']."/admin/modules/".$modulename, $output, $exitcode);
			if ($exitcode != 0) {
				return array(sprintf(_('Could not remove old module %s to install new version'), $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename));
			}
		}
		
		//we just deleted it, if we cant recreate it then we have some serious issues going on
		if (! @mkdir($amp_conf['AMPWEBROOT']."/admin/modules/".$modulename) ) {
			return array(sprintf(_("Error creating module directory: %s"), $amp_conf['AMPWEBROOT']."/admin/modules/".$modulename));
		}
		
		exec("cp -R ".$archivepath."/* ".$amp_conf['AMPWEBROOT']."/admin/modules/".$modulename."/", $output, $exitcode);
		if ($exitcode != 0) {
			exec("rm -Rf ".escapeshellarg($amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'.$time.'/'));
			if ($exitcode != 0) {
				return array(sprintf(_('Could not remove temp storage at %s'), $amp_conf['AMPWEBROOT'].'/admin/modules/_cache/'.$time));
			}
			return array(sprintf(_('Could not move %s to %s'), $amp_conf['AMPWEBROOT']."/admin/modules/_cache/".$time."/".$modulename, $amp_conf['AMPWEBROOT'].'/admin/modules/'));
		}
		
		exec("rm -rf $temppath", $output, $exitcode);
		if ($exitcode != 0) {
			return array(sprintf(_('Could not remove temporary location %s'), $temppath));
		}

		// invoke progress_callback
		if (function_exists($progress_callback)) {
			$progress_callback('done', array('module'=>$modulename));
		}
		return true;
	}

	/** Installs or upgrades a module from it's directory
	 * Checks dependencies, and enables
	 * @param string   The name of the module to install
	 * @param bool     If true, skips status and dependency checks
	 * @return mixed   True if succesful, array of error messages if not succesful
	 */
	function install($modulename, $force = false) {
		global $db, $amp_conf;

		if ($time_limit = ini_get('max_execution_time')) {
			set_time_limit($time_limit);
		}

		$modules = $this->getinfo($modulename);
	
		// make sure we have a directory, to begin with
		$dir = $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename;
		if (!is_dir($dir)) {
			return array(_("Cannot find module"));
		}
	
		// read the module.xml file
		$modules = $this->getinfo($modulename);
		if (!isset($modules[$modulename])) {
			return array(_("Could not read module.xml"));
		}
	
		// don't force this bit - we can't install a broken module (missing files) 
		if ($modules[$modulename]['status'] == MODULE_STATUS_BROKEN) {
			return array(_("Module ".$modules[$modulename]['rawname']." is broken and cannot be installed. You should try to download it again."));
		}
		
		if (!$force) {
	
			if (!in_array($modules[$modulename]['status'], array(MODULE_STATUS_NOTINSTALLED, MODULE_STATUS_NEEDUPGRADE))) {
				//return array(_("This module is already installed."));
				// This isn't really an error, we just exit
				return true;
			}
		
			// check dependencies
			if (is_array($errors = $this->checkdepends($modules[$modulename]))) {
				return $errors;
			}
		}

		// Check if another module wants this install to be rejected
		// The module must have a callback: [modulename]_module_install_check_callback() that takes
		// a single modules array from module_getinfo() about the module to be installed
		// and it must pass back boolean true if the installation can proceed, or a message
		// indicating why the installation must fail
		//
		$rejects = array();
		foreach (mod_func_iterator('module_install_check_callback', $modules) as $mod => $res) {
			if ($res !== true) {
				$rejects[] = $res;
			}
		}
		if (!empty($rejects)) {
			return $rejects;
		}
			
		// run the scripts
		if (!$this->_runscripts($modulename, 'install', $modules)) {
			return array(_("Failed to run installation scripts"));
		}
	
		if ($modules[$modulename]['status'] == MODULE_STATUS_NOTINSTALLED) {
			// customize INSERT query
			$sql = "INSERT INTO modules (modulename, version, enabled) values ('".$db->escapeSimple($modules[$modulename]['rawname'])."','".$db->escapeSimple($modules[$modulename]['version'])."', 1);";
		} else {
			// just need to update the version
			$sql = "UPDATE modules SET version='".$db->escapeSimple($modules[$modulename]['version'])."' WHERE modulename = '".$db->escapeSimple($modules[$modulename]['rawname'])."'";
		}
	
		// run query
		$results = $db->query($sql);
		if(DB::IsError($results)) {
			return array(sprintf(_("Error updating database. Command was: %s; error was: %s "), $sql, $results->getMessage()));
		}
	
		// module is now installed & enabled, invalidate the modulelist class since it is now stale
		$modulelist =& modulelist::create($db);
		$modulelist->invalidate();

		// edit the notification table to list any remaining upgrades available or clear
		// it if none are left. It requres a copy of the most recent module_xml to compare
		// against the installed modules.
		//
		$sql = 'SELECT data FROM module_xml WHERE id = "xml"';
		$data = sql($sql, "getOne");
		$parser = new xml2ModuleArray($data);
		$xmlarray = $parser->parseAdvanced($data);
		$new_modules = array();
		if (count($xmlarray)) {
			foreach ($xmlarray['xml']['module'] as $mod) {
				$new_modules[$mod['rawname']] = $mod;
			}
		}
		$this->upgrade_notifications($new_modules, 'PASSIVE');
		needreload();
	
		return true;
	}

	/** Disable a module, but reqmains installed
	 * @param string   The name of the module to disable
	 * @param bool     If true, skips status and dependency checks
	 * @return mixed   True if succesful, array of error messages if not succesful
	*/
	function disable($modulename, $force = false) { // was disableModule
		$modules = $this->getinfo($modulename);
		if (!isset($modules[$modulename])) {
			return array(_("Specified module not found"));
		}
	
		if (!$force) {
			if ($modules[$modulename]['status'] != MODULE_STATUS_ENABLED) {
				return array(_("Module not enabled: cannot disable"));
			}
		
			if ( ($depmods = $this->reversedepends($modulename)) !== false) {
				return array(_("Cannot disable: The following modules depend on this one: ").implode(',',$depmods));
			}
		}
	
		$this->_setenabled($modulename, false);
		needreload();
		return true;
	}

	/** Uninstall a module, but files remain
	 * @param string   The name of the module to install
	 * @param bool     If true, skips status and dependency checks
	 * @return mixed   True if succesful, array of error messages if not succesful
	 */
	function uninstall($modulename, $force = false) {
		global $db;
		global $amp_conf;
	
		$modules = $this->getinfo($modulename);
		if (!isset($modules[$modulename])) {
			return array(_("Specified module not found"));
		}
	
		if (!$force) {
			if ($modules[$modulename]['status'] == MODULE_STATUS_NOTINSTALLED) {
				return array(_("Module not installed: cannot uninstall"));
			}
		
			if ( ($depmods = $this->reversedepends($modulename)) !== false) {
				return array(_("Cannot disable: The following modules depend on this one: ").implode(',',$depmods));
			}
		}

		// Check if another module wants this uninstall to be rejected
		// The module must have a callback: [modulename]_module_uninstall_check_callbak() that takes
		// a single modules array from module_getinfo() about the module to be uninstalled
		// and it must pass back boolean true if the uninstall can proceed, or a message
		// indicating why the uninstall must fail
		//
		$rejects = array();
		foreach (mod_func_iterator('module_uninstall_check_callback', $modules) as $mod => $res) {
			if ($res !== true) {
				$rejects[] = $res;
			}
		}
		if (!empty($rejects)) {
			return $rejects;
		}
	
		$sql = "DELETE FROM modules WHERE modulename = '".$db->escapeSimple($modulename)."'";
		$results = $db->query($sql);
		if(DB::IsError($results)) {
			return array(_("Error updating database: ").$results->getMessage());
		}
	
		if (!$this->_runscripts($modulename, 'uninstall', $modules)) {
			return array(_("Failed to run un-installation scripts"));
		}

	  // Now make sure all feature codes are uninstalled in case the module has not already done it
	  //
	  require_once(dirname(__FILE__) . '/featurecodes.class.php'); //TODO: do we need this, now that we have bootstrap? -MB
	  featurecodes_delModuleFeatures($modulename);

	  $freepbx_conf =& freepbx_conf::create();
	  $freepbx_conf->remove_module_settings($modulename);
	  $mod_asset_dir = $amp_conf['AMPWEBROOT'] . "/admin/assets/" . $modulename;
	  if (is_link($mod_asset_dir)) {
	    @unlink($mod_asset_dir);
	  }
	
		needreload();
		return true;
	}

	/** Totally deletes a module
	 * @param string   The name of the module to install
	 * @param bool     If true, skips status and dependency checks
	 * @return mixed   True if succesful, array of error messages if not succesful
	 */
	function delete($modulename, $force = false) {
		global $amp_conf;
	
		$modules = $this->getinfo($modulename);
		if (!isset($modules[$modulename])) {
			return array(_("Specified module not found"));
		}
	
		if ($modules[$modulename]['status'] != MODULE_STATUS_NOTINSTALLED) {
			if (is_array($errors = $this->uninstall($modulename, $force))) {
				return $errors;
			}
		}
	
		// delete module directory
		//TODO : do this in pure php
		$dir = $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename;
		if (!is_dir($dir)) {
			return array(sprintf(_("Cannot delete directory %s"), $dir));
		}
		if (strpos($dir,"..") !== false) {
			die_freepbx("Security problem, denying delete");
		}
		exec("rm -r ".escapeshellarg($dir),$output, $exitcode);
		if ($exitcode != 0) {
			return array(sprintf(_("Error deleting directory %s (code %d)"), $dir, $exitcode));
		}
	
		// uninstall will have called needreload() if necessary
		return true;
	}

	/** Internal use only */	
	function _setenabled($modulename, $enabled) {
		global $db;
		$sql = 'UPDATE modules SET enabled = '.($enabled ? '1' : '0').' WHERE modulename = "'.$db->escapeSimple($modulename).'"';
		$results = $db->query($sql);
		if(DB::IsError($results)) {
			die_freepbx($sql."<br>\n".$results->getMessage());
		}
		$modulelist =& modulelist::create($db);
		$modulelist->invalidate();
	}

	function _readxml($modulename) {
		global $amp_conf;
		switch ($modulename) {
			case 'builtin': // special handling
				$dir = $amp_conf['AMPWEBROOT'];
				$xmlfile = $dir.'/admin/module-builtin.xml';
			break;
			default:
				$dir = $amp_conf['AMPWEBROOT'].'/admin/modules/'.$modulename;
				$xmlfile = $dir.'/module.xml';
			break;
		}

		if (file_exists($xmlfile)) {
	    ini_set('user_agent','Wget/1.10.2 (Red Hat modified)');
			$data = file_get_contents($xmlfile);
			//$parser = new xml2ModuleArray($data);
			//$xmlarray = $parser->parseModulesXML($data);
			$parser = new xml2Array($data);
			$xmlarray = $parser->data;
			if (isset($xmlarray['module'])) {
				// add a couple fields first
				$xmlarray['module']['name'] = str_replace("\n&\n","&",$xmlarray['module']['name']);
				$xmlarray['module']['displayname'] = $xmlarray['module']['name'];
				if (isset($xmlarray['module']['description'])) {
					$xmlarray['module']['description'] = trim(str_replace("\n","",$xmlarray['module']['description']));
				}
				if (isset($xmlarray['module']['methods'])) {
					$defpri = 300;
					$mod_meths = $xmlarray['module']['methods'];
					unset($xmlarray['module']['methods']);
					foreach ($mod_meths as $type => $methods) {
						if (is_array($methods)) {
							foreach ($methods as $i => $method) {
								$path = '/module/methods/' . $type . '/' .$i;
								$pri = isset($parser->attributes[$path]['pri'])
									? $parser->attributes[$path]['pri']
									: $defpri;
								$pri = ctype_digit($pri) && $pri > 0
									? $pri
									: $defpri;
								$xmlarray['module']['methods'][$type][$pri][] 
									= $method;
							}
						} else {
							$path = '/module/methods/' . $type;
							$pri = isset($parser->attributes[$path]['pri'])
								? $parser->attributes[$path]['pri']
								: $defpri;
							$pri = ctype_digit($pri) && $pri > 0
								? $pri
								: $defpri;
						
							$xmlarray['module']['methods'][$type][$pri][] 
								= $methods;
						}
					}
				}
				if (isset($xmlarray['module']['menuitems'])) {
				
					foreach ($xmlarray['module']['menuitems'] as $item=>$displayname) {
						$displayname = str_replace("\n&\n","&",$displayname);
						$xmlarray['module']['menuitems'][$item] = $displayname;
						$path = '/module/menuitems/'.$item;
					
						// find category
						if (isset($parser->attributes[$path]['category'])) {
							$category = str_replace("\n&\n","&",$parser->attributes[$path]['category']);
						} else if (isset($xmlarray['module']['category'])) {
							$category = str_replace("\n&\n","&",$xmlarray['module']['category']);
						} else {
							$category = 'Basic';
						}
					
						// find type
						if (isset($parser->attributes[$path]['type'])) {
							$type = $parser->attributes[$path]['type'];
						} else if (isset($xmlarray['module']['type'])) {
							$type = $xmlarray['module']['type'];
						} else {
							$type = 'setup';
						}
					
						// sort priority
						if (isset($parser->attributes[$path]['sort'])) {
							// limit to -10 to 10
							if ($parser->attributes[$path]['sort'] > 10) {
								$sort = 10;
							} else if ($parser->attributes[$path]['sort'] < -10) {
								$sort = -10;
							} else {
								$sort = $parser->attributes[$path]['sort'];
							}
						} else {
							$sort = 0;
						}

						// setup basic items array
						$xmlarray['module']['items'][$item] = array(
							'name' => $displayname,
							'type' => $type,
							'category' => $category,
							'sort' => $sort,
						);
					
						// add optional values:
						$optional_attribs = array(
							'href', // custom href
							'target', // custom target frame
							'display', // display= override
							'needsenginedb', // set to true if engine db access required (e.g. astman access)
							'needsenginerunning', // set to true if required to run
							'access', // set to all if all users should always have access
							'hidden', //keep hidden from the gui at all times - but accesable if you kknow how...
							'requires_auth', //option todisable auth check
							'beta', //option to display Beta warning on display page
						);
						foreach ($optional_attribs as $attrib) {
							if (isset($parser->attributes[$path][ $attrib ])) {
								$xmlarray['module']['items'][$item][ $attrib ] = $parser->attributes[$path][ $attrib ];
							}
						}
					}
				}
				return $xmlarray['module'];
			}
		}
		return null;
	}

	// This returns the version of a module
	function _getversion($modname) {
		global $db;

		$sql = "SELECT version FROM modules WHERE modulename = '".$db->escapeSimple($modname)."'";
		$results = $db->getRow($sql,DB_FETCHMODE_ASSOC);
		if (isset($results['version'])) 
			return $results['version'];
		else
			return null;
	}

	/** Include additional files requested in module.xml for install and uninstall
	 * @param array   The modulexml array
	 * @param string  The action to perform, either 'install' or 'uninstall'
	 * @return boolean   If the action was successful, currently TRUE so we don't prevent the install
	 */
	function _runscripts_include($modulexml, $type) {
		foreach($modulexml as $modulename => $items) {
			if (isset($items['fileinclude'][$type]) && !empty($items['fileinclude'][$type])) {
				foreach($items['fileinclude'][$type] as $key => $filename) {
					$ret = $this->_doinclude($moduledir.'/'.$filename, $modulename);
					if (!$ret) {
						freepbx_log(FPBX_LOG_WARNING,sprintf(_("failed to include %s during %s of the %s module."),$filename,$type,$modulename));		
					}
				}
			}
		}
		return true;
	}

	/** Run the module install/uninstall scripts
	 * @param string  The name of the module
	 * @param string  The action to perform, either 'install' or 'uninstall'
	 * @param array	  The modulexml array
	 * @return boolean  If the action was succesful
	 */
	function _runscripts($modulename, $type, $modulexml = false) {
		global $amp_conf;
		$db_engine = $amp_conf["AMPDBENGINE"];
	
		$moduledir = $amp_conf["AMPWEBROOT"]."/admin/modules/".$modulename;
		if (!is_dir($moduledir)) {
			return false;
		}
	
		switch ($type) { 
			case 'install':
				// install sql files
				$sqlfilename = "install.sql";
	      			$rc = true;
			
				if (is_file($moduledir.'/'.$sqlfilename)) {
					$rc = execSQL($moduledir.'/'.$sqlfilename);
				}
			
				//include additional files developer requested
				if ($modulexml !== false) {
					$this->_runscripts_include($modulexml, $type);
				}
	
				// then run .php scripts
				return ($this->_doinclude($moduledir.'/install.php', $modulename) && $rc);
			break;
			case 'uninstall':
				//include additional files developer requested
	                        if ($modulexml !== false) {
	                                $this->_runscripts_include($modulexml, $type);
	                        }

				// run uninstall .php scripts first
				$rc = $this->_doinclude($moduledir.'/uninstall.php', $modulename);
		
				$sqlfilename = "uninstall.sql";
			
				// then uninstall sql files 
				if (is_file($moduledir.'/'.$sqlfilename)) {
					return ($rc && execSQL($moduledir.'/'.$sqlfilename));
				} else {
	        			return $rc;
	      			}
			
			break;
			default:
				return false;
		}
	
		return true;
	}

	function _doinclude($filename, $modulename) {
		// we provide the following variables to the included file (as well as $filename and $modulename)
		global $db, $amp_conf, $asterisk_conf;
	
		if (file_exists($filename) && is_file($filename)) {
			return include_once($filename);
		} else {
	    return true;
	  }
	}

	/* module_get_annoucements()

		Get's any annoucments, security warnings, etc. that may be related to the current freepbx version. Also
		transmits a uniqueid to help track the number of installations using the online module admin system.
		The uniqueid used is completely anonymous and not trackable.
	*/
	function get_annoucements() {
		global $db;
		global $amp_conf;

		
		$announcement = $this->get_remote_contents("/version-".getversion().".html");

		return $announcement;
	}

	/* Assumes properly formated input, which is ok since
	   this is a private function and error checking is done
		 through proper regex scanning above

		 Returns: random md5 hash
	 */
	function _generate_random_id($type=null, $mac=null) {

		if (trim($mac) == "") {
			$id['uniqueid'] = md5(mt_rand());
		} else {
			// MD5 hash of the MAC so it is not identifiable
			//
			$id['uniqueid'] = md5($mac);
		}
		$id['type'] = $type;

		return $id;
	}

	/* _module_generate_unique_id

		The purpose of this function is to generate a unique id that will try
		and regenerate the same unique id on a system if called multiple
		times. The id is unique but is not in any way identifable so that
		privacy is not compromised.

		Returns:

		Array: ["uniqueid"] => unique_md5_hash
		       ["type"]     => type_passed_in
  
	*/
	function _generate_unique_id($type=null) {

		// Array of macs that require identification so we know these are not
		// 'real' systems. Either home setups or test environments
		//
		$ids = array('000C29' => 'vmware',
		             '000569' => 'vmware',
		             '00163E' => 'xensource'
		            ); 
		$mac_address = array();
		$chosen_mac = null;

		// TODO: put proper path in places for ifconfig, try various locations where it may be if
		//       non-0 return code.
		//
		exec('/sbin/ifconfig',$output, $return);

		if ($return != '0') {

			// OK try another path
			//
			exec('ifconfig',$output, $return);

			if ($return != '0') {
				// No seed available so return with random seed
				return $this->_generate_random_id($type);
			}
		}

		// parse the output of ifconfig to get list of MACs returned
		//
		foreach ($output as $str) {
			// make sure each line contains a valid MAC and IP address and then
			//
			if (preg_match("/([0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5})/", $str, $mac)) {
				$mac_address[] = strtoupper(preg_replace("/:/","",$mac[0]));
			}
		}

		if (trim($type) == "") {
			foreach ($mac_address as $mac) {
				$id = substr($mac,0,6);

				// If we care about this id, then choose it and set the type
				// we only choose the first one we see
				//
				if (array_key_exists($id,$ids)) {
					$chosen_mac = $mac;
					$type = $ids[$id];
					break;
				}
			}
		}

		// Now either we have a chosen_mac, we will use the first mac, or if something went wrong
		// and there is nothing in the array (couldn't find a mac) then we will make it purely random
		//
	  if ($type == "vmware" || $type == "xensource") {
			// vmware, xensource machines will have repeated macs so make random
			return $this->_generate_random_id($type);
		} else if ($chosen_mac != "") {
			return $this->_generate_random_id($type, $chosen_mac);
		} else if (isset($mac_address[0])) {
			return $this->_generate_random_id($type, $mac_address[0]);
		} else {
			return $this->_generate_random_id($type);
		}
	} 

	function run_notification_checks() {
		global $db;
		$modules_needup = $this->getinfo(false, MODULE_STATUS_NEEDUPGRADE);
		$modules_broken = $this->getinfo(false, MODULE_STATUS_BROKEN);
	
		$notifications =& notifications::create($db);
		if ($cnt = count($modules_needup)) {
			$text = (($cnt > 1) ? sprintf(_('You have %s disabled modules'), $cnt) : _('You have a disabled module'));
			$desc = _('The following modules are disabled because they need to be upgraded:')."\n".implode(", ",array_keys($modules_needup));
			$desc .= "\n\n"._('You should go to the module admin page to fix these.');
			$notifications->add_error('freepbx', 'modules_disabled', $text, $desc, '?type=tool&display=modules');
		} else {
			$notifications->delete('freepbx', 'modules_disabled');
		}
		if ($cnt = count($modules_broken)) {
			$text = (($cnt > 1) ? sprintf(_('You have %s broken modules'), $cnt) : _('You have a broken module'));
			$desc = _('The following modules are disabled because they are broken:')."\n".implode(", ",array_keys($modules_broken));
			$desc .= "\n\n"._('You should go to the module admin page to fix these.');
			$notifications->add_critical('freepbx', 'modules_broken', $text, $desc, '?type=tool&display=modules', false);
		} else {
			$notifications->delete('freepbx', 'modules_broken');
		}
	}

	/** Replaces variables in a string with the values from ampconf
	 * eg, "%AMPWEBROOT%/admin" => "/var/www/html/admin"
	 */
	function _ampconf_string_replace($string) {
		$freepbx_conf =& freepbx_conf::create();
	
		$target = array();
		$replace = array();
	
		foreach ($freepbx_conf->conf as $key=>$value) {
			$target[] = '%'.$key.'%';
			$replace[] = $value;
		}
	
		return str_replace($target, $replace, $string);
	}

	/* Get the brand from the pbx-brand file. This can be overriden
	 * by setting it first, used primarily to modify current brand
	 * to get a specific version that may exist online
	 */
	function _brandid($brand_override=false) {
		static $brand;
		if ($brand_override) {
			$brand = strtolower(trim($brand_override));
		}
		if (!empty($brand)) {
			return $brand;
		}

		$brandfile = "/etc/schmooze/pbx-brand";
		// TODO: log error if file is un-readable or blank?
		if (file_exists($brandfile)) {
			return strtolower(trim(file_get_contents($brandfile)));
		} else {
			return false;
		}
	}

	/* Get the deploymentid from zend.
	 */
	function _deploymentid() {
		if (function_exists('sysadmin_get_license')) {
			$lic = sysadmin_get_license();
			if (isset($lic['deploymentid'])) {
				return trim($lic['deploymentid']);
			}
		} 
		return false;
	}


	function _distro_id() {
		static $pbx_type;
		static $pbx_version;

		if (isset($pbx_type)) {
			return array('pbx_type' => $pbx_type, 'pbx_version' => $pbx_version);
		}
		
		exec('lscpu',$out,$ret);
		$cpu_arch = '';
		if(!$ret) {
			foreach($out as $line) {
				if(preg_match('/architecture:(.*)/i',$line,$matches)) {
					$cpu_arch = trim($matches[1]);
					break;
				}
			}			
		}
		
		// FreePBX Distro
		if (file_exists('/etc/schmooze/freepbxdistro-version')) {
			$pbx_type = 'freepbxdistro';
			$pbx_version = trim(file_get_contents('/etc/schmooze/freepbxdistro-version'));
		} elseif (file_exists('/etc/asterisk/freepbxdistro-version')) {
			$pbx_type = 'freepbxdistro';
			$pbx_version = trim(file_get_contents('/etc/asterisk/freepbxdistro-version'));
		} elseif (file_exists('/etc/schmooze/pbx-version')) {
			$pbx_type = 'freepbxdistro';
			$pbx_version = trim(file_get_contents('/etc/schmooze/pbx-version'));
		} elseif (file_exists('/etc/asterisk/pbx-version')) {
			$pbx_type = 'freepbxdistro';
			$pbx_version = trim(file_get_contents('/etc/asterisk/pbx-version'));

			// Trixbox
		} elseif (file_exists('/etc/trixbox/trixbox-version')) {
			$pbx_type = 'trixbox';
			$pbx_version = trim(file_get_contents('/etc/trixbox/trixbox-version'));

			// AsteriskNOW
		} elseif (file_exists('/etc/asterisknow-version')) {
			$pbx_type = 'asterisknow';
			$pbx_version = trim(file_get_contents('/etc/asterisknow-version'));

			// Elastix
		} elseif (is_dir('/usr/share/elastix') || file_exists('/usr/share/elastix/pre_elastix_version.info')) {
			$pbx_type = 'elastix';
			$pbx_version = '';
			if (class_exists('PDO') && file_exists('/var/www/db/settings.db')) {
				$elastix_db = new PDO('sqlite:/var/www/db/settings.db');
				$result = $elastix_db->query("SELECT value FROM settings WHERE key='elastix_version_release'");
				if ($result !== false) foreach ($result as $row) {
					if (isset($row['value'])) {
						$pbx_version = $row['value'];
						break;
					}
				}
			}
			if (!$pbx_version && file_exists('/usr/share/elastix/pre_elastix_version.info')) {
				$pbx_version = trim(file_get_contents('/usr/share/elastix/pre_elastix_version.info'));
			}
			if (!$pbx_version) {
				$pbx_version = '2.X+';
			}

			// PIAF
		} elseif (file_exists('/etc/pbx/.version') || file_exists('/etc/pbx/.color')) {
			$pbx_type = 'piaf';
			$pbx_version = '';
			if (file_exists('/etc/pbx/.version')) {
				$pbx_version = trim(file_get_contents('/etc/pbx/.version'));
			}
			if (file_exists('/etc/pbx/.color')) {
				$pbx_version .= '.' . trim(file_get_contents('/etc/pbx/.color'));
			}
			//this probably wont work correctly for his beaglebone stuff
			if(preg_match('/arm/i',$cpu_arch)) {
				$pbx_type = 'piaf-IncrediblePI';
			}
			if (!$pbx_version) {
				if (file_exists('/etc/pbx/ISO-Version')) {
					$pbx_ver_raw = trim(file_get_contents('/etc/pbx/ISO-Version'));
					$pbx_arr = explode('=',$pbx_ver_raw);
					$pbx_version = $pbx_arr[count($pbx_arr)-1];
				} else {
					$pbx_version = 'unknown';
				}
			}
			
			//raspbx
		} elseif(file_exists('/etc/raspbx/base_version') || file_exists('/etc/raspbx/installed_version')) {
			$pbx_type = 'raspbx';
			
			if (file_exists('/etc/raspbx/base_version')) {
				$pbx_version = trim(file_get_contents('/etc/raspbx/base_version'));
			}
			if (file_exists('/etc/raspbx/installed_version')) {
				$pbx_version .= '.' . trim(file_get_contents('/etc/raspbx/installed_version'));
			}
			if (!$pbx_version) {
				$pbx_version = 'unknown';
			}
			
			// Old PIAF or Fonica
		} elseif (file_exists('/etc/pbx/version') || file_exists('/etc/pbx/ISO-Version')) {
			$pbx_type = 'fonica';
			if (file_exists('/etc/pbx/ISO-Version')) {
				$pbx_ver_raw = trim(file_get_contents('/etc/pbx/ISO-Version'));
				$pbx_arr = explode('=',$pbx_ver_raw);
				$pbx_version = $pbx_arr[count($pbx_arr)-1];
				if (stristr($pbx_arr[0],'foncordiax') !== false) {
					$pbx_version .= '.pro';
				} else {
					$pbx_version = str_replace(' ','.',$pbx_version);
					if ($pbx_version != '1.0.standard') {
						$pbx_type = 'piaf';
					}
				}
			} else {
				$pbx_version = 'unknown';
			}
		} else {
			$pbx_type = 'unknown';
			$pbx_version = 'unknown';
		}
		return array('pbx_type' => $pbx_type, 'pbx_version' => $pbx_version);
	}
	
	function get_remote_contents($path, $add_options=false) {
		$mirrors = $this->generate_remote_urls($path,$add_options);
		foreach($mirrors['mirrors'] as $url) {
			$o = $this->url_get_contents($url,$mirrors['path'],'post',$mirrors['options']);
			if(!empty($o)) {
				return $o;
			}
		}
		return false;
	}
	
	/**
	 * function generate_module_repo_url
	 * short create array of full URLs to get a file from repo
	 * use this function to generate an array of URLs for all configured REPOs
	 * @author Philippe Lindheimer
	 *
	 * @pram string
	 * @returns string
	 */
	function generate_remote_urls($path, $add_options=false) {
		global $db;
		global $amp_conf;
		$urls = array();
		$options = array();

		if ($add_options) {
			$firstinstall=false;
			$type=null;

			$sql = "SELECT * FROM module_xml WHERE id = 'installid'";
			$result = sql($sql,'getRow',DB_FETCHMODE_ASSOC);

			// if not set so this is a first time install
			// get a new hash to account for first time install
			//
			if (!isset($result['data']) || trim($result['data']) == "") {
				$firstinstall=true;
				$install_hash = $this->_generate_unique_id();
				$installid = $install_hash['uniqueid'];
				$type = $install_hash['type'];

				// save the hash so we remeber this is a first time install
				//
				$data4sql = $db->escapeSimple($installid);
				sql("INSERT INTO module_xml (id,time,data) VALUES ('installid',".time().",'".$data4sql."')");
				$data4sql = $db->escapeSimple($type);
				sql("INSERT INTO module_xml (id,time,data) VALUES ('type',".time().",'".$data4sql."')");

				// Not a first time so save the queried hash and check if there is a type set
				//
			} else {
				$installid=$result['data'];
				$sql = "SELECT * FROM module_xml WHERE id = 'type'";
				$result = sql($sql,'getRow',DB_FETCHMODE_ASSOC);

				if (isset($result['data']) && trim($result['data']) != "") {
					$type=$result['data'];
				}
			}

			// Now we have the id and know if this is a firstime install so we can get the announcement
			//
			$options['installid'] = $installid;

			if (trim($type) != "") {
				$options['type'] = trim($type);
			}
			if ($firstinstall) {
				$options['firstinstall'] = 'yes';
			}
			
			//Now that we do everything in post format send back module versions
			$modules_local = $this->getinfo(false,false,true);
			foreach($modules_local as $m => $mod) {
				if($mod['status'] != MODULE_STATUS_BROKEN) {
					$options['modules'][$m]['version'] = $mod['version'];
					$options['modules'][$m]['status'] = $mod['status'];
					$options['modules'][$m]['rawname'] = $mod['rawname'];
				}
			}

			// We check specifically for false because evenif blank it means the file
			// was there so we want module.xml to do appropriate actions
			$brandid = $this->_brandid();
			if ($brandid !== false) {
				$options['brandid'] = $brandid;
			}

			$deploymentid = $this->_deploymentid();
			if ($deploymentid !== false) {
				$options['deploymentid'] = $deploymentid;
			}

			$engver=engine_getinfo();
			if ($engver['engine'] == 'asterisk' && trim($engver['engine']) != "") {
				$options['astver'] = $engver['version'];
			} else {
				$options['astver'] = $engver['raw'];
			}
			$options['phpver'] = phpversion();

			$distro_info = $this->_distro_id();
			$options['distro'] = $distro_info['pbx_type'];
			$options['distrover'] = $distro_info['pbx_version'];
			$options['pbxver'] = getversion();
			if (function_exists('core_users_list')) {
				$options['ucount'] = count(core_users_list());
			}

			// Other modules may need to add 'get' paramters to the call to the repo. Check and add them
			// here if we are adding paramters. The module should return an array of key/value pairs each of which
			// is to be appended to the GET parameters. The variable name will be prepended with the module name
			// when sent.
			//
			$repo_params = array();
			foreach (mod_func_iterator('module_repo_parameters_callback', $path) as $mod => $res) {
				if (is_array($res)) {
					foreach ($res as $p => $v) {
						$options[$mod.'_'.$p] = $v;
					}
				}
			}
		}
		
		$repos = explode(',', $amp_conf['MODULE_REPO']);
		return array('mirrors' => $repos, 'path' => $path, 'options' => $options, 'query' => http_build_query($options));
	}
	
	function url_get_contents($url,$request,$verb='get',$params=array()) {
		global $amp_conf;
		$action = strtolower($action);
		$contents = null;
		
		if(!$amp_conf['MODULEADMINWGET']) {
			$pest = new Pest($url);
			try{
				$contents = $pest->$verb($url.$request,$params);
				return $contents;
			} catch (Exception $e) {
				freepbx_log(FPBX_LOG_ERROR,sprintf(_('Failed to get remote file, error was:'),(string)$e->getMessage()));
			}
		}
		
		$fn = $url.$request;
		if(empty($contents)) {
			$fn2 = str_replace('&','\\&',$fn);
			$p = (!empty($params)) ? "--post-data '".http_build_query($params)."'" : "";
			exec("wget --tries=1 --timeout=30 $p -O - $fn2 2>> /dev/null", $data_arr, $retcode);
			if ($retcode) {
				// if server isn't available for some reason should return non-zero
				// so we return and we don't set the flag below
				freepbx_log(FPBX_LOG_ERROR,sprintf(_('Failed to get remote file, mirror site may be down: %s'),$fn));

				// We are here if contents were blank. It's possible that whatever we were getting were suppose to be blank
				// so we only auto set the WGET var if we received something so as to not false trigger. If there are issues
				// with content filters that this is designed to get around, we will eventually get a non-empty file which
				// will trigger this for now and the future.
			} elseif (!empty($data_arr) && !$amp_conf['MODULEADMINWGET']) {
				$freepbx_conf =& freepbx_conf::create();
				$freepbx_conf->set_conf_values(array('MODULEADMINWGET' => true),true);

				$nt =& notifications::create($db); 
				$text = sprintf(_("Forced %s to true"),'MODULEADMINWGET');
				$extext = sprintf(_("The system detected a problem trying to access external server data and changed internal setting %s (Use wget For Module Admin) to true, see the tooltip in Advanced Settings for more details."),'MODULEADMINWGET');
				$nt->add_warning('freepbx', 'MODULEADMINWGET', $text, $extext, '', false, true);
			}
			$contents = implode("\n",$data_arr);
			return $contents;
		}
	}
}