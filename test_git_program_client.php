<?php
/*
 * test_git_program_client.php
 *
 * @(#) $Id: test_git_program_client.php,v 1.2 2019/09/20 07:22:06 mlemos Exp $
 *
 */

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<HTML>
<HEAD>
<TITLE>Test for Manuel Lemos' PHP Git client class using the git program</TITLE>
</HEAD>
<BODY>
<H1 align="center">Test for Manuel Lemos' PHP Git client class using the git program</H1>
<HR>
<UL>
<?php

    require('git_client.php');
    require('git_program_client.php');

    set_time_limit(0);
    $git = new git_program_client_class;

    /* Output debugging information about the progress of the connection */
    $git->debug = true;

    /* Output debugging information to PHP error log */
    $git->log_debug = true;

    /* Format dubug output to display with HTML pages */
    $git->html_debug = false;
    
    $git->temporary_directory = 'tmp';

    $repository = 'https://github.com/manuellemos/gitclient.git';
    $module = '';
    $log_file = 'test_git_client.php';

    echo '<li><h2>Validating the Git repository</h2>', "\n", '<p>Repository: ', $repository, '</p>', "\n", '<p>Module: ', $module, '</p>', "\n";
    flush();
    $arguments = array(
        'Repository' => $repository,
        'Module' => $module,
    );
    if ($git->Validate($arguments, $error_code))
    {
        switch ($error_code)
        {
            case GIT_REPOSITORY_ERROR_NO_ERROR:
                break;
            case GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS:
                $git->error = 'It was specified an invalid Git server address';
                break;
            case GIT_REPOSITORY_ERROR_CANNOT_CONNECT:
                $git->error = 'Could not connect to the Git server';
                break;
            case GIT_REPOSITORY_ERROR_INVALID_AUTHENTICATION:
                $git->error = 'It was specified an invalid user or an incorrect password';
                break;
            case GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE:
                $git->error = 'There was a problem communicating with the Git server';
                break;
            case GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT:
                $git->error = 'It was not possible to checkout the specified module from the Git server';
                break;
            case GIT_REPOSITORY_ERROR_CANNOT_FIND_HEAD:
                $git->error = 'The repository seems to be empty.';
                break;
            default:
                $git->error = 'it was returned an unexpected Git repository validation error: ' . $git->error;
                break;
        }
    }
    if (strlen($git->error) == 0)
    {
        echo '<li><h2>Connecting to the Git server</h2>', "\n", '<p>Repository: ', $repository, '</p>', "\n";
        flush();
        $arguments = array(
            'Repository' => $repository
        );
    }
    if(strlen($git->error) == 0
	&& $git->Connect($arguments))
	{
        echo '<li><h2>Checking out files from the repository '.$repository.'</h2>', "\n";
        flush();
        $arguments = array(
            'Module' => $module
        );
        if($git->Checkout($arguments))
        {
			$arguments = array(
				'GetFileData'=>false,
				'GetFileModes'=>false
			);
			for($files = 0;; ++$files)
            {
                if(!$git->GetNextFile($arguments, $file, $no_more_files)
				|| $no_more_files)
                    break;
                echo '<pre>', HtmlSpecialChars(print_r($file, 1)), '</pre>';
                flush();
            }
			echo '<pre>Total of '.$files.' files</pre>',"\n";
        }
        echo '<li><h2>Getting the log of changes of file '.$log_file.'</h2>', "\n";
        flush();
        $arguments = array(
            'Module' => $module,
            'File' => $log_file,
//			'Revision' => 'a47e98393a5740d68ff78c34d29f68e22d38b2d0',
//			'NewerThan' => '2013-11-28 15:59:46 +0000'
        );
        if ($git->Log($arguments))
        {
            for(;;)
            {
                $arguments = array();
                if (!$git->GetNextLogFile($arguments, $file, $no_more_files)
                || $no_more_files)
                    break;
                echo '<pre>', HtmlSpecialChars(print_r($file, 1)), '</pre>';
            }
        }
        $git->Disconnect();
    }
    if (strlen($git->error))
        echo '<H2 align="center">Error: ', HtmlSpecialChars($git->error), '</H2>', "\n";
    ?>
</UL>
<HR>
</BODY>
</HTML>
