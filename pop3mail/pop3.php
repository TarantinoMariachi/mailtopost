<?php
/**
*
* @package Mail to Post Extension
* @copyright (c) 2019 david63
* @license GNU General Public License, version 2 (GPL-2.0)
*
* pop3.php
*
* @(#) $Header: /opt2/ena/metal/pop3/pop3.php,v 1.24 2014/01/27 10:46:48 mlemos Exp $
*
**/

namespace david63\mailtopost\pop3mail;

use phpbb\config\config;

class pop3
{
	/** @var \phpbb\config\config */
	protected $config;

	/**
	* Constructor for admin manage controller
	*
	* @param \phpbb\config\config	$config	Config object
	*
	* @access public
	*/
	public function __construct(config $config)
	{
		$this->config					= $config;

		$this->hostname 				= $this->config['mtp_hostname'];
		$this->port 					= $this->config['mtp_port'];
		$this->tls 						= $this->config['mtp_tls'];
		$this->authentication_mechanism	= $this->config['mtp_authentication'];
		$this->realm 					= $this->config['mtp_realm'];
		$this->workstation 				= $this->config['mtp_workstation'];
	}

	var $join_continuation_header_lines = 1;
	var $error = "";
	var $quit_handshake = 1;

	/* Private variables - DO NOT ACCESS */

	var $connection = 0;
	var $state = "DISCONNECTED";
	var $greeting = "";
	var $must_update = 0;
	var $next_token = "";
	var $message_buffer = "";
	var $connection_name = '';
	//var $debug = 0;
	//var $html_debug = 0;

	/* Private methods - DO NOT CALL */

	function Tokenize($string, $separator = "")
	{
		if (!strcmp($separator, ""))
		{
			$separator = $string;
			$string = $this->next_token;
		}
		for ($character = 0; $character<strlen($separator); $character ++)
		{
			if (gettype($position = strpos($string, $separator[$character])) == "integer")
			{
				$found = (isset($found) ? min($found,$position) : $position);
			}
		}
		if (isset($found))
		{
			$this->next_token=substr($string, $found + 1);
			return (substr($string, 0, $found));
		}
		else
		{
			$this->next_token = "";
			return ($string);
		}
	}

	function SetError($error)
	{
		return ($this->error = $error);
	}

	function OutputDebug($message)
	{
		$message .= "\n";
		if ($this->html_debug)
		{
			$message=str_replace("\n","<br />\n",HtmlSpecialChars($message));
		}
		echo $message;
		flush();
	}

	function GetLine()
	{
		for ($line = "";;)
		{
			if (feof($this->connection))
			{
				return (0);
			}
			$line .= fgets($this->connection, 100);
			$length = strlen($line);
			if ($length >= 2 && substr($line, $length - 2, 2) == "\r\n")
			{
				$line = substr($line, 0, $length - 2);
				if ($this->debug)
				{
					$this->OutputDebug("S $line");
				}
				return ($line);
			}
		}
	}

	function PutLine($line)
	{
		if ($this->debug)
		{
			$this->OutputDebug("C $line");
		}
		return (fputs($this->connection, "$line\r\n"));
	}

	function OpenConnection()
	{
		if ($this->tls)
		{
			$version = explode(".", function_exists("phpversion") ? phpversion() : "3.0.7");
			$php_version = intval($version[0])*1000000 + intval($version[1]) * 1000 + intval($version[2]);
			if ($php_version < 4003000)
			{
				return ("establishing TLS connections requires at least PHP version 4.3.0");
			}
			if (!function_exists("extension_loaded") || !extension_loaded("openssl"))
			{
				return ("establishing TLS connections requires the OpenSSL extension enabled");
			}
		}
		if ($this->hostname=="")
		{
			return ($this->SetError("2 it was not specified a valid hostname"));
		}
		if ($this->debug)
		{
			$this->OutputDebug("Connecting to " . $this->hostname . " ...");
		}
		if (($this->connection = @fsockopen(($this->tls ? "tls://" : "") . $this->hostname, $this->port, $error, $error_message))==0)
		{
			switch ($error)
			{
				case -3:
					return ($this->SetError("-3 socket could not be created"));
				case -4:
					return ($this->SetError("-4 dns lookup on hostname \"$hostname\" failed"));
				case -5:
					return ($this->SetError("-5 connection refused or timed out"));
				case -6:
					return ($this->SetError("-6 fdopen() call failed"));
				case -7:
					return ($this->SetError("-7 setvbuf() call failed"));
				default:
					return ($this->SetError($error." could not connect to the host \"".$this->hostname."\": ".$error_message));
			}
		}
		return ("");
	}

	function CloseConnection()
	{
		if ($this->debug)
		{
			$this->OutputDebug("Closing connection.");
		}
		if ($this->connection != 0)
		{
			fclose($this->connection);
			$this->connection = 0;
		}
	}

	/* Public methods */
	/* Open method - set the object variable $hostname to the POP3 server address. */

	function Open()
	{
		if ($this->state != "DISCONNECTED")
		{
			return ($this->SetError("1 a connection is already opened"));
		}
		if (($error=$this->OpenConnection())!="")
		{
			return ($error);
		}
		$greeting=$this->GetLine();
		if (gettype($greeting)!="string"
		|| $this->Tokenize($greeting," ")!="+OK")
		{
			$this->CloseConnection();
			return ($this->SetError("3 POP3 server greeting was not found"));
		}
		$this->Tokenize("<");
		$this->greeting = $this->Tokenize(">");
		$this->must_update=0;
		$this->state="AUTHORIZATION";
		return ("");
	}

	/* Close method - this method must be called at least if there are any
     messages to be deleted */

	function Close()
	{
		if ($this->state=="DISCONNECTED")
		{
			return ($this->SetError("no connection was opened"));
		}
		while ($this->state == 'GETMESSAGE')
		{
			if (strlen($error=$this->GetMessage(8000, $message, $end_of_message)))
			{
				return ($error);
			}
		}
		if ($this->must_update || $this->quit_handshake)
		{
			if ($this->PutLine("QUIT")==0)
			{
				return ($this->SetError("Could not send the QUIT command"));
			}
			$response=$this->GetLine();
			if (gettype($response)!="string")
			{
				return ($this->SetError("Could not get quit command response"));
			}
			if ($this->Tokenize($response," ")!="+OK")
			{
				return ($this->SetError("Could not quit the connection: ".$this->Tokenize("\r\n")));
			}
		}
		$this->CloseConnection();
		$this->state="DISCONNECTED";

// ***************************
		$pop3_class = new pop3($this->config);
		$pop3_class->SetConnection(-1, $this->connection_name, $this);
		return ("");
	}

	/* Login method - pass the user name and password of POP account.  Set
     $apop to 1 or 0 wether you want to login using APOP method or not.  */

	function Login($user, $password, $apop = 0)
	{
		if ($this->state!="AUTHORIZATION")
		{
			return ($this->SetError("connection is not in AUTHORIZATION state"));
		}
		if ($apop)
		{
			if (!strcmp($this->greeting,""))
			{
				return ($this->SetError("Server does not seem to support APOP authentication"));
			}
			if ($this->PutLine("APOP $user ".md5("<".$this->greeting.">".$password))==0)
			{
				return ($this->SetError("Could not send the APOP command"));
			}
			$response=$this->GetLine();
			if (gettype($response)!="string")
			{
				return ($this->SetError("Could not get APOP login command response"));
			}
			if ($this->Tokenize($response," ")!="+OK")
			{
				return ($this->SetError("APOP login failed: ".$this->Tokenize("\r\n")));
			}
		}
		else
		{
			$authenticated = 0;
			if (strcmp($this->authentication_mechanism,"USER") && function_exists("class_exists") && class_exists("sasl_client_class"))
			{
				if (strlen($this->authentication_mechanism))
				{
					$mechanisms=array($this->authentication_mechanism);
				}
				else
				{
					$mechanisms=array();
					if ($this->PutLine("CAPA")==0)
					{
						return ($this->SetError("Could not send the CAPA command"));
					}
					$response=$this->GetLine();
					if (gettype($response)!="string")
					{
						return ($this->SetError("Could not get CAPA command response"));
					}
					if (!strcmp($this->Tokenize($response," "),"+OK"))
					{
						for (;;)
						{
							$response=$this->GetLine();
							if (gettype($response)!="string")
							{
								return ($this->SetError("Could not retrieve the supported authentication methods"));
							}
							switch ($this->Tokenize($response," "))
							{
								case ".":
								break 2;
								case "SASL":
									for ($method=1; strlen($mechanism = $this->Tokenize(" ")); $method++)
									{
										$mechanisms[] = $mechanism;
									}
								break;
							}
						}
					}
				}
				$sasl=new sasl_client_class;
				$sasl->SetCredential("user",$user);
				$sasl->SetCredential("password",$password);
				if (strlen($this->realm))
				{
					$sasl->SetCredential("realm",$this->realm);
				}
				if (strlen($this->workstation))
				{
					$sasl->SetCredential("workstation",$this->workstation);
				}
				do
				{
					$status=$sasl->Start($mechanisms,$message,$interactions);
				}
				while ($status == SASL_INTERACT);
				switch ($status)
				{
					case SASL_CONTINUE:
					break;
					case SASL_NOMECH:
						if (strlen($this->authentication_mechanism))
						{
							return ($this->SetError("authenticated mechanism ".$this->authentication_mechanism." may not be used: ".$sasl->error));
						}
					break;
					default:
						return ($this->SetError("Could not start the SASL authentication client: ".$sasl->error));
				}
				if (strlen($sasl->mechanism))
				{
					if ($this->PutLine("AUTH ".$sasl->mechanism.(isset($message) ? " ".base64_encode($message) : ""))==0)
					{
						return ("Could not send the AUTH command");
					}
					$response=$this->GetLine();
					if (gettype($response)!="string")
					{
						return ("Could not get AUTH command response");
					}
					switch ($this->Tokenize($response," "))
					{
						case "+OK":
							$response="";
						break;
						case "+":
							$response=base64_decode($this->Tokenize("\r\n"));
						break;
						default:
							return ($this->SetError("Authentication error: ".$this->Tokenize("\r\n")));
					}
					while (!$authenticated)
					{
						do
						{
							$status=$sasl->Step($response, $message, $interactions);
						}
						while ($status == SASL_INTERACT);
						switch ($status)
						{
							case SASL_CONTINUE:
								if ($this->PutLine(base64_encode($message))==0)
								{
									return ("Could not send message authentication step message");
								}
								$response=$this->GetLine();
								if (gettype($response)!="string")
								{
									return ("Could not get authentication step message response");
								}
								switch ($this->Tokenize($response," "))
								{
									case "+OK":
										$authenticated=1;
										break;
									case "+":
										$response=base64_decode($this->Tokenize("\r\n"));
										break;
									default:
										return ($this->SetError("Authentication error: ".$this->Tokenize("\r\n")));
								}
							break;
							default:
								return ($this->SetError("Could not process the SASL authentication step: ".$sasl->error));
						}
					}
				}
			}
			if (!$authenticated)
			{
				if ($this->PutLine("USER $user")==0)
				{
					return ($this->SetError("Could not send the USER command"));
				}
				$response=$this->GetLine();
				if (gettype($response)!="string")
				{
					return ($this->SetError("Could not get user login entry response"));
				}
				if ($this->Tokenize($response," ")!="+OK")
				{
					return ($this->SetError("User error: ".$this->Tokenize("\r\n")));
				}
				if ($this->PutLine("PASS $password")==0)
				{
					return ($this->SetError("Could not send the PASS command"));
				}
				$response=$this->GetLine();
				if (gettype($response)!="string")
				{
					return ($this->SetError("Could not get login password entry response"));
				}
				if ($this->Tokenize($response," ")!="+OK")
				{
					return ($this->SetError("Password error: ".$this->Tokenize("\r\n")));
				}
			}
		}
		$this->state="TRANSACTION";
		return ("");
	}

	/* Statistics method - pass references to variables to hold the number of
     messages in the mail box and the size that they take in bytes.  */

	function Statistics(&$messages, &$size)
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($this->PutLine("STAT")==0)
		{
			return ($this->SetError("Could not send the STAT command"));
		}
		$response=$this->GetLine();
		if (gettype($response)!="string")
		{
			return ($this->SetError("Could not get the statistics command response"));
		}
		if ($this->Tokenize($response," ")!="+OK")
		{
			return ($this->SetError("Could not get the statistics: ".$this->Tokenize("\r\n")));
		}
		$messages=$this->Tokenize(" ");
		$size=$this->Tokenize(" ");
		return ("");
	}

	/* ListMessages method - the $message argument indicates the number of a
     message to be listed.  If you specify an empty string it will list all
     messages in the mail box.  The $unique_id flag indicates if you want
     to list the each message unique identifier, otherwise it will
     return the size of each message listed.  If you list all messages the
     result will be returned in an array. */

	function ListMessages($message, $unique_id)
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($unique_id)
		{
			$list_command = "UIDL";
		}
		else
		{
			$list_command = "LIST";
		}
		if ($this->PutLine("$list_command".($message ? " ".$message : "")) == 0)
		{
			return ($this->SetError("Could not send the $list_command command"));
		}
		$response=$this->GetLine();
		if (gettype($response) != "string")
		{
			return ($this->SetError("Could not get message list command response"));
		}
		if ($this->Tokenize($response," ") != "+OK")
		{
			return ($this->SetError("Could not get the message listing: " . $this->Tokenize("\r\n")));
		}
		if ($message=="")
		{
			for ($messages=array();;)
			{
				$response=$this->GetLine();
				if (gettype($response)!="string")
				{
					return ($this->SetError("Could not get message list response"));
				}
				if ($response==".")
				{
					break;
				}
				$message=intval($this->Tokenize($response," "));
				if ($unique_id)
				{
					$messages[$message]=$this->Tokenize(" ");
				}
				else
				{
					$messages[$message]=intval($this->Tokenize(" "));
				}
			}
			return ($messages);
		}
		else
		{
			$message=intval($this->Tokenize(" "));
			$value=$this->Tokenize(" ");
			return ($unique_id ? $value : intval($value));
		}
	}

	/* RetrieveMessage method - the $message argument indicates the number of
     a message to be listed.  Pass a reference variables that will hold the
     arrays of the $header and $body lines.  The $lines argument tells how
     many lines of the message are to be retrieved.  Pass a negative number
     if you want to retrieve the whole message. */

	function RetrieveMessage($message, &$headers, &$body, $lines)
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($lines<0)
		{
			$command="RETR";
			$arguments="$message";
		}
		else
		{
			$command="TOP";
			$arguments="$message $lines";
		}
		if ($this->PutLine("$command $arguments")==0)
		{
			return ($this->SetError("Could not send the $command command"));
		}
		$response=$this->GetLine();
		if (gettype($response)!="string")
		{
			return ($this->SetError("Could not get message retrieval command response"));
		}
		if ($this->Tokenize($response," ")!="+OK")
		{
			return ($this->SetError("Could not retrieve the message: ".$this->Tokenize("\r\n")));
		}
		for ($headers=$body=array(),$line=0;;)
		{
			$response=$this->GetLine();
			if (gettype($response)!="string")
			{
				return ($this->SetError("Could not retrieve the message"));
			}
			switch ($response)
			{
				case ".":
					return ("");
				case "":
					break 2;
				default:
					if (substr($response,0,1)==".")
					{
						$response=substr($response,1,strlen($response)-1);
					}
				break;
			}
			if ($this->join_continuation_header_lines && $line>0 && ($response[0]=="\t" || $response[0]==" "))
			{
				$headers[$line-1].=$response;
			}
			else
			{
				$headers[$line]=$response;
				$line++;
			}
		}
		for ($line = 0;; $line++)
		{
			$response=$this->GetLine();
			if (gettype($response)!="string")
			{
				return ($this->SetError("Could not retrieve the message"));
			}
			switch ($response)
			{
				case ".":
					return ("");
				default:
					if (substr($response,0,1)==".")
					{
						$response=substr($response,1,strlen($response)-1);
					}
				break;
			}
			$body[$line]=$response;
		}
		return ("");
	}

	/* OpenMessage method - the $message argument indicates the number of
     a message to be opened. The $lines argument tells how many lines of
     the message are to be retrieved.  Pass a negative number if you want
     to retrieve the whole message. */

	function OpenMessage($message, $lines = -1)
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($lines<0)
		{
			$command="RETR";
			$arguments="$message";
		}
		else
		{
			$command="TOP";
			$arguments="$message $lines";
		}
		if ($this->PutLine("$command $arguments")==0)
		{
			return ($this->SetError("Could not send the $command command"));
		}
		$response=$this->GetLine();
		if (gettype($response)!="string")
		{
			return ($this->SetError("Could not get message retrieval command response"));
		}
		if ($this->Tokenize($response," ")!="+OK")
		{
			return ($this->SetError("Could not retrieve the message: ".$this->Tokenize("\r\n")));
		}
		$this->state="GETMESSAGE";
		$this->message_buffer="";
		return ("");
	}

	/* GetMessage method - the $count argument indicates the number of bytes
     to be read from an opened message. The $message returns by reference
     the data read from the message. The $end_of_message argument returns
     by reference a boolean value indicated whether it was reached the end
     of the message. */

	function GetMessage($count, &$message, &$end_of_message)
	{
		if ($this->state!="GETMESSAGE")
		{
			return ($this->SetError("connection is not in GETMESSAGE state"));
		}
		$message="";
		$end_of_message=0;
		while ($count>strlen($this->message_buffer) && !$end_of_message)
		{
			$response=$this->GetLine();
			if (gettype($response)!="string")
			{
				return ($this->SetError("Could not retrieve the message headers"));
			}
			if (!strcmp($response,"."))
			{
				$end_of_message=1;
				$this->state="TRANSACTION";
				break;
			}
			else
			{
				if (substr($response,0,1)==".")
				{
					$response=substr($response,1,strlen($response)-1);
				}
				$this->message_buffer.=$response."\r\n";
			}
		}
		if ($end_of_message
		|| $count>=strlen($this->message_buffer))
		{
			$message=$this->message_buffer;
			$this->message_buffer="";
		}
		else
		{
			$message=substr($this->message_buffer, 0, $count);
			$this->message_buffer=substr($this->message_buffer, $count);
		}
		return ("");
	}

	/* DeleteMessage method - the $message argument indicates the number of
     a message to be marked as deleted.  Messages will only be effectively
     deleted upon a successful call to the Close method. */

	function DeleteMessage($message)
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($this->PutLine("DELE $message")==0)
		{
			return ($this->SetError("Could not send the DELE command"));
		}
		$response=$this->GetLine();
		if (gettype($response)!="string")
		{
			return ($this->SetError("Could not get message delete command response"));
		}
		if ($this->Tokenize($response," ")!="+OK")
		{
			return ($this->SetError("Could not delete the message: ".$this->Tokenize("\r\n")));
		}
		$this->must_update=1;
		return ("");
	}

	/* ResetDeletedMessages method - Reset the list of marked to be deleted
     messages.  No messages will be marked to be deleted upon a successful
     call to this method.  */

	function ResetDeletedMessages()
	{
		if ($this->state != "TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($this->PutLine("RSET") == 0)
		{
			return ($this->SetError("Could not send the RSET command"));
		}
		$response=$this->GetLine();
		if (gettype($response) != "string")
		{
			return ($this->SetError("Could not get reset deleted messages command response"));
		}
		if ($this->Tokenize($response," ") != "+OK")
		{
			return ($this->SetError("Could not reset deleted messages: " . $this->Tokenize("\r\n")));
		}
		$this->must_update = 0;
		return ("");
	}

	/* IssueNOOP method - Just pings the server to prevent it auto-close the
     connection after an idle timeout (tipically 10 minutes).  Not very
     useful for most likely uses of this class.  It's just here for
     protocol support completeness.  */

	function IssueNOOP()
	{
		if ($this->state!="TRANSACTION")
		{
			return ($this->SetError("connection is not in TRANSACTION state"));
		}
		if ($this->PutLine("NOOP") == 0)
		{
			return ($this->SetError("Could not send the NOOP command"));
		}
		$response=$this->GetLine();
		if (gettype($response) != "string")
		{
			return ($this->SetError("Could not NOOP command response"));
		}
		if ($this->Tokenize($response," ") != "+OK")
		{
			return ($this->SetError("Could not issue the NOOP command: " . $this->Tokenize("\r\n")));
		}
		return ("");
	}

	function &SetConnection($set, &$current_name, &$pop3)
	{
		static $connections = array();

		if ($set>0)
		{
			$current_name = strval(count($connections));
			$connections[$current_name] = &$pop3;
		}
		else if ($set < 0)
		{
			$connections[$current_name] = '';
			$current_name = '';
		}
		else if (isset($connections[$current_name]) && gettype($connections[$current_name]) != 'string')
		{
			$connection = &$connections[$current_name];
			return ($connection);
		}
		return ($pop3);
	}

	/* GetConnectionName method - Retrieve the name associated to an
	   established POP3 server connection to use as virtual host name for
	   use in POP3 stream wrapper URLs.  */
	function GetConnectionName(&$connection_name)
	{
		if ($this->state != "TRANSACTION")
		{
			return ($this->SetError("cannot get the name of a POP3 connection that was not established and the user has logged in"));
		}
		if (strlen($this->connection_name) == 0)
		{
// ***************************
			$pop3_class = new pop3($this->config);
			$pop3_class->SetConnection(1, $this->connection_name, $this);
		}
		$connection_name = $this->connection_name;
		return ('');
	}
}
