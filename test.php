<?php

/**
 * @file
 * Testing Batch API.
 */

if(!defined('e107_INIT'))
{
	require_once('../../class2.php');
}

e107_require_once(e_PLUGIN . 'batch/includes/batch.php');

$comments = get_comments();

$batch = array(
	'operations'       => array(
		array('batch_example_process', array($comments)),
		array('batch_example_process', array($comments)),
	),
	'finished'         => 'batch_example_finished',
	'title'            => 'Processing Example Batch',
	'init_message'     => 'Example Batch is starting.',
	'progress_message' => 'Processed @current out of @total.',
	'error_message'    => 'Example Batch has encountered an error.',
	'file'             => '{e_PLUGIN}batch/includes/batch.test.php',
);

batch_set($batch);
batch_process(e_HTTP);


/**
 * Helper function to load comments for Batch process (as dummy data).
 *
 * @return array
 */
function get_comments()
{
	$db = e107::getDb();
	$db->select('comments', '*', 'comment_id > 0');

	$comments = array();
	while($comment = $db->fetch())
	{
		$comments[] = $comment;
	}

	return $comments;
}
