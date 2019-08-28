<?php
/*
 * test_git_client.php
 *
 * @(#) $Id: test_git_client.php,v 1.3 2014/04/02 05:49:06 mlemos Exp $
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

	/* Output debugging information about the HTTP requests */
	$git->http_debug = 0;

	/* Format dubug output to display with HTML pages */
	$git->html_debug = false;

	$repository = 'https://github.com/manuellemos/xmlparser.git';
	$module = '';
	$log_file = 'README.txt';

	$repository = 'https://github.com/slavepens/integrity-md5-class.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/garpeer/adfly.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/jas-/jQuery.pidCrypt';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/igorescobar/nodejs-playground.git';
	$module = 'crosswords';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/mark-rolich/RulersGuides.js.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/dyorg/Lean.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'http://git.code.sf.net/p/phpprefixer/code';
	$module = '';
	$log_file = 'README.txt';
	
	$repository = 'https://github.com/picamator/SteganographyKit.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/crocodile2u/zhandlersocket.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/niceit/S3FilesManager.git';
	$module = '';
	$log_file = 'index.php';
 
	$repository = 'https://github.com/msalsas/itransformer.git';
	$module = '';
	$log_file = 'README.md';

	$repository = 'https://github.com/abriceno/codecard.git';
	$module = '';
	$log_file = 'README.md';
	
	$repository = 'https://github.com/cycle/orm.git';
	$module = '';

	$repository = 'https://github.com/msalsas/itransformer.git';
	$module = '';
	$log_file = 'README.md';

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
			case GIT_REPOSITORY_ERROR_CANNOT_FIND_HEAD:
				$git->error = 'The repository seems to be empty.';
				break;
			default:
				$git->error = 'it was returned an unexpected Git repository validation error: '.$git->error;
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
			for($files=0;;++$files)
			{
				$arguments = array(
				);
				if(!$git->GetNextFile($arguments, $file, $no_more_files)
				|| $no_more_files)
					break;
				echo '<pre>', HtmlSpecialChars(print_r($file, 1)), '</pre>';
				flush();
			}
			echo $files.' files',"\n";
		}
		$arguments = array(
			'Module'=>$module,
			'File'=>$log_file,
		);
/*
		if($git->Log($arguments))
		{
			for(;;)
			{
				$arguments = array(
				);
				if(!$git->GetNextLogFile($arguments, $file, $no_more_files)
				|| $no_more_files)
					break;
				echo '<pre>', HtmlSpecialChars(print_r($file, 1)), '</pre>';
			}
		}
*/
		$git->Disconnect();
	}
	if(strlen($git->error))
		echo '<H2 align="center">Error: ', HtmlSpecialChars($git->error), '</H2>', "\n";
?>
</UL>
<HR>
</BODY>
</HTML>
