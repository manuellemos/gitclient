<?php

/**
 * The main class for this "library"
 * Class GitClient
 * @package GitClient
 */
class GitClient
{
    /**
     * @var string
     */
    public $error;
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
    private $tmpDirectory = "/tmp";

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
        $this->filesManager = new FilesManager();
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
        $this->error = $error_code = $data['exitCode'];
        return $data['exitCode'] === 0;
    }

    /**
     * Used to execute shell commands
     * @param string $action
     * @return array
     */
    private function execCommandForClient($action)
    {
        $command = "{$this->client} {$action} 2>&1";
        exec($command, $output, $exitCode);

        return ['output' => $output, 'exitCode' => $exitCode];
    }

    /**
     * Establish a connection with the remote repo and download its contents
     * @param $config
     * @return bool
     * @throws Exception
     */
    public function connect($config)
    {
        if (!isset($config['Repository']) || strlen($config['Repository']) < 1) {
            throw new Exception("Repository is required");
        }

        $this->repository = $config['Repository'];
        $this->generateTmpLocation();
        $clone = $this->cloneRepo();
        if ($clone['exitCode'] !== 0) {
            throw new Exception("Unable to connect to remote repo, or invalid repo provided");
        }
        $this->filesManager->setLocation($this->tmpLocation);
        $this->connected = true;
        return true;
    }

    /**
     * Generate the location for where the repo will be stored, temporarily
     */
    private function generateTmpLocation()
    {
        $CloneDirectoryName = preg_replace("/[^0-9a-zA-Z]/m", "", $this->repository) . time();
        $this->tmpLocation = $this->tmpDirectory . '/' . $CloneDirectoryName;
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
     * @throws Exception
     */
    public function disconnect()
    {
        if (!$this->connected) {
            throw new Exception("Not connected to any repo");
        }

        if (!$this->tmpLocation) {
            throw new Exception("No cloned repo found");
        }

        if (!$this->deleteClonedRepoContent($this->tmpLocation)) {
            throw new Exception("Unable to delete the cloned repo");
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
     * @throws Exception
     */
    public function checkout($config)
    {
        $clone = $this->execCommandForClient("checkout {$this->branch}");
        if ($clone['exitCode'] !== 0) {
            throw new Exception("Unable to checkout the branch {$this->branch}.");
        }
        $this->repoFiles = $this->filesManager->getRepoFiles();
        return true;
    }

    /**
     * Get files from the repo
     * @param $config
     * @param $file
     * @param $no_more_files
     * @return bool
     * @throws Exception
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
     * @throws Exception
     */
    public function Log($config)
    {
        if (!$config['File']) {
            throw new Exception("Log File not set");
        }
        $this->logFiles = $this->filesManager->getLogFiles($config['File']);
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
