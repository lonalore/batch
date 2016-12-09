<?php

/**
 * @file
 * Example for Batch API.
 */

if(!defined('e107_INIT'))
{
	require_once('../../class2.php');
}

// Include main Batch API file.
e107_require_once(e_PLUGIN . 'batch/includes/batch.php');
// Include we our example functions.
e107_require_once(e_PLUGIN . 'batch/includes/batch.example.php');

// Get dummy data for testing.
$comments = get_comments();

// Run!
batch_example($comments);


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
