<?php
namespace Media;
use mm\Mime\Type;
/**
 * FreePBX multi-audio convert engine
 * This class will determine the best converting engine to use
 * for cross converting audio files
 */
class Media {
	private $track;
	private $extension;
	private $mime;
	private $driver;
	private $tempDir;
	private $drivers = array();
	public $image;

	public function __construct($filename) {
		$this->loadTrack($filename);
		$tempDir = sys_get_temp_dir();
		$this->tempDir = !empty($tempDir) ? $tempDir : "/tmp";
	}
	/**
	 * Cast the track to a string
	 *
	 * @return type
	 */
	public function __toString() {
		return $this->track;
	}

	/**
	 * Load a track for processing
	 * @param  string $track The full path to the track
	 */
	public function loadTrack($track) {
		if(empty($track)) {
			throw new \Exception("A track must be supplied");
		}
		if(!file_exists($track)) {
			throw new \Exception("Track [$track] not found");
		}
		if(!is_readable($track)) {
			throw new \Exception("Track [$track] not readable");
		}
		$this->track = $track;

		Type::config('magic', array(
			'adapter' => 'Freedesktop',
			'file' => dirname(__DIR__).'/resources/magic.db'
		));
		Type::config('glob', array(
			'adapter' => 'Freedesktop',
			'file' => dirname(__DIR__).'/resources/glob.db'
		));
		$this->extension = Type::guessExtension($this->track);
		if(empty($this->extension) || $this->extension == "bin") {
			$parts = pathinfo($this->track);
			$this->extension = $parts['extension'];
		}
		$this->mime = Type::guessType($this->track);
	}

	/**
	 * Get all known drivers
	 * @return array Array of driver names
	 */
	private function getDrivers() {
		if(!empty($this->drivers)) {
			return $this->drivers;
		}
		foreach(glob(__DIR__."/Driver/Drivers/*.php") as $file) {
			$this->drivers[] = basename($file,".php");
		}
		return $this->drivers;
	}

	/**
	 * Get all supported formats
	 * @return array Array of supported audio formats
	 */
	public static function getSupportedFormats() {
		$formats = array(
			"out" => array(),
			"in" => array()
		);
		if(Driver\Drivers\AsteriskShell::installed()) {
			$formats = Driver\Drivers\AsteriskShell::supportedCodecs($formats);
		}
		if(Driver\Drivers\SoxShell::installed()) {
			$formats = Driver\Drivers\SoxShell::supportedCodecs($formats);
		}
		if(Driver\Drivers\Mpg123Shell::installed()) {
			$formats = Driver\Drivers\Mpg123Shell::supportedCodecs($formats);
		}
		if(Driver\Drivers\FfmpegShell::installed()) {
			$formats = Driver\Drivers\FfmpegShell::supportedCodecs($formats);
		}
		if(Driver\Drivers\LameShell::installed()) {
			$formats = Driver\Drivers\LameShell::supportedCodecs($formats);
		}
		return $formats;
	}

	/**
	 * Convert the track using the best possible means
	 * @param  string $filename The new filename
	 * @return object           New Media Object
	 */
	public function convert($newFilename) {
		$intermediary = $this->createIntermediaries();

		//generate wav form png
		if(isset($this->image)) {
			$waveform = new \Jasny\Audio\Waveform($intermediary['wav']['path'], array("width" => 700));
			$waveform->save("png",$this->image);
		}

		$extension = Type::guessExtension($newFilename);
		$parts = pathinfo($newFilename);
		if(empty($extension) || $extension == "bin") {
			$extension = $parts['extension'];
		}
		$mime = Type::guessType($newFilename);
		//generate final file
		foreach($this->getDrivers() as $driver) {
			$class = "Media\\Driver\\Drivers\\".$driver;
			if($driver == "AsteriskShell") {
				$i = $intermediary['sln'];
			} else {
				$i = $intermediary['wav'];
			}
			if($class::installed() && $class::isCodecSupported($extension,"out")) {
				$driver = new $class($i['path'],$i['extension'],$i['mime']);
				$driver->convert($newFilename,$extension,$mime);
				break;
			}
		}
		if(!empty($intermediary['wav']['path']) && file_exists($intermediary['wav']['path'])) {
			unlink($intermediary['wav']['path']);
		}
		if(!empty($intermediary['sln']['path']) && file_exists($intermediary['sln']['path'])) {
			unlink($intermediary['sln']['path']);
		}
		unset($intermediary);

		return file_exists($newFilename);
	}

	/**
	 * Convert the track using the best possible means
	 * @param  string $filename The new filename
	 * @return object           New Media Object
	 */
	public function convertMultiple($newFilename,$codecs=array()) {
		if(empty($codecs)) {
			return false;
		}

		$intermediary = $this->createIntermediaries();
		//generate wav form png
		if(isset($this->image)) {
			$waveform = new \Jasny\Audio\Waveform($intermediary['wav']['path'], array("width" => 700));
			$waveform->save("png",$this->image);
		}

		//generate final file
		foreach($codecs as $codec) {
			$parts = pathinfo($newFilename);
			$base = dirname($newFilename);
			$file = $base."/".$parts['filename'].".".$codec;
			$extension = Type::guessExtension($file);
			if(empty($extension) || $extension == "bin") {
				$extension = $codec;
			}
			$mime = Type::guessType($file);
			foreach($this->getDrivers() as $driver) {
				$class = "Media\\Driver\\Drivers\\".$driver;
				if($driver == "AsteriskShell") {
					$i = $intermediary['sln'];
				} else {
					$i = $intermediary['wav'];
				}
				if($class::installed() && $class::isCodecSupported($extension,"out")) {
					$driver = new $class($i['path'],$i['extension'],$i['mime']);
					$driver->convert($file,$extension,$mime);
					break;
				}
			}
		}
		if(!empty($intermediary['wav']['path']) && file_exists($intermediary['wav']['path'])) {
			unlink($intermediary['wav']['path']);
		}
		if(!empty($intermediary['sln']['path']) && file_exists($intermediary['sln']['path'])) {
			unlink($intermediary['sln']['path']);
		}
		unset($intermediary);

		return file_exists($newFilename);
	}

	private function createIntermediaries() {
		//generate intermediary file
		$ts = time().rand(0,1000);

		$soxClass = "Media\\Driver\\Drivers\\SoxShell";
		if(!$soxClass::installed()) {
			throw new \Exception("Sox needs to be installed");
		}

		//Convert everything to 48k, so we upscale and downscale
		//This is on purpose.
		//Transform into a wav file
		foreach($this->getDrivers() as $driver) {
			if($this->extension == "wav" && $driver == "AsteriskShell") {
				continue; //just dont allow it
			}
			$class = "Media\\Driver\\Drivers\\".$driver;
			if($class::installed() && $class::isCodecSupported($this->extension,"in")) {
				$d = new $class($this->track,$this->extension,$this->mime,48000,1,16);
				$d->convert($this->tempDir."/temp.".$ts.".wav","wav","audio/x-wav");
				$intermediary['wav']['path'] = $this->tempDir."/temp.".$ts.".wav";
				$intermediary['wav']['extension'] = "wav";
				$intermediary['wav']['mime'] = "audio/x-wav";
				break;
			}
		}
		if(!file_exists($this->track)) {
			throw new \Exception("Unable to find an intermediay converter");
		}

		//Now transform into a raw audio file
		$d = new $soxClass($intermediary['wav']['path'],$intermediary['wav']['extension'],$intermediary['wav']['mime'],48000,1,16);
		$d->convert($this->tempDir."/temp.".$ts.".sln48","sln48","audio/x-raw");
		$intermediary['sln']['path'] = $this->tempDir."/temp.".$ts.".sln48";
		$intermediary['sln']['extension'] = "sln48";
		$intermediary['sln']['mime'] = "audio/x-raw";

		if(empty($intermediary)) {
			throw new \Exception("No Driver found for ".$this->extension);
		}
		if(!file_exists($intermediary['wav']['path']) || !file_exists($intermediary['sln']['path'])) {
			throw new \Exception("Intermediary files could not be created");
		}
		return $intermediary;
	}
}