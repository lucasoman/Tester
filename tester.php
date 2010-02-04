<?php

/**
 * Class for performing unit tests.
 *
 * Example usage:
 *
 * $tester = Tester::singleton();
 * $tester->runTests(array(
 * 			array('tests/testOne.php',Tester::TESTRUN),
 * 			array('tests/testTwo.php',Tester::TESTSKIP),
 * 			));
 * 
 * $tester->setShowTests();
 * $tester->setShowTotals();
 * $tester->setShowFailing();
 * $tester->setShowPassing(false);
 * $tester->setShowContents();
 * print($tester->getResults());
 *
 * Get the latest from:
 * http://github.com/lucasoman/Tester
 *
 * @author Lucas Oman (me@lucasoman.com)
 */
class Tester {
	/**
	 * ends previous group and begins a new one with name
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param string label or name for the group
	 * @return null
	 */
	public function setGroup($label) {
		$this->closeBuffer();
		$this->_group = (!empty($this->_groupPrefix) ? $this->_groupPrefix.': ' : '').$label;
		if (!isset($this->_passes[$this->_group])) {
			$this->_passes[$this->_group] = array();
			$this->_failures[$this->_group] = array();
		}
		if (!$this->_silent) print("* {$this->_group}\n");
		$this->openBuffer();
	}

	/**
	 * sets the group's prefix name
	 * This is handy when repeating the same test files in different
	 * environments.
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param string prefix label
	 * @return null
	 */
	public function setGroupPrefix($prefix) {
		$this->_groupPrefix = $prefix;
	}

	/**
	 * execute a test
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param string note or name of the test
	 * @param mixed result of the test
	 * @param optional value that result should be
	 * @return null
	 */
	public function test($note,$is,$shouldBe=true) {
		$this->closeBuffer();
		$this->_testCount++;
		$return = null;
		// if it's a closure, then we're looking for an
		// exception. Otherwise, simple comparison.
		if ($is instanceof Closure) {
			try {
				$is();
			} catch (Exception $e) {
				if (get_class($e) == $shouldBe) {
					$return = true;
				} else {
					$return = false;
				}
			}
			if ($return === null) {
				$return = false;
			}
		} else {
			$return = ($is === $shouldBe);
		}
		$count = str_pad($this->_testCount,3,'0',STR_PAD_LEFT);
		if ($return) {
			$this->_passes[$this->_group][$this->_testCount] = $note;
			if (!$this->_silent) print($count.": ".$this->goodColorize('PASS')." - ".$note."\n");
		} else {
			$this->_failures[$this->_group][$this->_testCount] = array('note'=>$note,'is'=>$is,'shouldbe'=>$shouldBe);
			if (!$this->_silent) print($count.": ".$this->badColorize('FAIL')." - ".$note."\n");
		}
		$this->_endTime = microtime(true);
		$this->openBuffer();
	}

	/**
	 * gets a purdy report of test results
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @return null
	 */
	public function getResults() {
		$totals = $this->_showTotals;
		$failing = $this->_showFailing;
		$passing = $this->_showPassing;
		$contents = $this->_showContents;
		// returns a report on how the tests went
		$string = "\n--------------------------\nTesting Results\n--------------------------\n\n";
		if ($contents) {
			$string .= $this->getContents();
		}
		if ($failing) {
			list($failstring,$fails) = $this->listNotes($this->_failures);
			if ($fails > 0)
				$string .= "Failing tests\n-------------\n{$failstring}\n";
		}
		list($passstring,$passes) = $this->listNotes($this->_passes);
		if ($passing && $passes > 0) {
			$string .= "Passing tests\n-------------\n.{$passstring}.\n";
		}
		if ($totals) {
			$percent = number_format($passes / ($this->_testCount < 1 ? 1 : $this->_testCount) * 100,0);
			$time = number_format($this->_endTime - $this->_startTime,2);
			$string .= "{$passes}/{$this->_testCount} ({$percent}%) passed in {$time} seconds\n";
		}
		if (!empty($this->_logFile)) {
			$logFile = fopen($this->_logFile,$this->_logType);
			fwrite($logFile,$string);
			fclose($logFile);
		}
		$this->openBuffer();
		return $string;
	}

	/**
	 * sets the variables for the testing environment
	 * This environment is reset for each test file passed into tester.
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param array of vars; exported as varname=>varvalue
	 * @return null
	 */
	public function setEnv($vars) {
		// sets the environment for the tests
		// this array will be extracted for every test
		$this->_environment = $vars;
	}
	
	/**
	 * adds more environment vars to current environment
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param array of vars
	 * @return null
	 */
	public function addEnv($vars) {
		// adds vars to the environment
		$this->_environment = array_merge($this->_environment,$vars);
	}

	/**
	 * runs test files
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param array of test files. Format:
	 * array(
	 *   array('testfile.php',testoption),
	 *   array('testfile2.php',testoption),
	 *   ...
	 * )
	 * testoption is one of TESTRUN, TESTONLY, TESTSKIP
	 * @return null
	 */
	public function runTests($files) {
		$this->open();
		$this->_startTime = microtime(true);
		if (!$this->_silent) print("\nStarting tests...\n");
		$onlyExists = false;
		foreach ($files as $file) {
			if ($file[1] === self::TESTONLY) {
				$onlyExists = true;
				$this->runTest($file[0]);
			}
		}
		if ($onlyExists) {
			$this->close();
			return;
		}
		foreach ($files as $file) {
			if ($file[1] !== self::TESTSKIP) {
				$this->runTest($file[0]);
			}
		}
		$this->close();
	}

	/**
	 * The following three methods allow setting up a list of
	 * tests. Each result can then be pushed by other code and
	 * compared to its respective expected result.
	 *
	 * The usefulness and design of this feature are both
	 * questionable. Need to rethink.
	 */
	public function setList($name,$list) {
		$this->_lists[$name] = $list;
		$this->resetListCounter($name);
	}

	public function resetListCounter($name) {
		$this->_listCounters[$name] = 0;
	}

	public function testList($name,$value) {
		print("Is:\n".$value."\nShould be:\n".$this->_lists[$name][$this->_listCounters[$name]]);
		$this->test('List '.$name.': '.$this->_listCounters[$name],$value,$this->_lists[$name][$this->_listCounters[$name]]);
		$this->_listCounters[$name]++;
	}

	/**
	 * show tests as they're executed?
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param bool show tests?
	 * @return null
	 */
	public function setShowTests($show=true) {
		// set true if you don't want a test-by-test message
		$this->_silent = !((bool)$show);
	}

	/**
	 * show total passes/fails?
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param bool show totals?
	 * @return null
	 */
	public function setShowTotals($show=true) {
		$this->_showTotals = $show;
	}

	/**
	 * show all failing tests?
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param bool show failing?
	 * @return null
	 */
	public function setShowFailing($show=true) {
		$this->_showFailing = $show;
	}

	/**
	 * show all passing tests?
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param bool show passing?
	 * @return null
	 */
	public function setShowPassing($show=true) {
		$this->_showPassing = $show;
	}

	/**
	 * show data printed by executed code?
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param bool show data?
	 * @return null
	 */
	public function setShowContents($show=true) {
		$this->_showContents = $show;
	}

	/**
	 * should we show purdy colorful tests?
	 *
	 * @author Lucas Oman <lucas.oman@bookit.com>
	 * @param bool show color?
	 * @return null
	 */
	public function setShowColor($show=true) {
		$this->_setShowColor = $show;
	}

	/**
	 * sets logging options
	 *
	 * @author Lucas Oman <me@lucasoman.com>
	 * @param string filename
	 * @param bool overwrite if existing?
	 * @return null
	 */
	public function setLogFile($file,$overwrite=false) {
		$this->_logFile = $file;
		$this->_logType = ($overwrite ? 'w' : 'a');
	}

	private function open() {
		// opens testing
		// only necessary if you're printing debugging info in your tests
		$this->_open = true;
		ob_start();
	}

	private function close() {
		// closes testing
		// only necessary if you open()ed testing
		$this->closeBuffer();
		$this->_open = false;
	}

	private function runTest($file) {
		extract($this->_environment);
		$tester = $this;
		require($file);
	}

	private function __construct() {
		$this->setShowTests();
		$this->setShowTotals();
		$this->setShowFailing();
		$this->setShowPassing(false);
		$this->setShowContents();
		$this->setShowColor();
	}

	public static function singleton() {
		if (!self::$_singleton) {
			$class = __CLASS__;
			self::$_singleton = new $class;
		}
		return self::$_singleton;
	}

	private function closeBuffer() {
		if ($this->_open) {
			$contents = ob_get_contents();
			ob_end_clean();
			if (!empty($contents)) $this->_contents .= "\n------------\n\n$contents\n";
		}
	}

	private function openBuffer() {
		if ($this->_open) {
			ob_start();
		}
	}

	private function listNotes($groups) {
		$string = '';
		$total = 0;
		foreach ($groups as $group=>$notes) {
			if (count($notes) > 0) {
				$string .= "* {$group}\n";
				foreach ($notes as $num => $note) {
					if (is_array($note)) {
						$shouldbe = (string)$note['shouldbe'];
						if (strlen($shouldbe) > 20) {
							$shouldbe = substr($shouldbe,0,20).'...';
						}
						$is = (string)$note['is'];
						if (strlen($is) > 20) {
							$is = substr($is,0,20).'...';
						}
						$testNum = str_pad($num,3,'0',STR_PAD_LEFT);
						$string .= "  {$testNum}: {$note['note']}; should be: ".serialize($shouldbe)." is: ".serialize($is)."\n";
					} else {
						$string .= "  {$note}\n";
					}
					$total++;
				}
			}
		}
		return array($string,$total);
	}

	private function getContents() {
		return "Printed Data{$this->_contents}\n\n";
	}

	private function badColorize($text) {
		if ($this->_showColor) {
			$text = chr(27).'[31m'.$text.chr(27).'[0m';
		}
		return $text;
	}

	private function goodColorize($text) {
		if ($this->_showColor) {
			$text = chr(27).'[32m'.$text.chr(27).'[0m';
		}
		return $text;
	}

	private $_testCount = 0;
	private $_passes = array();
	private $_failures = array();
	private $_startTime;
	private $_endTime;
	private $_open = false;
	private $_contents = '';
	private $_environment = array();
	private $_testFiles = array();
	private $_lists = array();
	private $_listCounters = array();
	private $_silent;
	private $_showTotals;
	private $_showFailing;
	private $_showPassing;
	private $_showContents;
	private $_showColor;
	private $_group;
	private $_groupPrefix;
	private $_logFile;
	private $_logType;
	private static $_singleton;

	const TESTSKIP = 0;
	const TESTRUN = 1;
	const TESTONLY = 2;
}

?>
