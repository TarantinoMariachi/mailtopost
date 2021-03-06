<?php
/**
*
* @package Mail to Post Extension
* @copyright (c) 2019 david63
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

/**
* DO NOT CHANGE
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

/// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine
//
// Some characters you may want to copy&paste:
// ’ » “ ” …
//

$lang = array_merge($lang, array(
	'ACP_USER_MTPFORUM'			=> 'Mail to post',

	'MAILTOPOST_LOG'			=> 'Mail to Post log',
	'MAIL_TO_POST'				=> 'Mail to Post',
	'MAIL_TO_POST_ACTIONS'		=> 'Mail to Post tools',
	'MAIL_TO_POST_FORUM'		=> 'Mail to Post forum',
	'MAIL_TO_POST_MANAGE'		=> 'Mail to Post options',
	'MAIL_TO_POST_PROCESS'		=> 'Manual process',
	'MAILTOPOST_LOG_PRUNE_LOG'	=> '<strong>Mail to Post log file pruned</strong>',
	'MAILTOPOST_MANAGE_LOG'		=> '<strong>Mail to Post settings updated</strong>',
));
