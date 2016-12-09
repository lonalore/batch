<?php

/**
 * @file
 * Default page for batch operations.
 */

if(!defined('e107_INIT'))
{
	require_once('../../class2.php');
}

if(!e107::isInstalled('batch'))
{
	e107::redirect();
	exit;
}

e107_require_once(e_PLUGIN . 'batch/includes/batch.php');
$output = _batch_page();

if($output === false)
{
	// Access denied page.
}
elseif(isset($output))
{
	echo $output;
}
