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
            $this->SetError('executeCommand must be callable', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE);
        }
        $this->executeCommand = $executeCommand;
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
                if ($fileName && $fileInfo->getFilename() !== $fileName) {
                    continue;
                }
                $files[] = $this->prepareFileInfo($fileInfo, $contents);
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
        $fullPath = realpath($r = $relativePath = $fileInfo->getPathname());
        if (substr($fullPath, 0, strlen($this->absoluteLocation)) == $this->absoluteLocation)
            $relativePath = substr($fullPath, strlen($this->absoluteLocation) + 1);
        $getVersion = call_user_func_array($this->executeCommand, ["hash-object {$fullPath}"]);
        if ($getVersion['exitCode'] !== 0) {
            $this->SetError('Unable to get file version', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE);
        }
        $version = $getVersion['output'];
        $file = array(
            'Version' => $version,
            'Name' => $fileInfo->getFilename(),
            'PathName' => $fileInfo->getPathname(),
            'File' => $fullPath,
            'RelativeFile' => $relativePath,
            'Fuck'=>__LINE__.' '.print_r(array($this->absoluteLocation, $r, $relativePath), 1)
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
     * The base directory where the cloned git repos will be stored temporarily
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
     * Exact location of the cloned repo
     * @var string
     */
    private $tmpLocation;
    /**
     * Contains the status of whether we are connected to the repo or not
     * @var bool
     */
    private $connected;

    /**
     * The file manager to handle the process of reading the repo files
     * @var FilesManager
     */
    private $files_manager;

    /**
     * Contains the data for files in the repo
     * @var array
     */
    private $repoFiles;

    /**
     * Contains the data for log files in the repo
     * @var array
     */
    private $logFiles;

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
        $action = "ls-remote {$repository}";
        $data = $this->execCommandForClient($action);
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
        $action = "clone {$this->repository} {$this->tmpLocation}";
        return $this->execCommandForClient($action);
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
        $clone = $this->execCommandForClient("checkout {$this->branch}");
        if ($clone['exitCode'] !== 0) {
            return ($this->SetError("Unable to checkout the branch {$this->branch}. " . $clone['exitCode'], GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT));
        }
        $repository_files = $this->files_manager->getRepoFiles(false);
        if (!IsSet($repository_files))
			return $this->SetError($this->files_manager->error, $this->files_manager->error_code);
		$this->OutputDebug('Checked out '.count($repository_files).' files.');
        $this->repoFiles = $repository_files;
        return true;
    }

    /**
     * Get files from the repo
     * @param $config
     * @param $file
     * @param $no_more_files
     * @return bool
     */
    public function GetNextFile($config, &$file, &$no_more_files)
    {
		$this->OutputDebug('Get next checked out file.');
        $file = current($this->repoFiles);
        if(IsSet($file))
        {
			if(!IsSet($file['PathName']))
			{
//				$this->OutputDebug(print_r($file, 1));
				return $this->SetError('it was not possible to retrieve the repository file '.print_r($file, 1));
			}
			else
			{
				if(($data = file_get_contents($file['PathName'])) === false)
				{
					return $this->SetError('it was not possible to read the repository file contents'.print_r($file, 1));
				}
				$file['Size'] = strlen($data);
				$file['Data'] = $data;
			}
        }
        next($this->repoFiles);
        $no_more_files = (key($this->repoFiles) === null);
        return true;
    }

    /**
     * Get the data for the log files and store it
     * @param $config
     * @return bool
     */
    public function Log($config)
    {
        if (!IsSet($config['File']))
            return ($this->SetError('Log File not set'));
		$file = $config['File'];
		$this->OutputDebug('Get the log of file: '.$file);
        $log_files = $this->files_manager->getLogFiles($file, true);
        if (!IsSet($log_files))
            return $this->SetError($this->files_manager->error, $this->files_manager->error_code);
        $this->logFiles = $log_files;
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
        $file = current($this->logFiles);
        next($this->logFiles);
        $no_more_files = (key($this->logFiles) === null);
        return true;
    }

}
