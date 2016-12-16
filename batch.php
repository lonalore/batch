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
	require_once(HEADERF);
	$error = e107::getError();
	$error->set(403);
	$error->setCaption('Access denied');
	$error->setContent('You are not allowed to access this page.');
	$error->render();
	require_once(FOOTERF);
}
elseif(isset($output['content']))
{
	require_once(HEADERF);
	e107::getRender()->tablerender($output['caption'], $output['content']);
	require_once(FOOTERF);
}
