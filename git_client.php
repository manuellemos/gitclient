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
	define('REPOSITORY_ERROR_CANNOT_FIND_HEAD',       6);
}
define('GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR',      REPOSITORY_ERROR_UNSPECIFIED_ERROR);
define('GIT_REPOSITORY_ERROR_NO_ERROR',               REPOSITORY_ERROR_NO_ERROR);
define('GIT_REPOSITORY_ERROR_INVALID_SERVER_ADDRESS', REPOSITORY_ERROR_INVALID_SERVER_ADDRESS);
define('GIT_REPOSITORY_ERROR_CANNOT_CONNECT',         REPOSITORY_ERROR_CANNOT_CONNECT);
define('GIT_REPOSITORY_ERROR_INVALID_AUTHENTICATION', REPOSITORY_ERROR_INVALID_AUTHENTICATION);
define('GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE',  REPOSITORY_ERROR_COMMUNICATION_FAILURE);
define('GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT',        REPOSITORY_ERROR_CANNOT_CHECKOUT);
define('GIT_REPOSITORY_ERROR_CANNOT_FIND_HEAD',       REPOSITORY_ERROR_CANNOT_FIND_HEAD);

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

	var $debug = 0;

	var $http_debug = 1;

	var $html_debug = 1;

	var $log_debug = 0;

	var $timeout = 20;
	
	var $data_timeout = 60;

	var $validation_error = '';

	var $ignore_commit_blobs = 1;


	/* Private variables */
	var $http;
	var $repository;
	var $hexdec = '0123456789abcdef';
	var $pack_block = '';
	var $pack_position = 0;
	var $pack_offset = 0;
	var $sideband_channel;
	var $end_of_blocks = 0;
	var $checkout_objects = array();
	var $checkout_trees = array();
	var $current_checkout_tree = array();
	var $checkout_tree = 0;
	var $current_checkout_tree_entry;
	var $pack_objects = array();
	var $current_checkout_tree_path = '';
	var $log_files = array();
	var $log_commit = '';
	var $current_log_file = 0;
	var $commits = array();
	var $trees = array();
	var $log_revision = '';
	var $log_newer_date = 0;
	var $upload_packs = array();
	var $checkout_path = '';
	var $pack_head = '';

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

	Function ObjectHash($type, $object)
	{
		return(sha1($type.' '.strlen($object)."\0".$object));
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
			if(strlen($data) == 0)
				return($this->SetError('reached premature end of upload pack block data', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
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
			$this->pack_offset += strlen($block);
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
		if(!$this->UnpackData($expanded + 4096, $compressed, 0))
			return(0);
		$data = gzuncompress($compressed);
		if(GetType($data) != 'string')
			return($this->SetError('could not uncompressed pack data', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(strlen($data) != $expanded)
			return($this->SetError('the uncompressed data size does not match the expected size', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$recompressed = gzcompress($data);
		$read = strlen($recompressed);
		if(strncmp($compressed, $recompressed, $read))
		{
			$low = 0; 
			$high = strlen($compressed);
			while($low < $high)
			{
				$read = intval(($high - $low) / 2) + $low;
				if(GetType(@gzuncompress(substr($compressed, 0, $read))) === 'boolean')
				{
					if($low === $read)
					{
						$read = $high;
						break;
					}
					$low = $read;
				}
				else
					$high = $read;
			}
		}
		$this->pack_position -= strlen($compressed) - $read;
		return(1);
	}

	Function UnpackByte(&$byte)
	{
		if(!$this->UnpackData(1, $data))
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
	
	Function GetBlockSize($block, &$position, &$size)
	{
		$size = $shift = 0;
		$l = strlen($block);
		do
		{
			if($position >= $l)
				return($this->SetError('reached premature end of patch delta data', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
			$b = Ord($block[$position]);
			$size += ($b & 0x7F) << $shift;
			$shift += 7;
			++$position;
		}
		while($b & 0x80);
		return(1);
	}
	
	Function ParseTreeObject($object, &$tree)
	{
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
			$sha1 = substr($object, $d, 20);
			$d += 20;
			if($d > $l)
				return($this->SetError('could not extract the tree element object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
			$hash = $this->BinaryToHexadecimal($sha1);
			$tree[$name] = array(
				'mode'=>$mode,
				'hash'=>$hash
			);
		}
		return(1);
	}
	
	Function ParseCommitObject($object, &$commit)
	{
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
		return(1);
	}

	Function ApplyDelta(&$objects, $base, $delta, &$hash)
	{
		if(!IsSet($objects[$base]))
			return($this->SetError('it was specified an unknown base object to apply the delta patch', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$position = 0;
		if(!$this->GetBlockSize($delta, $position, $size))
			return(0);
		$data = $objects[$base]['data'];
		if($size != strlen($data))
			return($this->SetError('the delta size does not match the object size', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(!$this->GetBlockSize($delta, $position, $remaining))
			return(0);
		$l = strlen($delta);
		$patched = '';
		while($position < $l)
		{
			$command = Ord($delta[$position++]);
			if($command & 0x80)
			{
				$offset = ($command & 0x1) ? Ord($delta[$position++]) : 0;
				if($command & 0x2)
					$offset += Ord($delta[$position++]) * 0x100;
				if($command & 0x4)
					$offset += Ord($delta[$position++]) * 0x10000;
				if($command & 0x8)
					$offset += Ord($delta[$position++]) * 0x1000000;
				$length = ($command & 0x10) ? Ord($delta[$position++]) : 0;
				if($command & 0x20)
					$length += Ord($delta[$position++]) * 0x100;
				if($command & 0x40)
					$length += Ord($delta[$position++]) * 0x10000;
				if($length == 0)
					$length = 0x10000;
				$patched .= substr($data, $offset, $length);
				$remaining -= $length;
			}
			elseif($command)
			{
				if($command > $remaining)
					break;
				$patched .= substr($delta, $position, $command);
				$position += $command;
				$remaining -= $command;
			}
			else
				return($this->SetError('unexpected delta command 0', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		}
		if($position < $l
		|| $remaining != 0)
			return($this->SetError('attempted to apply corrupted delta data', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$type = $objects[$base]['type'];
		$hash = $this->ObjectHash($type, $patched);
		$objects[$hash] = array(
			'type'=>$type,
			'data'=>$patched,
			'base'=>$base
		);
		$objects[$base]['patched'] = $hash;
		return(1);
	}

	Function UnpackObjects(&$objects, &$offsets, &$patches)
	{
		$offset = ($this->pack_offset - strlen($this->pack_block) + $this->pack_position);
		if($this->debug)
			$this->OutputDebug('Offset '.$offset);
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
		if($this->debug)
			$this->OutputDebug('Type '.$type.' Size '.$size);
		switch($type)
		{
			case 1:
				if(!$this->UnpackCompressedData($size, $object))
					return(0);
				$hash = $this->ObjectHash('commit', $object);
				$objects[$hash] = array(
					'type'=>'commit',
					'data'=>$object,
				);
				break;
			case 2:
				if(!$this->UnpackCompressedData($size, $object))
					return(0);
				$hash = $this->ObjectHash('tree', $object);
				if($this->debug)
				{
					if(!$this->ParseTreeObject($object, $tree))
						return(0);
					if($this->debug)
						$this->OutputDebug($hash.' '.print_r($tree, 1));
				}
				$objects[$hash] = array(
					'type'=>'tree',
					'data'=>$object,
				);
				break;
			case 3:
				if(!$this->UnpackCompressedData($size, $object))
					return(0);
				$hash = $this->ObjectHash('blob', $object);
				if($this->debug)
					$this->OutputDebug($hash.' '.strlen($object).' '.$object);
				$objects[$hash] = array(
					'type'=>'blob',
					'data'=>$object
				);
				break;
			case 4:
				if(!$this->UnpackCompressedData($size, $object))
					return(0);
				$hash = $this->ObjectHash('tag', $object);
				if($this->debug)
					$this->OutputDebug($object);
				$objects[$hash] = array(
					'type'=>'tag',
					'data'=>$object
				);
				break;
			case 6:
				if(!$this->UnpackByte($head))
					return(0);
				$base_offset = $head & 0x7F;
				while ($head & 0x80)
				{
					++$base_offset;
					if(!$this->UnpackByte($head))
						return(0);
					$base_offset = ($base_offset << 7) + ($head & 0x7F);
				}
				$base_offset = $offset - $base_offset;
				if($this->debug)
					$this->OutputDebug('Patch size '.$size.' Object offset '.$base_offset);
				if(!$this->UnpackCompressedData($size, $delta))
					return(0);
				if(!IsSet($offsets[$base_offset]))
					return($this->SetError('it was not found the pack object with offset '.$base_offset, GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
				$base = $offsets[$base_offset];
				if($this->debug)
					$this->OutputDebug('Patch base object '.$base);
				if(!$this->ApplyDelta($objects, $base, $delta, $hash))
					return(0);
				if($this->debug)
					$this->OutputDebug('Patched object '.$hash);
				break;
			case 7:
				if(!$this->UnpackData(20, $sha1))
					return(0);
				if(!$this->UnpackCompressedData($size, $delta))
					return(0);
				$base = $this->BinaryToHexadecimal($sha1);
				if(IsSet($objects[$base]))
				{
					if($this->debug)
						$this->OutputDebug('Patch base object '.$base);
					if(!$this->ApplyDelta($objects, $base, $delta, $hash))
						return(0);
					if($this->debug)
						$this->OutputDebug('Patched object '.$hash);
				}
				else
				{
					if($this->debug)
						$this->OutputDebug('Deferred patch of object '.$base);
					$patches[] = array(
						'base'=>$base,
						'delta'=>$delta
					);
					return(1);
				}
				break;
			default:
				return($this->SetError('objects of type '.$type.' are not yet supported', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		}
		$offsets[$offset] = $hash;
		return(1);
	}

	Function StartUnpack()
	{
		$this->pack_position = $this->pack_offset = 0;
		$this->pack_block = '';
		UnSet($this->sideband_channel);
		$this->end_of_blocks = 0;
		$this->commits = array();
		$this->trees = array();
		return(1);
	}

	Function BinaryToHexadecimal($b)
	{
		$l = strlen($b);
		for($h = '', $s = 0; $s < $l; ++$s)
			$h .= sprintf('%02x', Ord($b[$s]));
		return($h);
	}

	Function RequestUploadPack($object, &$objects)
	{
		if($this->debug)
			$this->OutputDebug('Retrieving the upload pack for object '.$object);
		$headers = array(
			'Content-Type'=>'application/x-git-upload-pack-request',
			'Accept'=>'application/x-git-upload-pack-result'
		);
		$body = $this->PackLine('want '.$object.' multi_ack_detailed side-band-64k thin-pack no-progress ofs-delta').'0000'.$this->PackLine('done');
		if(!$this->GetRequest($this->repository.'/git-upload-pack', 'POST', $headers, $body))
			return(0);
		if(!$this->StartUnpack()
		|| !$this->ReadPackBlock($block, $length))
		{
			$this->http->Close();
			return(0);
		}
		if($block !== "NAK\n")
			return($this->SetError('unexpected upload pack response', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		if(!$this->UnpackByte($this->sideband_channel)
		|| !$this->UnpackData(4, $data))
		{
			$this->http->Close();
			return(0);
		}
		if($data !== 'PACK')
		{
			$this->http->Close();
			return($this->SetError('unexpected upload pack response', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		}
		if(!$this->UnpackInteger($version)
		|| !$this->UnpackInteger($pack_objects))
		{
			$this->http->Close();
			return(0);
		}
		if($this->debug)
			$this->OutputDebug('Pack version '.$version.' objects '.$pack_objects);
		$objects = $offsets = $patches = array();
		for($o = 0; $o < $pack_objects; ++$o)
		{
			if($this->debug)
				$this->OutputDebug('Object '.$o.' of '.$pack_objects);
			if(!$this->UnpackObjects($objects, $offsets, $patches))
			{
				$this->http->Close();
				return(0);
			}
		}
		while($remaining = count($patches))
		{
			if($this->debug)
				$this->OutputDebug($remaining.' patches remaining');
			foreach($patches as $p => $patch)
			{
				$base = $patch['base'];
				if(IsSet($objects[$base]))
				{
					if($this->debug)
						$this->OutputDebug('Deferred patch object '.$base);
					if(!$this->ApplyDelta($objects, $base, $patch['delta'], $hash))
						return(0);
					if($this->debug)
						$this->OutputDebug('Deferred patch object '.$hash);
					UnSet($patches[$p]);
				}
			}
			if($remaining == count($patches))
				return($this->SetError('could not find base object '.$base.' to patch', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		}
		$this->http->Close();
		return(1);
	}

	Function GetPack()
	{
		if(IsSet($this->upload_packs[$this->repository]))
			$upload_pack = $this->upload_packs[$this->repository];
		else
		{
			if(!$this->StartUnpack()
			|| !$this->GetUploadPack($upload_pack))
				return(0);
			$this->upload_packs[$this->repository] = $upload_pack;
			$this->pack_objects = array();
		}
		if(IsSet($upload_pack['HEAD']['object']))
			$head = $upload_pack['HEAD']['object'];
		elseif(IsSet($upload_pack['refs/heads/master']['object']))
			$head = $upload_pack['refs/heads/master']['object'];
		else
			return($this->SetError('the upload pack did not return the refs/heads/master object', GIT_REPOSITORY_ERROR_CANNOT_FIND_HEAD));
		$this->pack_head = $head;
		if(IsSet($this->pack_objects[$head]))
			$this->checkout_objects = $this->pack_objects[$head];
		else
		{
			if(!$this->RequestUploadPack($head, $this->checkout_objects))
			{
				if($this->debug)
					$this->OutputDebug($this->error);
				return(0);
			}
			$this->pack_objects[$head] = $this->checkout_objects;
		}
		return(1);
	}

	Function GetCommitObject($hash, &$commit)
	{
		if(IsSet($this->commits[$hash]))
			$commit = $this->commits[$hash];
		else
		{
			if($this->checkout_objects[$hash]['type'] !== 'commit')
				return($this->SetError('the object '.$hash.' is not of type commit'));
			if(!$this->ParseCommitObject($this->checkout_objects[$hash]['data'], $commit))
				return(0);
			if(!IsSet($commit['Headers']['tree'])
			|| !IsSet($this->checkout_objects[$commit['Headers']['tree']]))
				return($this->SetError('the upload pack did not return a valid commit object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
			$this->commits[$hash] = $commit;
		}
		return(1);
	}

	Function GetTreeObject($hash, &$tree)
	{
		if(IsSet($this->trees[$hash]))
			$tree = $this->trees[$hash];
		else
		{
			if($this->checkout_objects[$hash]['type'] !== 'tree')
				return($this->SetError('the object '.$hash.' is not of type tree'));
			if(!$this->ParseTreeObject($this->checkout_objects[$hash]['data'], $tree))
				return(0);
			$this->trees[$hash] = $tree;
		}
		return(1);
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
		$this->http->debug = $this->debug && $this->http_debug;
		$this->http->html_debug = $this->html_debug;
		$this->http->log_debug = $this->log_debug;
		$this->http->timeout = $this->timeout;
		$this->http->data_timeout = $this->data_timeout;
		$this->http->user_agent = 'git/emulation';
		$this->http->accept = '*/*';
		$this->http->sasl_authenticate = 0;
		$this->error = '';
		$this->error_code = GIT_REPOSITORY_ERROR_NO_ERROR;
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
		if(!$this->GetPack())
			return(0);
		$this->checkout_trees = array();
		$hash = '';
		for(Reset($this->checkout_objects); IsSet($this->checkout_objects[$hash = Key($this->checkout_objects)]); Next($this->checkout_objects))
		{
			if($this->pack_head === $hash)
			{
				if(!$this->checkout_objects[$hash]['type'] == 'commit')
					return($this->SetError('the retrieved branch object type '.$hash.' is not commit as expected'));
				if(!$this->GetCommitObject($hash, $commit))
					return(0);
				if($this->debug)
					$this->OutputDebug('Commit '.$hash.' '.print_r($commit, 1));
				$hash = $commit['Headers']['tree'];
				break;
			}
		}
		if(strlen($hash) == 0)
			return($this->SetError('it was not returned the repository commit object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$module_path = (strlen($module) ? explode('/', $module) : array());
		for($path = 0;;)
		{
			if(!IsSet($this->checkout_objects[$hash])
			|| $this->checkout_objects[$hash]['type'] !== 'tree')
				return($this->SetError('it was not returned valid directory tree entry '.$hash, GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
			if(count($module_path) === $path)
				break;
			if(!$this->GetTreeObject($hash, $tree))
				return false;
			$name = '';
			foreach($tree as $name => $entry)
			{
				$hash = $entry['hash'];
				if(!strcmp($name, $module_path[$path]))
					break;
			}
			if($name !== $module_path[$path])
				return($this->SetError('it was not found the directory tree path '.$module_path[$path], GIT_REPOSITORY_ERROR_CANNOT_CHECKOUT));
			++$path;
		}
		$this->checkout_path = (count($module_path) ? implode($module_path, '/').'/' : '');
		$this->checkout_trees[] = array('hash'=>$hash, 'path'=>'');
		$this->checkout_tree = 0;
		$this->current_checkout_tree = array();
		return(1);
	}

	Function GetNextFile($arguments, &$file, &$no_more_files)
	{
		$no_more_files = 0;
		for(;;)
		{
			while(count($this->current_checkout_tree) == 0)
			{
				if($this->debug)
					$this->OutputDebug('Checkout tree '.$this->checkout_tree.' of '. count($this->checkout_trees));
				if($this->checkout_tree >= count($this->checkout_trees))
				{
					$no_more_files = 1;
					return(1);
				}
				$checkout_tree = $this->checkout_trees[$this->checkout_tree++];
				$hash = $checkout_tree['hash'];
				if(!$this->GetTreeObject($hash, $tree))
					return(0);
				$this->current_checkout_tree = $tree;
				$this->current_checkout_tree_path = $checkout_tree['path'];
				Reset($this->current_checkout_tree);
				$this->current_checkout_tree_entry = Key($this->current_checkout_tree);
				if($this->debug)
					$this->OutputDebug('Tree hash: '.$hash.' Path: '.$this->current_checkout_tree_path.' Contents: '.print_r($tree, 1));
			}
			if($this->debug)
				$this->OutputDebug('Traversing tree with path "'.$this->current_checkout_tree_path.'"');
			while(IsSet($this->current_checkout_tree_entry)
			&& IsSet($this->current_checkout_tree[$name = $this->current_checkout_tree_entry]))
			{
				$hash = $this->current_checkout_tree[$name]['hash'];
				if(!IsSet($this->checkout_objects[$hash]))
				{
					Next($this->current_checkout_tree);
					$this->current_checkout_tree_entry = Key($this->current_checkout_tree);
					continue;
/*
					return($this->SetError('it was not returned the file entry object '.$hash, GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
*/
				}
				$type = $this->checkout_objects[$hash]['type'];
				$entry = $this->current_checkout_tree[$name];
				$hash = $entry['hash'];
				if($type === 'tree')
				{
					$path = $this->current_checkout_tree_path.$name.'/';
					$this->checkout_trees[] = array('hash'=>$hash, 'path'=>$path);
					Next($this->current_checkout_tree);
					$this->current_checkout_tree_entry = Key($this->current_checkout_tree);
					if($this->debug)
						$this->OutputDebug('Found sub-tree with path "'.$path.'" '.$hash);
					continue;
				}
				switch($type)
				{
					case 'blob':
						break;
					case 'commit':
						if($this->ignore_commit_blobs)
						{
							Next($this->current_checkout_tree);
							$this->current_checkout_tree_entry = Key($this->current_checkout_tree);
							continue 2;
						}
					default:
						return($this->SetError('it was returned an object of type '.$type.' for the file object '.$hash.' named '.$name, GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
				}
				$base_name = $name;
				if(($path = dirname($base_name)) === '.')
					$path = '';
				else
					$path .= '/';
				$modes = array();
				$mode = substr($entry['mode'], -3);
				if(strlen($mode) != 3)
					return($this->SetError('unexpected file mode value "'.$entry['mode'].'"', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
				$mode_map = array(
					'0'=>'',
					'1'=>'x',
					'2'=>'w',
					'3'=>'wx',
					'4'=>'r',
					'5'=>'rx',
					'6'=>'rw',
					'7'=>'rwx'
				);
				for($m = 0; $m < 3; ++$m)
				{
					$mode_value = $mode[$m];
					if(!IsSet($mode_map[$mode_value]))
						return($this->SetError('unexpected file mode value "'.$mode_value.'"', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
					$modes[substr('ugo', $m, 1)] = $mode_map[$mode_value];
				}
				$file = array(
					'Version'=>$hash,
					'Name'=>basename($base_name),
					'PathName'=>$this->checkout_path.$path,
					'File'=>$this->checkout_path.$this->current_checkout_tree_path.$base_name,
					'RelativeFile'=>$this->checkout_path.$this->current_checkout_tree_path.$base_name,
					'Mode'=>$modes,
					'Data'=>$this->checkout_objects[$hash]['data'],
					'Size'=>strlen($this->checkout_objects[$hash]['data'])
				);
				Next($this->current_checkout_tree);
				$this->current_checkout_tree_entry = Key($this->current_checkout_tree);
				return(1);
			}
			UnSet($this->current_checkout_tree_entry);
			$this->current_checkout_tree = array();
		}
		$no_more_files = 1;
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
			$module = $arguments['Module'];
		else
			return($this->SetError('it was not specified a valid directory to get the log'));
		if($this->debug)
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
		if(!$this->GetPack())
			return(0);
		$commit = array();
		$this->log_commit = '';
		for(Reset($this->checkout_objects); IsSet($this->checkout_objects[$hash = Key($this->checkout_objects)]); Next($this->checkout_objects))
		{
			if($this->pack_head === $hash)
			{
				if($this->checkout_objects[$hash]['type'] !== 'commit')
					return($this->SetError('the retrieved branch object type '.$hash.' is not commit as expected'));
				if(!$this->GetCommitObject($hash, $commit))
					return(1);
				$this->log_commit = $hash;
				if($this->debug)
					$this->OutputDebug('Commit '.$hash.' '.print_r($commit, 1));
				break;
			}
		}
		if(strlen($this->log_commit) == 0)
			return($this->SetError('it was not returned the repository commit object', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
		$this->log_files = array();
		if(strlen($module)
		&& $module[strlen($module) - 1] !== '/')
			$module.='/';
		$tree_object = $commit['Headers']['tree'];
		$file_path = explode('/', $module.$file);
		$sub_path = '';
		foreach($file_path as $path)
		{
			if(!$this->GetTreeObject($tree_object, $tree))
				return(0);
			$found = 0;
			$sub_path .= $path;
			foreach($tree as $name => $object)
			{
				if(!strcmp($name,$path))
				{
					$hash = $object['hash'];
					$found = 1;
					break;
				}
			}
			if(!$found
			|| !IsSet($this->checkout_objects[$hash]))
				return($this->SetError($sub_path.' is not a valid file path to get the log'));
			switch($this->checkout_objects[$hash]['type'])
			{
				case 'tree':
					$sub_path .= '/';
					$tree_object = $hash;
					break;
				case 'blob':
					break 2;
				default:
					return($this->SetError($sub_path.' is not a valid file path type object to get the log'));
			}
		}
		if($sub_path !== $module.$file)
			return($this->SetError($file.' is not a valid file to get the log'));
		$this->log_files[] = array(
			'object'=>$hash,
			'path'=>$file_path,
			'name'=>$file
		);
		$this->current_log_file = 0;
		return(1);
	}

	Function GetNextLogFile($arguments, &$file, &$no_more_files)
	{
		if($no_more_files = ($this->current_log_file >= count($this->log_files)))
			return(1);
		$log_file = $this->log_files[$this->current_log_file];
		$hash = $log_file['object'];
		$file = $log_file['name'];
		$file_path = $log_file['path'];
		$next_commit = $this->log_commit;
		$first = 1;
		$revisions = array();
		$got_revision = 0;
		for(;;)
		{
			if(!$this->GetCommitObject($next_commit, $commit))
				return(1);
			if($first)
			{
				$file_name = $file;
				$first = 0;
				$found = 1;
			}
			else
			{
				$found = 0;
				$sub_path = '';
				if(!$this->GetTreeObject($commit['Headers']['tree'], $tree))
					return(0);
				foreach($file_path as $path)
				{
					$sub_path .= $path;
					foreach($tree as $name => $object)
					{
						if(!strcmp($name,$path))
						{
							$tree_hash = $object['hash'];
							$found = 1;
							break;
						}
					}
					if(!$found)
						break 2;
					switch($this->checkout_objects[$tree_hash]['type'])
					{
						case 'tree':
							$sub_path .= '/';
							$found = 0;
							if(!$this->GetTreeObject($tree_hash, $tree))
								return(0);
							break;
						case 'blob':
							if($file !== $sub_path)
								$found = 0;
							else
								$hash = $tree_hash;
							break 2;
					}
				}
			}
			if($found
			&& strlen($this->log_revision))
			{
				if($got_revision)
				{
					if($this->log_revision !== $hash)
						break;
				}
				else
				{
					if($this->log_revision !== $hash)
						$found = 0;
					else
						$got_revision = 1;
				}
			}
			if($found)
			{
				if(!preg_match('/(.*) ([0-9]+) (.*)/', $commit['Headers']['committer'], $m))
					return($this->SetError('it was not possible to extract the committer information', GIT_REPOSITORY_ERROR_COMMUNICATION_FAILURE));
				if($this->log_newer_date
				&& $this->log_newer_date >= intval($m[2]))
				{
					UnSet($revisions[$hash]);
					break;
				}
				$revisions[$hash] = array(
					'Log'=>$commit['Body'],
					'author'=>$m[1],
					'date'=>gmstrftime('%Y-%m-%d %H:%M:%S +0000', $m[2])
				);
			}
			if(!IsSet($commit['Headers']['parent']))
				break;
			$next_commit = $commit['Headers']['parent'];
		}
		$file = array(
			'Properties'=>array(
				'description'=>'',
				'Work file'=>basename($file_name)
			),
			'Revisions'=>$revisions
		);
		++$this->current_log_file;
		return(1);
	}

	Function Validate($arguments, &$error_code)
	{
		$this->validation_error = '';
		if(!$this->Connect($arguments))
		{
			if(($error_code = $this->error_code) != GIT_REPOSITORY_ERROR_NO_ERROR)
			{
				$this->validation_error = $this->error;
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
		$error_code = $this->error_code;
		if(strlen($this->error)
		&& $error_code != GIT_REPOSITORY_ERROR_UNSPECIFIED_ERROR)
		{
			if($this->debug)
				$this->OutputDebug('Validate error: '.$this->error);
			$this->validation_error = $this->error;
			$this->error = '';
		}
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