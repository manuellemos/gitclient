<?php

/**
 * The file manager to handle the process of reading the repo files
 * Class FilesManager
 * @package GitClient
 */
class git_client_files_manager_class
{
    /**
     * @var string
     */
    public $error = '';
    /**
     * @var string
     */
    public $error_code = GIT_REPOSITORY_ERROR_NO_ERROR;
    /**
     * @var bool
     */
    public $debug = false;
    /**
     * @var bool
     */
	public $html_debug = true;
    /**
     * @var bool
     */
	public $log_debug = false;

    /**
     * Location of the cloned repo
     * @var string
     */
    private $location;
    /**
     * Absolute Location of the cloned repo
     * @var bool|string
     */
    private $absoluteLocation;

    /**
     * The directories that need to be ignored
     * @var array
     */
    private $blockedDirectories = [
        ".git"
    ];

    /**
     * @var callable
     */
    private $executeCommand;

    /**
     * git_client_files_manager_class constructor.
     * @param callable $executeCommand
     */
    public function __construct($executeCommand)
    {
        if (!is_callable($executeCommand)) {
            return $this->SetError('executeCommand must be callable', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE);
        }
        $this->executeCommand = $executeCommand;
        return true;
    }

	private function OutputDebug($message)
	{
		if($this->debug)
		{
			if($this->log_debug)
				error_log($message);
			else
			{
				$message .= "\n";
				if($this->html_debug)
					$message='<tt>'.str_replace("\n", "<br />\n", HtmlSpecialChars($message)).'</tt>';
				echo $message;
				flush();
			}
		}
	}

    public function SetError($error, $error_code = GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
    {
        $this->error_code = $error_code;
        $this->error = $error;
        return false;
    }

    /**
     * Set the location to the cloned repo
     * @param string $location
     */
    public function setLocation($location)
    {
        $this->location = $location;
        $this->absoluteLocation = realpath($location);
    }

    /**
     * Get the data for files in the repo
     * @return array
     */
    public function getRepoFiles($contents = true)
    {
        return $this->manageFiles(null, $contents);
    }

    /**
     * File Manager, that reads the files inside the cloned repo's checkedout branch
     * @param string|null $logFile
     * @return array
     */
    private function manageFiles($logFile = null, $contents)
    {

        if (!$this->location) {
            $this->SetError('location not set', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS);
            return null;
        }
        return $this->getFilesAndContents($this->location, $logFile, $contents);
    }

    /**
     * Get files and their contents from the repo
     * @param string $dir
     * @param null|string $fileName Use this to only get the files that match the name
     * @return array
     */
    private function getFilesAndContents($dir, $fileName = null, $contents = true)
    {
        $files = array();
        foreach (new DirectoryIterator($dir) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            } else if ($fileInfo->isDir() && !in_array($fileInfo->getFilename(), $this->blockedDirectories)) {
                $files = array_merge($files, $this->getFilesAndContents($fileInfo->getPathname(), $fileName, $contents));
            } else if ($fileInfo->isFile()) {
                if (IsSet($fileName) && substr($fileInfo->getPathname(), strlen($this->absoluteLocation) + 1) !== $fileName) {
                    continue;
                }
                $file = $this->prepareFileInfo($fileInfo, $contents);
                if(!IsSet($file))
					return null;
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Prepare file data for a single file
     * @param DirectoryIterator $fileInfo
     * @return array
     */
    private function prepareFileInfo(DirectoryIterator $fileInfo, $contents)
    {
        $fullPath = realpath($relativePath = $fileInfo->getPathname());
        if (substr($fullPath, 0, strlen($this->absoluteLocation)) == $this->absoluteLocation)
            $relativePath = substr($fullPath, strlen($this->absoluteLocation) + 1);
        $getVersion = call_user_func_array($this->executeCommand, ["hash-object ".escapeshellarg($fullPath)]);
        if ($getVersion['exitCode'] !== 0)
        {
            $this->SetError('Unable to get file version', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE);
            return null;
        }
        $version = $getVersion['output'];
        $file = array(
            'Version' => $version,
            'Name' => $fileInfo->getFilename(),
            'PathName' => $fileInfo->getPathname(),
            'File' => $fullPath,
            'RelativeFile' => $relativePath,
		);
		if($contents)
		{
			$data = file_get_contents($file['PathName']);
			$file['Size'] = strlen($data);
			$file['Data'] = $data;
        }
        return $file;
    }

    /**
     * Get the data for the log files in the repo
     * @param string $logFile
     * @return array
     */
    public function getLogFiles($logFile, $contents = true)
    {
        return $this->manageFiles($logFile, $contents);
    }
};

/**
 * The main class for this "library"
 * Class GitClient
 * @package git_program_client_class
 */
class git_program_client_class
{
    /**
     * @var string
     */
    public $error = '';
    /**
     * @var string
     */
    public $error_code = GIT_REPOSITORY_ERROR_NO_ERROR;
    /**
     * @var bool
     */
    public $debug = false;
    /**
     * @var bool
     */
	public $html_debug = true;
    /**
     * @var bool
     */
	public $log_debug = false;
    /**
     * The base directory where the cloned git repositories will be stored temporarily
     * Change this according to your need
     * @var string
     */
    public $temporary_directory = "/tmp";
    /**
     * URL of the remote repository
     * @var string
     */
    private $repository;
    /**
     * Branch to checkout
     * @var string
     */
    private $branch = "master";
    /**
     * Path to git cli client
     * @var string
     */
    private $client = "git";
    /**
     * Exact location of the cloned repository
     * @var string
     */
    private $tmpLocation;
    /**
     * Contains the status of whether we are connected to the repository or not
     * @var bool
     */
    private $connected;

    /**
     * The file manager to handle the process of reading the repository files
     * @var FilesManager
     */
    private $files_manager;

    /**
     * Contains the data for files in the repository
     * @var array
     */
    private $repository_files;

    /**
     * Count of checked out files
     * @var array
     */
    private $checkout_file = 0;

    /**
     * Contains the data for log files in the repository
     * @var array
     */
    private $logFiles;
    /**
     * Contains an array with the next log file in the repository
     * @var array
     */
    private $next_log_file;
    /**
     * Hash of the revision to get the file log
     * @var string
     */
	private $log_revision = '';
    /**
     * Timestamp lower limitr of the revision to get the file log
     * @var integer
     */
	private $log_newer_date = 0;
    /**
     * Valid characters accepted in repository file hash
     * @var string
     */
	private $hexdec = '0123456789abcdef';

	private function OutputDebug($message)
	{
		if($this->debug)
		{
			if($this->log_debug)
				error_log($message);
			else
			{
				$message .= "\n";
				if($this->html_debug)
					$message='<tt>'.str_replace("\n", "<br />\n", HtmlSpecialChars($message)).'</tt>';
				echo $message;
				flush();
			}
		}
	}

    private function SetError($error, $error_code = GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
    {
        $this->error_code = $error_code;
        $this->error = $error;
        return false;
    }

    public function __construct()
    {
        $this->files_manager = new git_client_files_manager_class([$this, 'execCommandForClient']);
        $this->files_manager->debug = $this->debug;
        $this->files_manager->log_debug = $this->log_debug;
        $this->files_manager->html_debug = $this->html_debug;
    }

    /**
     * Check if the provided repo is valid or not
     * @param array $config
     * @param int $error_code
     * @return bool
     */
    public function validate($config, &$error_code)
    {
		$repository = $config['Repository'];
		$this->OutputDebug('Validating repository: '.$repository);
        $data = $this->execCommandForClient("ls-remote ".escapeshellarg($repository));
        $error_code = $data['exitCode'];
        $this->error = $error_code !== 0 ? $error_code : null;
        return $data['exitCode'] === 0;
    }

    /**
     * Used to execute shell commands
     * @param string $action
     * @return array
     */
    public function execCommandForClient($action)
    {
        $command = "cd \"{$this->tmpLocation}\" && {$this->client} {$action} 2>&1";
		$this->OutputDebug('Executing Git command: '.$command);
        if (!($pipe = popen($command, 'r'))) {
            return ['output' => '', 'exitCode' => -3, 'error' => 'it was not possible to start the git command'];
        }
        for ($output = ''; !feof($pipe);) {
            if (!($data = fread($pipe, 8000))) {
                if (feof($pipe))
                    break;
                pclose($pipe);
                return ['output' => '', 'exitCode' => -2, 'error' => 'it was not possible to read the git command output'];
            }
            $output .= $data;
        }
        $error = '';
        $code = pclose($pipe);
        if ($code === -1) {
            $e = error_get_last();
            $error = $e['message'];
        }
        return ['output' => $output, 'exitCode' => $code, 'error' => $error];
    }

    /**
     * Establish a connection with the remote repo and download its contents
     * @param $arguments
     * @return bool
     */
    public function connect($arguments)
    {
        if (!IsSet($arguments['Repository'])) {
            return ($this->SetError('it was not specified the repository URL', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));
        }

        if (!preg_match('/^https?:\\/\\/(([^:@]+)?(:([^@]+))?@)?([^:\\/]+)(:[^\\/]+)?\\/(.*)$/', $arguments['Repository'], $m)) {
            return ($this->SetError('it was not specified a valid repository', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));
        }
        $this->repository = $arguments['Repository'];

        if (!$this->generateTmpLocation()) {
            return false;
        }
		$this->OutputDebug('Connecting to repository '.$this->repository);
        $clone = $this->cloneRepo();
        if ($clone['exitCode'] !== 0) {
            return ($this->SetError('Unable to access the remote repository', GIT_REPOSITORY_ERROR_CANNOT_CONNECT));
        }
        $this->files_manager->setLocation($this->tmpLocation);
        $this->connected = true;
        return true;
    }

    /**
     * Generate the location for where the repo will be stored, temporarily
     */
    private function generateTmpLocation()
    {
        $CloneDirectoryName = preg_replace("/[^0-9a-zA-Z]/m", "", $this->repository);
        if (($path = tempnam(strlen($this->temporary_directory) ? $this->temporary_directory : sys_get_temp_dir(), $CloneDirectoryName)) === false)
            return ($this->SetError('it was not possible to setup a temporary directory to retrieve the repository'));
        if (file_exists($path))
            unlink($path);
        if (!mkdir($path))
            return ($this->SetError('it was not possible to create the temporary directory to retrieve the repository'));
        $this->tmpLocation = $path;
        return true;
    }

    /**
     * Clone the Repo in the generated tmp location
     * @return array
     */
    private function cloneRepo()
    {
        return $this->execCommandForClient("clone ".escapeshellarg($this->repository)."  ".escapeshellarg($this->tmpLocation));
    }

    /**
     * Disconnect form the repo by deleting the clone
     * @return bool
     */
    public function disconnect()
    {
        if (!$this->connected) {
            return ($this->SetError('Not connected to any repository', GIT_REPOSITORY_ERROR_CANNOT_CONNECT));
        }

        if (!$this->tmpLocation) {
            return ($this->SetError('No cloned repository found'));
        }

        if (!$this->deleteClonedRepoContent($this->tmpLocation)) {
            return ($this->SetError('Unable to delete the cloned repository'));
        }
        $this->connected = false;
        return true;
    }

    /**
     * Delete the contents of the cloned repo
     * @param string $dirname
     * @return bool
     */
    private function deleteClonedRepoContent($dirname)
    {
        $dir_handle = null;
        if (is_dir($dirname)) {
            $dir_handle = opendir($dirname);
        }
        if (!$dir_handle) {
            return false;
        }
        while ($file = readdir($dir_handle)) {
            if ($file != "." && $file != "..") {
                if (!is_dir($dirname . "/" . $file)) {
                    chmod($dirname . "/" . $file, 0777);
                    unlink($dirname . "/" . $file);
                } else {
                    $this->deleteClonedRepoContent($dirname . '/' . $file);
                }
            }
        }
        closedir($dir_handle);
        rmdir($dirname);
        return true;
    }

    /**
     * Checkout the branch provided in the config
     * @param array $config
     * @return bool
     */
    public function Checkout($config)
    {
		$this->OutputDebug('Checkout...');
        $clone = $this->execCommandForClient("checkout ".escapeshellarg($this->branch));
        if ($clone['exitCode'] !== 0) {
            return ($this->SetError("Unable to checkout the branch {$this->branch}. " . $clone['exitCode'], GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT));
        }
        $repository_files = $this->files_manager->getRepoFiles(false);
        if (!IsSet($repository_files))
			return $this->SetError($this->files_manager->error, $this->files_manager->error_code);
		$this->OutputDebug('Checked out '.count($repository_files).' files.');
        $this->repository_files = $repository_files;
        $this->checkout_file = 0;
        return true;
    }

    /**
     * Get files from the repo
     * @param $arguments
     * @param $file
     * @param $no_more_files
     * @return bool
     */
    public function GetNextFile($arguments, &$file, &$no_more_files)
    {
		$get_file_data = (!IsSet($arguments['GetFileData']) || $arguments['GetFileData']);
		$get_file_modes = (!IsSet($arguments['GetFileModes']) || $arguments['GetFileModes']);
        $no_more_files = ($this->checkout_file >= count($this->repository_files));
        if(!$no_more_files)
        {
			$file = current($this->repository_files);
			if(IsSet($file))
			{
				++$this->checkout_file;
				$this->OutputDebug('Get next checked out file '.$this->checkout_file.' of '.count($this->repository_files));
				if(!IsSet($file['PathName']))
					return $this->SetError('it was not possible to retrieve the repository file '.$file['PathName']);
				if($get_file_data)
				{
					if(($data = file_get_contents($file['PathName'])) === false)
						return $this->SetError('it was not possible to read the repository file contents'.$file['PathName']);
					$file['Size'] = strlen($data);
					$file['Data'] = $data;
				}
			}
			next($this->repository_files);
		}
        return true;
    }

    /**
     * Get the data for the log files and store it
     * @param $arguments
     * @return bool
     */
    public function Log($arguments)
    {
		if(!IsSet($arguments['File']))
			return($this->SetError('retrieving the log of directories is not yet supported'));
		$file = $arguments['File'];
		if(IsSet($arguments['Module']))
			$module = $arguments['Module'];
		else
			return($this->SetError('it was not specified a valid directory to get the log'));
		$this->OutputDebug('Log '.$file);
		$this->log_revision = '';
		$this->log_newer_date = 0;
		if(IsSet($arguments['Revision']))
		{
			if(strlen($this->log_revision = $arguments['Revision']) != 40
			|| strspn($this->log_revision, $this->hexdec) != 40)
				return($this->SetError('it was not specified a valid log revision'));
		}
		elseif(IsSet($arguments['NewerThan']))
		{
			if(GetType($this->log_newer_date = strtotime($arguments['NewerThan'])) != 'integer')
				return($this->SetError('it was not specified a valid newer than time'));
		}
        $log_files = $this->files_manager->getLogFiles($file, false);
        if (!IsSet($log_files))
            return $this->SetError($this->files_manager->error, $this->files_manager->error_code);
        $this->logFiles = $log_files;
        $this->next_log_file = current($this->logFiles);
        return true;
    }

    /**
     * Traverses through the log files and returns(via reference) one at a time
     * @param $config
     * @param $file
     * @param $no_more_files
     * @return bool
     */
    public function GetNextLogFile($config, &$file, &$no_more_files)
    {
        $no_more_files = ($this->next_log_file === false);
        if($no_more_files)
        {
			$file = null;
			return true;
        }
        $log_file = current($this->logFiles);
		
		$this->OutputDebug('Getting the log for file: '.$log_file['RelativeFile']);
        $log = $this->execCommandForClient("log ".escapeshellarg($log_file['RelativeFile']));
        if($log['exitCode'] !== 0)
			return $this->SetError('it was not possible to get the file log of file '.$log_file['RelativeFile']);
        $lines = explode("\n", $log['output']);
        $revisions = array();
        for($line = 0; $line < count($lines);)
        {
			if(!preg_match('/^commit ([0-9a-f]+)$/', $lines[$line], $m))
				return $this->SetError('the log file response did not return a valid commit hash value for file '.$log_file['RelativeFile']);
			$hash = $m[1];
			$r = array();
			for(++$line ; $line < count($lines) && $lines[$line] !== ''; ++$line)
			{
				if(!preg_match('/^([^:]+): (.+)$/', $lines[$line], $m))
					return $this->SetError('the log file response did not return a valid header value for file '.$log_file['RelativeFile']." ".$line.' "'.$lines[$line].'" ');
				switch($property = strtolower($m[1]))
				{
					case 'author':
						$r[$property] = $m[2];
						break;
					case 'date':
						$r[$property] = gmstrftime('%Y-%m-%d %H:%M:%S +0000', strtotime($m[2]));
						break;
				}
			}
			if($lines[$line] === '')
				++$line;
			$log = '';
			for(; $line < count($lines) && $lines[$line] !== ''; ++$line)
				$log .= trim($lines[$line])."\n";
			$r['Log'] = $log;
			if(strlen($this->log_revision))
				$add_revision = ($this->log_revision === $hash);
			elseif($this->log_newer_date)
				$add_revision = (IsSet($r['date']) && $this->log_newer_date < intval(strtotime($r['date'])));
			else
				$add_revision = true;
			if($add_revision)
				$revisions[$hash] = $r;
			if($lines[$line] === '')
				++$line;
		}
		$file = array(
			'Properties'=>array(
				'description'=>'',
				'Work file'=>basename($log_file['RelativeFile'])
			),
			'Revisions'=>$revisions
		);
        $this->next_log_file = next($this->logFiles);
        return true;
    }

}
