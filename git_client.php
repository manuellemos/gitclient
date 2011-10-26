<?php
/*
 * git_client.php
 *
 * @(#) $Id$
 *
 */

/*
{metadocument}<?xml version="1.0" encoding="ISO-8859-1" ?>
<class>

	<package>net.manuellemos.gitclient</package>

	<version>@(#) $Id$</version>
	<copyright>Copyright © (C) Manuel Lemos 2011</copyright>
	<title>Git client</title>
	<author>Manuel Lemos</author>
	<authoraddress>mlemos-at-acm.org</authoraddress>

	<documentation>
		<idiom>en</idiom>
		<purpose>.</purpose>
		<usage>.</usage>
	</documentation>

{/metadocument}
*/

if(!defined('REPOSITORY_ERROR_NO_ERROR'))
{
	define('REPOSITORY_ERROR_UNSPECIFIED_ERROR',     -1);
	define('REPOSITORY_ERROR_NO_ERROR',               0);
	define('REPOSITORY_ERROR_INVALID_SERVER_ADDRESS', 1);
	define('REPOSITORY_ERROR_CANNOT_CONNECT',         2);
	define('REPOSITORY_ERROR_INVALID_AUTHENTICATION', 3);
	define('REPOSITORY_ERROR_COMMUNICATION_FAILURE',  4);
	define('REPOSITORY_ERROR_CANNOT_CHECKOUT',        5);
}
define('GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR',      REPOSITORY_ERROR_UNSPECIFIED_ERROR);
define('GIT_REPOSITORY_ERROR_NO_ERROR',               REPOSITORY_ERROR_NO_ERROR);
define('GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS', REPOSITORY_ERROR_INVALID_SERVER_ADDRESS);
define('GIT_REPOSITORY_ERROR_CANNOT_CONNECT',         REPOSITORY_ERROR_CANNOT_CONNECT);
define('GIT_REPOSITORY_ERROR_INVALID_AUTHENTICATION', REPOSITORY_ERROR_INVALID_AUTHENTICATION);
define('GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE',  REPOSITORY_ERROR_COMMUNICATION_FAILURE);
define('GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT',        REPOSITORY_ERROR_CANNOT_CHECKOUT);

class git_client_class
{
/*
{metadocument}
	<variable>
		<name>error</name>
		<type>STRING</type>
		<value></value>
		<documentation>
			<purpose>Store the message that is returned when an error
				occurs.</purpose>
			<usage>Check this variable to understand what happened when a call to
				any of the class functions has failed.<paragraphbreak />
				This class uses cumulative error handling. This means that if one
				class functions that may fail is called and this variable was
				already set to an error message due to a failure in a previous call
				to the same or other function, the function will also fail and does
				not do anything.<paragraphbreak />
				This allows programs using this class to safely call several
				functions that may fail and only check the failure condition after
				the last function call.<paragraphbreak />
				Just set this variable to an empty string to clear the error
				condition.</usage>
		</documentation>
	</variable>
{/metadocument}
*/
	var $error = '';

	var $error_code = GIT_REPOSITORY_ERROR_NO_ERROR;

	var $svn_error_code = 0;

	var $debug = 0;

	var $html_debug = 1;

	var $log_debug = 0;

	var $timeout = 20;
	
	var $data_timeout = 60;


	/* Private variables */
	var $http;
	var $repository;
	var $hexdec = '0123456789abcdef';
	var $pack_block = '';
	var $pack_position = 0;
	var $sideband_channel;
	var $end_of_blocks = 0;

	/* Private functions */

	Function SetError($error, $error_code = GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
	{
		$this->error_code = $error_code;
		$this->error = $error;
		return(0);
	}

	Function OutputDebug($message)
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

	Function SetHTTPErrorCode()
	{
		switch($this->http->error_code)
		{
			case HTTP_CLIENT_ERROR_NO_ERROR:
				$this->error_code = GIT_REPOSITORY_ERROR_NO_ERROR;
				break;
			case HTTP_CLIENT_ERROR_INVALID_SERVER_ADDRESS:
				$this->error_code = GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS;
				break;
			case HTTP_CLIENT_ERROR_CANNOT_CONNECT:
				$this->error_code = GIT_REPOSITORY_ERROR_CANNOT_CONNECT;
				break;
			case HTTP_CLIENT_ERROR_COMMUNICATION_FAILURE:
				$this->error_code = GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE;
				break;
			default:
				$this->error_code = GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR;
				break;
		}
		return(0);
	}

	Function GetRequest($url, $method = 'GET', $headers = array(), $body = '')
	{
		$this->http->GetRequestArguments($url, $parameters);
		$parameters['RequestMethod'] = $method;
		if(count($headers))
			$parameters['Headers'] = $headers;
		if(strlen($body))
			$parameters['Body'] = $body;
		$this->http->Open($parameters);
		$this->http->SendRequest($parameters);
		$this->http->ReadReplyHeaders($headers);
		if(strlen($this->error = $this->http->error))
			return($this->SetHTTPErrorCode());
		switch($this->http->response_status)
		{
			case 200:
				break;

			case 301:
			case 302:
				return($this->SetError('it was requested a resource that is in a different location', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));

			case 401:
				return($this->SetError('it was not specified a valid user name or a correct password to access this resource', GIT_REPOSITORY_ERROR_INVALID_AUTHENTICATION));

			case 404:
				return($this->SetError('it was requested an inexistent resource', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));

			default:
				return($this->SetError('it was returned an unexpected response status '.$this->http->response_status));
		}
		return(1);
	}

	Function GetUploadPack(&$upload_pack)
	{
		if(!$this->GetRequest($this->repository.'/info/refs?service=git-upload-pack'))
			return(0);
		$upload_pack = array();
		for($head = 1;;)
		{
			if(!$this->ReadPackBlock($block, $length))
			{
				$this->http->Close();
				return(0);
			}
			if($head)
			{
				if($length == 0)
				{
					$head = 0;
				}
				continue;
			}
			elseif($length == 0)
				break;
			if($block[0] === '#')
				continue;
			$object = substr($block, 0, $v = strspn($block, $this->hexdec));
			$v += strspn($block, ' ', $v);
			$path = substr($block, $v, strcspn($block, "\0\n", $v));
			$v += strlen($path);
			$v += strspn($block, "\0\n", $v);
			$upload_pack[$path] = array('object'=>$object);
			if($v < $length)
				$upload_pack[$path]['capabilities'] = explode(' ', substr($block, $v, strcspn($block, "\n", $v)));
		}
		$this->http->Close();
		return(1);
	}
	
	Function PackLine($line)
	{
		return(sprintf('%04x', strlen($line) + 5).$line."\n");
	}

	Function ReadPack($length, &$data)
	{
/*
		if(IsSet($this->fake_pack))
		{
			if(!($data = fread($this->fake_pack, $length)))
				return($this->SetError('could not read the fack upload pack file'));
			return(1);
		}
*/
		if(strlen($this->error = $this->http->ReadReplyBody($data, $length)))
			return($this->SetHTTPErrorCode());
		return(1);
	}

	Function ReadPackBlock(&$block, &$length)
	{
		$block = '';
		$length = 0;
		if(!$this->ReadPack(4, $data))
			return(0);
		if(strspn($data, $this->hexdec) != 4)
			return($this->SetError('unexpected upload pack response length', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$length = HexDec($data);
		if($length == 0)
			return(1);
		$remaining = $length - 4;
		if(IsSet($this->sideband_channel))
		{
			if(!$this->ReadPack(1, $data))
				return(0);
			$sideband_channel = Ord($data[0]);
			if($this->sideband_channel != $sideband_channel)
				return($this->SetError('unexpected upload pack block sideband channel', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
			--$remaining;
		}
		for(;$remaining;)
		{
			if(!$this->ReadPack($remaining, $data))
				return(0);
			$block .= $data;
			$remaining -= strlen($data);
		}
		return(1);
	}
	

	Function UnpackMore($need, $fill = 1)
	{
		while(strlen($this->pack_block) - $this->pack_position < $need)
		{
			if(!$this->end_of_blocks)
			{
				if(!$this->ReadPackBlock($block, $length))
				{
					$this->http->Close();
					return(0);
				}
				$this->end_of_blocks = ($length == 0);
			}
			if($this->end_of_blocks)
			{
				if($fill)
				{
					$this->http->Close();
					return($this->SetError('reached premature end of packed object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
				}
				return(1);
			}
			if($this->pack_position)
			{
				$this->pack_block = substr($this->pack_block, $this->pack_position);
				$this->pack_position = 0;
			}
			$this->pack_block .= $block;
		}
		return(1);
	}

	Function UnpackData($length, &$data, $fill = 1)
	{
		if(!$this->UnpackMore($length, $fill))
			return(0);
		$length = min($length, strlen($this->pack_block) - $this->pack_position);
		$data = substr($this->pack_block, $this->pack_position, $length);
		$this->pack_position += $length;
		return(1);
	}

	Function UnpackCompressedData($expanded, &$data)
	{
		if(!$this->UnpackData($expanded+256, $compressed, 0))
			return(0);
		$data = gzuncompress($compressed, $expanded);
		if(GetType($data) != 'string')
			return($this->SetError('could not uncompressed pack data', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(strlen($data) != $expanded)
			return($this->SetError('the uncompressed data size does not match the expected size', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$recompressed = gzcompress($data);
		if(strncmp($compressed, $recompressed, strlen($recompressed)))
			return($this->SetError('it was not possible to determine the length of a compressed data block', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$this->pack_position -= strlen($compressed) - strlen($recompressed);
		return(1);
	}

	Function UnpackByte(&$byte)
	{
		if(!$this->Unpackdata(1, $data))
			return(0);
		$byte = Ord($data);
		return(1);
	}

	Function UnpackInteger(&$integer)
	{
		if(!$this->UnpackData(4, $data))
			return(0);
		$u = unpack('N', $data);
		$integer = $u[1];
		return(1);
	}

	Function StartUnpack()
	{
		$this->pack_position = 0;
		$this->pack_block = '';
		UnSet($this->sideband_channel);
		$this->end_of_blocks = 0;
/*
		if(!$this->fake_pack = fopen('upload-pack', 'rb'))
			return($this->SetError('could not open the fake upload pack'));
*/
		return(1);
	}

	Function RequestUploadPack($object)
	{
		$headers = array(
			'Content-Type'=>'application/x-git-upload-pack-request',
			'Accept'=>'application/x-git-upload-pack-result'
		);
		$body = $this->PackLine('want '.$object.' multi_ack_detailed side-band-64k thin-pack no-progress').'0000'.$this->PackLine('done');
		if(!$this->GetRequest($this->repository.'/git-upload-pack', 'POST', $headers, $body))
			return(0);
		if(!$this->StartUnpack())
			return(0);
		if(!$this->ReadPackBlock($block, $length))
			return(0);
		if($block !== "NAK\n")
			return($this->SetError('unexpected upload pack response', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(!$this->UnpackByte($this->sideband_channel))
			return(0);
		if(!$this->UnpackData(4, $data))
			return(0);
		if($data !== 'PACK')
			return($this->SetError('unexpected upload pack response', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(!$this->UnpackInteger($version)
		|| !$this->UnpackInteger($objects))
			return(0);
		$this->OutputDebug('Version '.$version.' Objects '.$objects);
		for($o = 0; $o < $objects; ++$o)
		{
			if(!$this->UnpackByte($head))
				return(0);
			$shift = 4;
			$type = ($head & 0x70) >> 4;
			$size = $head & 0xF;
			while($head & 0x80)
			{
				if(!$this->UnpackByte($head))
					return(0);
				$size += ($head & 0x7F) << $shift;
				$shift += 7;
			}
			$this->OutputDebug('Object '.$o.' Type '.$type.' Size '.$size);
			switch($type)
			{
				case 1:
					if(!$this->UnpackCompressedData($size, $object))
						return(0);
					$commit = array();
					$l = strlen($object);
					for($d = 0; $d < $l && $object[$d] !== "\n";)
					{
						$s = $d + strcspn($object, ' ', $d);
						$key = substr($object, $d, $s - $d);
						++$s;
						$commit['Headers'][$key] = substr($object, $s, ($d = $s + strcspn($object, "\n", $s)) - $s);
						if($object[$d] === "\n")
							++$d;
					}
					if($object[$d] === "\n")
						++$d;
					$commit['Body'] = substr($object, $d);
					$this->OutputDebug(print_r($commit, 1));
					break;
				case 2:
					if(!$this->UnpackCompressedData($size, $object))
						return(0);
					$tree = array();
					$l = strlen($object);
					for($d = 0; $d < $l;)
					{
						$s = $d + strcspn($object, ' ', $d);
						if($s == $l)
							return($this->SetError('could not extract the tree element mode', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
						$mode = substr($object, $d, $s - $d);
						++$s;
						$d = $s + strcspn($object, "\0", $s);
						if($s == $l)
							return($this->SetError('could not extract the tree element name', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
						$name = substr($object, $s, $d - $s);
						++$d;
						$id = substr($object, $d, 20);
						$d += 20;
						if($d > $l)
							return($this->SetError('could not extract the tree element object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
						for($hash = '', $s = 0; $s < 20; ++$s)
							$hash .= sprintf('%02x', Ord($id[$s]));
						$tree[$hash] = array(
							'mode'=>$mode,
							'name'=>$name
						);
					}
					$this->OutputDebug(print_r($tree, 1));
					break;
				case 3:
				case 4:
					if(!$this->UnpackCompressedData($size, $object))
						return(0);
					$this->OutputDebug($object);
					break;
				case 6:
					if(!$this->UnpackByte($head))
						return(0);
					$base_offset = $head & 0x7F;
					while (c & 0x80)
					{
						++$base_offset;
						if(!$this->UnpackByte($head))
							return(0);
						$base_offset = ($base_offset << 7) + ($head & 0x7F);
					}
					if(!$this->UnpackCompressedData($size, $object))
						return(0);
					break;
				case 7:
					if(!$this->UnpackData(20, $object))
						return(0);
					if(!$this->UnpackCompressedData($size, $object))
						return(0);
					break;
				default:
					return($this->SetError('objects of type '.$type.' are not yet supported'));
					
			}
		}
		return($this->SetError('RequestUploadPack not fully implemented'));
	}

	/* Public functions */

	Function Connect($arguments)
	{
		if(!IsSet($arguments['Repository']))
			return($this->SetError('it was not specified the repository URL', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));
		if(!preg_match('/^https?:\\/\\/(([^:@]+)?(:([^@]+))?@)?([^:\\/]+)(:[^\\/]+)?\\/(.*)$/', $arguments['Repository'], $m))
			return($this->SetError('it was not specified a valid repository', GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS));
		$this->repository = $arguments['Repository'];
		$this->http = new http_class;
		$this->http->debug = $this->debug;
		$this->http->html_debug = $this->html_debug;
		$this->http->log_debug = $this->log_debug;
		$this->http->timeout = $this->timeout;
		$this->http->data_timeout = $this->data_timeout;
		return(1);
	}

	Function Disconnect()
	{
		if(IsSet($this->http))
		{
			UnSet($this->http);
			return(1);
		}
		else
			return($this->SetError('the connection is not opened'));
	}

	Function Checkout($arguments)
	{
		if(!IsSet($this->http))
			return($this->SetError('the connection with the server is not established'));
		if(!IsSet($arguments['Module']))
			return($this->SetError('it was not specified a valid module to checkout'));
		$module = $arguments['Module'];
		if($this->debug)
			$this->OutputDebug('Checkout module '.$module);
		if(!$this->GetUploadPack($upload_pack))
			return(0);
		if(!IsSet($upload_pack['refs/heads/master']['object']))
			return($this->SetError('the upload pack did not return the refs/heads/master object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(!$this->RequestUploadPack($upload_pack['refs/heads/master']['object']))
		{
			$this->OutputDebug($this->error);
			return(0);
		}
		return($this->SetError('Checkout not fully implemented'));
		return(1);
	}

	Function GetNextFile($arguments, &$file, &$no_more_files)
	{
		return($this->SetError('not yet implemented'));
		return(1);
	}

	Function Log($arguments)
	{
		if(!IsSet($this->http))
			return($this->SetError('the connection with the server is not established'));
		if(!IsSet($arguments['File']))
			return($this->SetError('retrieving the log of directories is not yet supported'));
		$file = $arguments['File'];
		if(IsSet($arguments['Module']))
		{
			$module = $arguments['Module'];
		}
		else
			return($this->SetError('it was not specified a valid directory to get the log'));
		if($this->debug)
			$this->OutputDebug('Log '.$file);
		$date_range = 0;
		if(IsSet($arguments['Revision']))
		{
			if(strlen($arguments['Revision']) == 0)
				return($this->SetError('it was not specified a valid log revision'));
			$start_revision = $end_revision = $arguments['Revision'];
		}
		elseif(IsSet($arguments['NewerThan']))
		{
			if(GetType($time = strtotime($arguments['NewerThan'])) != 'integer')
				return($this->SetError('it was not specified a valid newer than time'));
			$newer_date = gmstrftime('%Y-%m-%dT%H:%M:%S.000000Z', $time);
			$date_range = 1;
		}
		return($this->SetError('not yet implemented'));
		return(1);
	}

	Function GetNextLogFile($arguments, &$file, &$no_more_files)
	{
		return($this->SetError('not yet implemented'));
		return(1);
	}

	Function Validate($arguments, &$error_code)
	{
		if(!$this->Connect($arguments))
		{
			if(($error_code = $this->error_code) != GIT_REPOSITORY_ERROR_NO_ERROR)
			{
				$this->error = '';
				return(1);
			}
			return(0);
		}
		if(IsSet($arguments['Module']))
		{
			$checkout_arguments = array(
				'Module'=>$arguments['Module']
			);
			if($this->Checkout($checkout_arguments))
			{
				$checkout_arguments = array(
				);
				$this->GetNextFile($checkout_arguments, $file, $no_more_files);
			}
		}
		if(strlen($this->error)
		&& ($error_code = $this->error_code) != GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
			$this->error = '';
		if(!$this->Disconnect())
			return(0);
		return(1);
	}
};

/*

{metadocument}
</class>
{/metadocument}

*/

?>