<?php

/**
 * @file
 * Simple mod-rewrite module.
 */

if(!defined('e107_INIT'))
{
	exit;
}


/**
 * Class batch_url.
 */
class batch_url
{

	function config()
	{
		$config = array();

		// With query parameters.
		$config['batch?'] = array(
			'alias'    => 'batch',
			// Matched against url, and if true, redirected to 'redirect' below.
			'regex'    => '^{alias}/?\?(.*)$',
			// File-path of what to load when the regex returns true.
			'redirect' => '{e_PLUGIN}batch/batch.php?$1',
		);

		$config['batch'] = array(
			'alias'    => 'batch',
			// Matched against url, and if true, redirected to 'redirect' below.
			'regex'    => '^{alias}$',
			// Used by e107::url(); to create a url from the db table.
			'sef'      => '{alias}',
			// File-path of what to load when the regex returns true.
			'redirect' => '{e_PLUGIN}batch/batch.php',
		);

		return $config;
	}

}
