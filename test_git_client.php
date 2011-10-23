<?php
/*
 * test_git_client.php
 *
 * @(#) $Id$
 *
 */

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<HTML>
<HEAD>
<TITLE>Test for Manuel Lemos' PHP Git client class</TITLE>
</HEAD>
<BODY>
<H1 align="center">Test for Manuel Lemos' PHP Git client class</H1>
<HR>
<UL>
<?php
	require('http.php');
	require('git_client.php');

	set_time_limit(0);
	$git = new git_client_class;

	/* Connection timeout */
	$git->timeout = 20;

	/* Data transfer timeout */
	$git->data_timeout = 60;

	/* Output debugging information about the progress of the connection */
	$git->debug = 0;

	/* Format dubug output to display with HTML pages */
	$git->html_debug = 1;

	$repository = 'https://github.com/gitster/git.git';
	$module = '';

	echo '<li><h2>Validating the Git repository</h2>', "\n", '<p>Repository: ', $repository, '</p>', "\n", '<p>Module: ', $module, '</p>', "\n";
	flush();
	$arguments = array(
		'Repository'=>$repository,
		'Module'=>$module,
	); 
	if($git->Validate($arguments, $error_code))
	{
		switch($error_code)
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
			default:
				$git->error = 'it was returned an unexpected Git repository validation error';
				break;
		}
	}
	if(strlen($git->error) == 0)
	{
		echo '<li><h2>Connecting to the Git server</h2>', "\n", '<p>Repository: ', $repository, '</p>', "\n";
		flush();
		$arguments = array(
			'Repository'=>$repository
		); 
	}
	if(strlen($git->error) == 0
	&& $git->Connect($arguments))
	{
		$arguments = array(
			'Module'=>$module
		);
		if($git->Checkout($arguments))
		{
			for(;;)
			{
				$arguments = array(
				);
				if(!$git->GetNextFile($arguments, $file, $no_more_files)
				|| $no_more_files)
					break;
				echo '<pre>', HtmlSpecialChars(print_r($file, 1)), '</pre>';
				flush();
			}
		}
		$git->Disconnect();
	}
	if(strlen($git->error))
		echo '<H2 align="center">Error: ', HtmlSpecialChars($git->error), '</H2>', "\n";
?>
</UL>
<HR>
</BODY>
</HTML>
