<?php

/**
 * The file manager to handle the process of reading the repo files
 * Class FilesManager
 * @package GitClient
 */
class FilesManager
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
     * @throws Exception
     */
    public function getRepoFiles()
    {
        return $this->manageFiles();
    }

    /**
     * File Manager, that reads the files inside the cloned repo's checkedout branch
     * @param string|null $logFile
     * @return array
     * @throws Exception
     */
    private function manageFiles($logFile = null)
    {

        if (!$this->location) {
            throw new Exception("location not set");
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
     * @throws Exception
     */
    public function getLogFiles($logFile)
    {
        return $this->manageFiles($logFile);
    }

}