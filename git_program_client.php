<?php

/**
 * The file manager to handle the process of reading the repo files
 * Class FilesManager
 * @package GitClient
 */
class git_client_files_manager_class
{
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
    public function getRepoFiles()
    {
        return $this->manageFiles();
    }

    /**
     * File Manager, that reads the files inside the cloned repo's checkedout branch
     * @param string|null $logFile
     * @return array
     */
    private function manageFiles($logFile = null)
    {

        if (!$this->location) {
			$this->SetError('location not set', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS);
			return null;
        }
        return $this->getFilesAndContents($this->location, $logFile);
    }

    /**
     * Get files and their contents from the repo
     * @param string $dir
     * @param null|string $fileName Use this to only get the files that match the name
     * @return array
     */
    private function getFilesAndContents($dir, $fileName = null)
    {
        $files = [];
        foreach (new DirectoryIterator($dir) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            } else if ($fileInfo->isDir() && !in_array($fileInfo->getFilename(), $this->blockedDirectories)) {
                $files[] = array_merge($files, $this->getFilesAndContents($fileInfo->getPathname()));
            } else if ($fileInfo->isFile()) {
                if ($fileName && $fileInfo->getFilename() !== $fileName) {
                    continue;
                }
                $files[] = $this->prepareFileInfo($fileInfo);
            }
        }

        return $files;
    }

    /**
     * Prepare file data for a single file
     * @param DirectoryIterator $fileInfo
     * @return array
     */
    private function prepareFileInfo(DirectoryIterator $fileInfo)
    {
        $fullPath = realpath($fileInfo->getPathname());
        $relativePath = $fileInfo->getPathname();
        if (substr($fullPath, 0, strlen($this->absoluteLocation)) == $this->absoluteLocation) {
            $relativePath = substr($fullPath, strlen($this->absoluteLocation));
        }

        $data = file_get_contents($fileInfo->getPathname());

        return [
            'Name' => $fileInfo->getFilename(),
            'PathName' => $fileInfo->getPathname(),
            'File' => realpath($fileInfo->getPathname()),
            'RelativeFile' => $relativePath,
            'Size' => strlen($data),
            'Data' => $data
        ];

    }

    /**
     * Get the data for the log files in the repo
     * @param string $logFile
     * @return array
     */
    public function getLogFiles($logFile)
    {
        return $this->manageFiles($logFile);
    }

};

/**
 * The main class for this "library"
 * Class GitClient
 * @package GitClient
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
     * The base directory where the cloned git repos will be stored temporarily
     * Change this according to your need
     * @var string
     */
    public $tmpDirectory = "";
    /**
     * Contains the status of whether we are connected to the repo or not
     * @var bool
     */
    private $connected;

    /**
     * The file manager to handle the process of reading the repo files
     * @var FilesManager
     */
    private $filesManager;

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

    public function __construct()
    {
        $this->filesManager = new git_client_files_manager_class();
    }

	Function SetError($error, $error_code = GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
	{
		$this->error_code = $error_code;
		$this->error = $error;
		return false;
	}

    /**
     * Check if the provided repo is valid or not
     * @param array $config
     * @param int $error_code
     * @return bool
     */
    public function validate($config, &$error_code)
    {
        $action = "ls-remote {$config['Repository']}";
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
    private function execCommandForClient($action)
    {
        $command = "cd \"{$this->tmpLocation}\" ; {$this->client} {$action} 2>&1";
        error_log($command);
		if(!($pipe = popen($command, 'r')))
			return['output' => '', 'exitCode'=>-3, 'error'=>'it was not possible to start the git command'];
		for($output = ''; !feof($pipe);)
		{
			if(!($data = fread($pipe, 8000)))
			{
				if(feof($pipe))
					break;
				pclose($pipe);
				return['output' => '', 'exitCode'=>-2, 'error'=>'it was not possible to read the git command output'];
			}
			$output .= $data;
		}
		$code = pclose($pipe);
		if($code === -1)
		{
			$e = error_get_last();
			$error = $e['message'];
			error_log(serialize($e));
			error_log($output);
		}
		$error = '';
        return ['output' => $output, 'exitCode' => $code, 'error'=>$error];
    }

    /**
     * Establish a connection with the remote repo and download its contents
     * @param $arguments
     * @return bool
     */
    public function connect($arguments)
    {
		if(!IsSet($arguments['Repository']))
			return($this->SetError('it was not specified the repository URL', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));

		if(!preg_match('/^https?:\\/\\/(([^:@]+)?(:([^@]+))?@)?([^:\\/]+)(:[^\\/]+)?\\/(.*)$/', $arguments['Repository'], $m))
			return($this->SetError('it was not specified a valid repository', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));

        $this->repository = $arguments['Repository'];
        if(!$this->generateTmpLocation())
			return false;
        $clone = $this->cloneRepo();
        if ($clone['exitCode'] !== 0)
			return($this->SetError('Unable to access the remote repository', GIT_REPOSITORY_ERROR_CANNOT_CONNECT));
        $this->filesManager->setLocation($this->tmpLocation);
        $this->connected = true;
        return true;
    }

    /**
     * Generate the location for where the repo will be stored, temporarily
     */
    private function generateTmpLocation()
    {
        $CloneDirectoryName = preg_replace("/[^0-9a-zA-Z]/m", "", $this->repository);
        if(($path = tempnam(strlen($this->tmpDirectory) ? $this->tmpDirectory : sys_get_temp_dir(), $CloneDirectoryName)) === false)
			return($this->SetError('it was not possible to setup a temporary directory to retrieve the repository'));
		if(file_exists($path))
			unlink($path);
		if(!mkdir($path))
			return($this->SetError('it was not possible to create the temporary directory to retrieve the repository'));
		error_log($path);
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
			return($this->SetError('Not connected to any repository', GIT_REPOSITORY_ERROR_CANNOT_CONNECT));
        }

        if (!$this->tmpLocation) {
			return($this->SetError('No cloned repository found'));
        }

        if (!$this->deleteClonedRepoContent($this->tmpLocation)) {
			return($this->SetError('Unable to delete the cloned repository'));
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
    public function checkout($config)
    {
        $clone = $this->execCommandForClient("checkout {$this->branch}");
        if ($clone['exitCode'] !== 0) {
			return($this->SetError("Unable to checkout the branch {$this->branch}. ".$clone['exitCode'], GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT));
        }
        $repository_files = $this->filesManager->getRepoFiles();
        if(!IsSet($repository_files))
			return false;
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
    public function getNextFile($config, &$file, &$no_more_files)
    {
        $no_more_files = false;
        $file = current($this->repoFiles);
        next($this->repoFiles);
        if (key($this->repoFiles) === null) {
            $no_more_files = true;
        }
        return true;
    }

    /**
     * Get the data for the log files and store it
     * @param $config
     * @return bool
     */
    public function Log($config)
    {
        if (!$config['File']) {
			return($this->SetError('Log File not set'));
        }
        $log_files = $this->filesManager->getLogFiles($config['File']);
        if(!IsSet($log_files))
			return false;
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
        $no_more_files = false;
        $file = current($this->logFiles);
        next($this->logFiles);
        if (key($this->logFiles) === null) {
            $no_more_files = true;
        }
        return true;
    }

}
