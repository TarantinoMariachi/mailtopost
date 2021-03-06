<?php
/**
*
* @package Mail to Post Extension
* @copyright (c) 2019 david63
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

namespace david63\mailtopost\controller;

/**
* Interface for our admin process controller
*
* This describes all of the methods we'll use for the admin front-end of this extension
*/
interface admin_actions_interface
{
	/**
	* Manual process of mail data
	*
	* @return null
	* @access public
	*/
	public function actions();

	/**
	* Set page url
	*
	* @param string $u_action Custom form action
	* @return null
	* @access public
	*/
	public function set_page_url($u_action);
}
