<?php

/**
 * @file
 * Batch functions for test.php file.
 */


function batch_example_process($comments, &$context)
{
	if(!isset($context['sandbox']['progress']))
	{
		$context['sandbox']['progress'] = 0;
		$context['sandbox']['max'] = count($comments);
	}

	// For this example, we decide that we can safely process 1 comment
	// at a time without a timeout.
	$limit = 1;

	for($i = 0; $i < $limit; $i++)
	{
		if(isset($comments[$context['sandbox']['progress']]))
		{
			$comment = $comments[$context['sandbox']['progress']];

			// Do something with comment.
			sleep(2);

			// Update our progress information.
			$context['sandbox']['progress']++;
			$context['message'] = 'Now processing: Comment #' . $comment['comment_id'];
		}
	}

	// Inform the batch engine that we are not finished,
	// and provide an estimation of the completion level we reached.
	if($context['sandbox']['progress'] != $context['sandbox']['max'])
	{
		$context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
	}
}

/**
 * Batch 'finished' callback
 */
function batch_example_finished($success, $results, $operations)
{
	$tp = e107::getParser();
	$ms = e107::getMessage();

	if($success)
	{
		// Here we do something meaningful with the results.
		$message = $tp->lanVars('[x] items successfully processed:', array(
			'x' => count($results),
		));
		$ms->add($message, E_MESSAGE_SUCCESS, true);
	}
	else
	{
		// An error occurred.
		// $operations contains the operations that remained unprocessed.
		$error_operation = reset($operations);
		$message = $tp->lanVars('An error occurred while processing [x] with arguments: [y]', array(
			'x' => $error_operation[0],
			'y' => print_r($error_operation[1], true),
		));
		$ms->add($message, E_MESSAGE_ERROR, true);
	}
}
