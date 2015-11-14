#!/usr/bin/php -q
<?php
/*
PHPN2J_Gateway | Standalone N2J Gateway "NNTP to JNTP" for Inn

Copyright © 2013-2014 Julien Arlandis
Copyright © 2014 Gérald Niel
    @author : Julien Arlandis <julien.arlandis_at_gmail.com>
    @author : Gérald Niel <gerald.niel_at_gegeweb.org>
    
    @Licence : http://www.gnu.org/licenses/agpl-3.0.txt

    PHPN2J_Gateway is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    PHPN2J_Gateway is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with PhpNemoServer.  If not, see <http://www.gnu.org/licenses/>.

Usage :
-------
Copy this file and rename it wathever you want.
Better is to copy it in path_to_inn/bin and keep the name (in FreeBSD: /usr/local/news/bin/n2j_gateway.php).
Set the executable bit : chmod +x /usr/local/news/bin/n2j_gateway.php
Configure your newsfeeds file, for example :
JNTP!/from-jntp:[group pattern]:Tp,Wf:/usr/local/news/bin/n2j_gateway.php %s [server]

Manualy : /usr/local/news/bin/n2j_gateway.php [token] [server]

You can know the token of an Usenet article with the command :
/usr/local/news/bin/grephistory '<Message-ID>'

*/

/*---- CONFIGURATION SECTION -----*/
error_reporting(E_ALL);						// For debug only
$domain = gethostname();	
define('ORIGIN_SERVER', $domain);
define('GW_NAME', 'PHP N2J Gateway');		// Name of this script
define('GW_VERSION', '0.94.r09');			// Version number
define('PROTOCOL_JNTP_VERSION', '0.21.3');

define('SYSLOG_LOG', 1);					
// Set to true for logging to syslo(news.notice)											
define('ACTIVE_LOG', 1);					// Set to true for logging to file
define('LOG_PATH', '/var/log/news');		// Path where is the logfile (must be writable by news user)
define('LOG_FILE', 'n2j_gateway.log');		// Name of the log file
define('SM_PATH', '/usr/lib/news/bin/sm');// Path to sm binary

date_default_timezone_set('UTC');			// Default Timezone to UTC (don't touch!!)

if(SYSLOG_LOG) openlog("php_n2jgateway", LOG_PERROR, LOG_NEWS); // Open syslog connection (if LOG_SYLOG set to true)

/*-----    CHECK REQUIRE     -----*/ 
if (!extension_loaded('curl')) { 			// on FreeBSD you need to install php5-curl 
	if(SYSLOG_LOG) {
		syslog(LOG_CRIT,"CURL Extension is missing!");
		closelog();
	}
	fwrite(STDERR, "CURL Extension is missing!\n");
	exit(1);
}
if (!extension_loaded('mbstring')) {  		// on FreeBSD you need to install php5-mbstring 
	if(SYSLOG_LOG) {
		syslog(LOG_CRIT,"mbstring extension is missing!");
		closelog();
	}
	fwrite(STDERR, "mbstring extension is missing!\n");
	exit(1);
}
if (!extension_loaded('iconv')) {			// on FreeBSD you need to install php5-iconv
	if(SYSLOG_LOG) {
		syslog(LOG_CRIT,"iconv extension is missing!");
		closelog();
	}
	fwrite(STDERR, "iconv extension is missing!\n");
	exit(1);
}
if (!extension_loaded('json')) {			// on FreeBSD you need to install php5-json
	if(SYSLOG_LOG) {
		syslog(LOG_CRIT,"json extension is missing!");
		closelog();
	}
	fwrite(STDERR, "json extension is missing!\n");
	exit(1);
}
if (!function_exists('shell_exec')) {		// You need to allow shell_exec in your php.ini
	if(SYSLOG_LOG) {
		syslog(LOG_CRIT,"Shell exec disabled!");
		closelog();
	}
	fwrite(STDERR, "Shell exec disabled!\n");
	exit(1);
}
/*--------------------------------*/



if (isset($argv)) {

	if (!empty($argv[1]) && $argv[1] === "--test") {
		$article = file_get_contents('message');
		echo json_encode( NNTP::articleN2J($article), JSON_PRETTY_PRINT );
		exit(0);
	}

	if (!empty($argv[1]) && $argv[1] === "--help") {
		fwrite(STDERR, "Usage : n2j_gateway.php token server [from]\n");
		if(SYSLOG_LOG) closelog();
		exit(0);
	}
	if (!empty($argv[1])) $token = $argv[1];
	else {
		if(SYSLOG_LOG) {
			syslog(LOG_ERR,"token argument is missing!");
			closelog();
		}
		fwrite(STDERR, "token argument is missing!\n");
		exit(1);
	}
	if (!empty($argv[2])) $server = $argv[2];
	else {
		if(SYSLOG_LOG) {
			syslog(LOG_ERR,"server argument is missing!");
			closelog();
		}
		fwrite(STDERR, "server argument is missing!\n");
		exit(1);
	}

}

/*--- END CONFIGURATION SECTION ---*/

$article = shell_exec(SM_PATH." ".$token);

if (!$article) {
	NNTP::logGateway('could not retrieve token '.$token, ORIGIN_SERVER, ':');
	fwrite(STDERR, "could not retrieve token $token\n");
	exit(1);
}


$article = NNTP::articleN2J($article);

$jntp = new JNTP();

$propose = array();
$propose[0]{'Jid'} = $article{'Jid'};
$propose[0]{'Data'}{'DataType'} = 'Article';
$propose[0]{'Data'}{'DataID'} = $article{'Data'}{'DataID'};

$post = array();
$post[0] = "diffuse";
$post[1]{'Propose'} = $propose;
$post[1]{'From'} = ORIGIN_SERVER;

$jntp->exec($post, $server);

NNTP::logGateway($post, $server, '>');
NNTP::logGateway($jntp->reponse, $server, '<');

if($jntp->reponse{'code'} == '200') 
{
	foreach($jntp->reponse{'body'}{'Data.DataID'} as $dataid)
	{		
		$post = array();
		$post[0] = "diffuse";
		$post[1]{'Packet'} = $article;
		$post[1]{'From'} = ORIGIN_SERVER;
		$jntp->exec($post, $server);

		NNTP::logGateway($post, $server, '>');
		NNTP::logGateway($jntp->reponse, $server, '<');
	}
}

if(SYSLOG_LOG) closelog(); // Close syslog connection (if LOG_SYLOG set to true)

exit(0);

/*----- extract of Class JNTP (modified version from PHP NemoServer) -----*/

class JNTP
{
	// Constructeur
	function __construct() {}

	function exec($post, $server)
	{
		$CURL = curl_init();		
		if(empty($CURL)) {
			if(SYSLOG_LOG) {
				syslog(LOG_ERR,"CURL not ready.");
				closelog();
			}
			fwrite(STDERR, "CURL not ready.\n");
			exit(1);
		}

		$post = is_array($post) ? json_encode($post) : $post;

		$options = array(
			CURLOPT_URL            => "http://".$server."/jntp/",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => false,
			CURLOPT_FAILONERROR    => true,
			CURLOPT_POST           => true,
			CURLOPT_TIMEOUT	       => 20,
			CURLOPT_POSTFIELDS     => $post
		);

		if(!empty($CURL)) 
		{
			curl_setopt_array($CURL, $options);
			$reponse = curl_exec($CURL);
			curl_close($CURL);
			$this->reponse = json_decode($reponse, true);
		}
		else
		{
			$this->reponse{'code'} = "500";
			$this->reponse{'body'} = "Connection failed";
		}
		return;
	}

	static function canonicFormat($json, $firstRecursivLevel = true)
	{
		if (is_array($json) ) 
		{
			foreach ($json as $key => $value) 
			{
				if(is_array($value) || is_int($key) )
				{
					$json[$key] = self::canonicFormat($value, false);
				}
				else
				{
					if(strlen($value) > 27)
					{
						$json['#'.$key] = self::hashString($value);
						unset( $json[$key] );
					}
				}
			}
		}
		if( $firstRecursivLevel ) return (json_encode(self::sortJSON($json), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		return $json;
	}

	static function sortJSON($json)
	{
		if (is_array($json) ) 
		{
			ksort($json);
			foreach ($json as $key => $value) 
			{
				if(is_array($value) || is_int($key) )
				{
					$json[$key] = self::sortJSON($value);
				}
			}
		}
		return $json;
	}

	static function hashString($str)
	{
		return rtrim(strtr(base64_encode(sha1($str, true)), '+/', '-_'), '='); 
	}

}

/*----- Class NNTP -----*/

class NNTP
{
	static function articleN2J($txt)
	{
		
		$article = array();
		$article{'Route'} = array();
		$article{'Route'} = array(ORIGIN_SERVER);
		$article{'Data'}{'DataType'} = 'Article';
		$article{'Data'}{'ProtocolVersion'} = PROTOCOL_JNTP_VERSION;
		$article{'Data'}{'References'} = array();
		$article{'Data'}{'FollowupTo'} = array();
		$article{'Data'}{'OriginHeaders'} = array();
		$article{'Data'}{'Origin'} = 'NNTP';
		$article{'Data'}{'Server'} = GW_NAME.' '.GW_VERSION;

		$pos =  strpos($txt, "\n\n");
		$head = substr($txt, 0, $pos);
		$body = substr($txt, $pos+2);
		$lignes = preg_split("/\n(?![ \t])/", $head);
		$isContentType = false;
		$isInjectionDate = false;
		mb_internal_encoding('UTF-8');

		foreach ($lignes as $ligne)
		{
			$pos = strpos($ligne, ": ");
			$header = substr($ligne, 0, $pos);
			$champ = strtolower($header);
			$value = substr($ligne, $pos + 2);

			/*if($champ === "path" && strrpos($value, "!from-jntp"))
			{
				exit(1);
			}
			else
			*/if($champ === "control")
			{
				$args = array();
				$args = explode(" ", trim($value));

				if ($args[0] === 'cancel')
				{
					$args[1] = substr($args[1], 1, strlen($args[1])-2);
					$article{'Data'}{'Control'} = 'cancelUser '.$args[1];
				}
				elseif ($args[0] === 'newgroup')
				{
					$article{'Data'}{'Control'} = $args;
				}
				elseif ($args[0] === 'rmgroup')
				{
					$article{'Data'}{'Control'} = $args;
				}
				elseif ($args[0] === 'checkgroups')
				{
					$article{'Data'}{'Control'} = $args;
				}
			}
			elseif($champ === "supersedes") 
			{
				$article{'Data'}{'Supersedes'} = substr($value, 1, strlen($value)-2);
			}
			elseif($champ === "message-id") 
			{
				$value = trim($value);
				$article{'Data'}{'DataID'} = substr($value, 1, strlen($value)-2);
			}
			elseif($champ === "from") 
			{
				$from = iconv_mime_decode($value, 2, 'UTF-8');
				preg_match('#<(.*?)>#', $from, $mail);
				preg_match('#\s*(.*?)\s*<#', $from, $name);
				$article{'Data'}{'FromName'} = "".$name[1];
				$article{'Data'}{'FromMail'} = ($mail[1]) ? $mail[1] : $from;
	
			}
			elseif($champ === "subject")
			{
				$article{'Data'}{'Subject'} = iconv_mime_decode($value, 2, 'UTF-8');
			}
			elseif($champ === "newsgroups")
			{
				$groupes = explode(",", $value);
				foreach($groupes as $groupe)
				{
					$groupe = trim($groupe);
				}
				$article{'Data'}{'Newsgroups'} = $groupes;
			}
			elseif($champ === "followup-to") 
			{
				$groupes = explode(",", $value);
				foreach($groupes as $groupe)
				{
					$groupe = trim($groupe);
				}
				$article{'Data'}{'FollowupTo'} = $groupes;
			}
			elseif($champ === "references")
			{
				$references = preg_split("/[<>, \n\t]+/", $value, 0, PREG_SPLIT_NO_EMPTY);
				if(count($references) > 0)
				{
					$article{'Data'}{'References'} = preg_split("/[<>, \n\t]+/", $value, 0, PREG_SPLIT_NO_EMPTY);
				}
			}
			elseif($champ === "user-agent")
			{
				$article{'Data'}{'UserAgent'} = $value;
			}
			elseif($champ === "reply-to")
			{
				$article{'Data'}{'ReplyTo'} = $value;
			}
			elseif($champ === "organization")
			{
				$article{'Data'}{'Organization'} = iconv_mime_decode($value, 2, 'UTF-8');
			}
			elseif($champ === "content-type")
			{
				$token = "[A-Za-z0-9\\-_.]+";
				$optFWS = "(?:\n?[ \t])*";
				$pattern = "charset{$optFWS}={$optFWS}(\"?)({$token})\\1";
				$regexp = "/;{$optFWS}{$pattern}{$optFWS}(?:[(;]|$)/i";
				$charset = preg_match($regexp, $value, $matches) ? $matches[2] : "UTF-8";
				$isContentType = true;
				$article{'Data'}{'OriginHeaders'}{'Content-Type'} = $value;
			}
			elseif($champ === "content-transfer-encoding")
			{
				if(strstr(strtolower($value), "quoted-printable"))
				{
					$body = quoted_printable_decode($body);
				}
				elseif(strstr($value, "base64"))
				{
					$body = base64_decode($body);
				}
				$article{'Data'}{'OriginHeaders'}{'Content-Transfer-Encoding'} = $value;
			}
			elseif($champ === "x-trace")
			{
				$article{'Data'}{'OriginHeaders'}{'X-Trace'} = $value;
				if (strpos($value,"("))
				{
					$start = strpos($value,"(") + 1;
					$end =  strpos($value,")",$start);
					$xtracedate = substr($value, $start, $end - $start);
				}
			}
			elseif ($champ !== "xref")
			{
				$article{'Data'}{'OriginHeaders'}{$header} = $value;
			}
		}
		
		/*
			Fix ThreadID with JID if not References else do nothing
		*/
		if(count($article{'Data'}{'References'}) == 0)
		{
			$article{'Data'}{'ThreadID'} = $article{'Data'}{'DataID'};
		}
		if(!$isContentType){
			$charset = mb_detect_encoding($body);
			$article{'Data'}{'OriginHeaders'}{'CharsetDetect'} = $charset;
		}


		if(isset($article{'Data'}{'OriginHeaders'}{'NNTP-Posting-Date'}))
		{
			$injection_date = new DateTime($article{'Data'}{'OriginHeaders'}{'NNTP-Posting-Date'});
			$injection_date->setTimezone(new DateTimeZone('UTC'));
		}
		elseif(isset($article{'Data'}{'OriginHeaders'}{'Injection-Date'}))
		{
			$injection_date = new DateTime($article{'Data'}{'OriginHeaders'}{'Injection-Date'});
			$injection_date->setTimezone(new DateTimeZone('UTC'));
		}
		elseif(isset($xtracedate))
		{
			$injection_date = new DateTime($xtracedate);
			$injection_date->setTimezone(new DateTimeZone('UTC'));
		}
		elseif(isset($article{'Data'}{'OriginHeaders'}{'Date'}))
		{
			$injection_date = new DateTime($article{'Data'}{'OriginHeaders'}{'Date'});
			$injection_date->setTimezone(new DateTimeZone('UTC'));
		}
		else
		{
			NNTP::logGateway("Header Date missing in <".$article{'Jid'}.">", ORIGIN_SERVER, ':');
			if(SYSLOG_LOG) closelog();
			exit(1);
		}
	
		$now = new DateTime("now");
		$now->setTimezone(new DateTimeZone('UTC'));

		if ($injection_date->getTimestamp() > $now->getTimestamp()) 
		{
			$date_offset = $now->diff($injection_date);
			$injection_date = $now;
		}
		$article{'Data'}{'InjectionDate'} = $injection_date->format("Y-m-d\TH:i:s\Z");		
		$body = preg_replace('/\n-- \n(?![\s\S]*\n-- \n)([\s\S]+)/', "\n[signature]$1[/signature]", $body);
		$article{'Data'}{'Body'} = mb_convert_encoding($body, "UTF-8", $charset);

		//$article{'Jid'} = JNTP::hashString( JNTP::canonicFormat($article{'Data'}) ).'@'.ORIGIN_SERVER;
		//$article{'Jid'} = sha1(minifyJSON($article{'Jid'}))
		$article{'Jid'} = JNTP::hashString( JNTP::canonicFormat($article{'Data'}) );

		return $article;
	}
	
	static function logGateway($post, $server, $direct = '<')
	{
		if(ACTIVE_LOG)	// Log to file
		{
			$post = is_array($post) ? json_encode($post) : $post;
			$handle = fopen(LOG_PATH.'/'.LOG_FILE, 'a');
			$put = '['.date(DATE_RFC822).'] ['.$server.'] '.$direct.' '.rtrim(mb_strimwidth($post, 0, 300))."\n";
			fwrite($handle, $put);
			fclose($handle);
		}
		// Log to syslog (news.notice)
		if(SYSLOG_LOG) syslog(LOG_INFO, '['.$server.'] '.$direct.' '.rtrim(mb_strimwidth($post, 0, 300)));
	}
}
?>
