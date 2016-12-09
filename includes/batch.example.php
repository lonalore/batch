<?php

/**
 * @file
 * Batch functions for example.php file.
 */


/**
 * The $batch can include the following values. Only 'operations' and
 * 'finished' are required, all others will be set to default values.
 *
 * @param operations
 *   An array of callbacks and arguments for the callbacks. There can
 *   be one callback called one time, one callback called repeatedly
 *   with different arguments, different callbacks with the same
 *   arguments, one callback with no arguments, etc. (Use an empty
 *   array if you want to pass no arguments.)
 * @param finished
 *   A callback to be used when the batch finishes.
 * @param title
 *   A title to be displayed to the end user when the batch starts. The
 *   default is 'Processing'.
 * @param init_message
 *   An initial message to be displayed to the end user when the batch
 *   starts.
 * @param progress_message
 *   A progress message for the end user. Placeholders are available.
 *   Placeholders note the progression by operation, i.e. if there are
 *   2 operations, the message will look like:
 *    'Processed 1 out of 2.'
 *    'Processed 2 out of 2.'
 *   Available placeholders are @current, @remaining, @total,
 *   @percentage, @estimate and @elapsed.
 *   Defaults to 'Processed @current of @total.'.
 * @param error_message
 *   The error message that will be displayed to the end user if the
 *   batch fails.
 * @param file
 *   Path to the file containing the definitions of the 'operations' and
 *   'finished' functions. For example: {e_PLUGIN}plugin/file.php
 */
function batch_example($comments)
{
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
		'file'             => '{e_PLUGIN}batch/includes/batch.example.php',
	);

	batch_set($batch);
	batch_process(e_HTTP);
}

/**
 * Batch Operation Callback
 *
 * Each batch operation callback will iterate over and over until
 * $context['finished'] is set to 1. After each pass, Batch API will
 * check its timer and see if it is time for a new http request,
 * i.e. when more than 1 minute has elapsed since the last request.
 * Note that $context['finished'] is set to 1 on entry - a single pass
 * operation is assumed by default.
 *
 * An entire batch that processes very quickly might only need a single
 * http request even if it iterates through the callback several times,
 * while slower processes might initiate a new http request on every
 * iteration of the callback.
 *
 * This means you should set your processing up to do in each iteration
 * only as much as you can do without a php timeout, then let Batch API
 * decide if it needs to make a fresh http request.
 *
 * @param $comments [arg2, arg3, ...etc]
 *   If any arguments were sent to the operations callback, they will be
 *   the first arguments available to the callback.
 *
 * @param $context['sandbox']
 *   Use the $context['sandbox'] rather than $_SESSION to store the
 *   information needed to track information between successive calls to
 *   the current operation. If you need to pass values to the next
 *   operation use $context['results'].
 *
 *   The values in the sandbox will be stored and updated in the database
 *   between http requests until the batch finishes processing. This will
 *   avoid problems if the user navigates away from the page before the
 *   batch finishes.
 *
 * @param $context['results']
 *   The array of results gathered so far by the batch processing. This
 *   array is highly useful for passing data between operations. After all
 *   operations have finished, these results may be referenced to display
 *   information to the end-user, such as how many total items were
 *   processed.
 *
 * @param $context['message']
 *   A text message displayed in the progress page.
 *
 * @param $context['finished']
 *   A float number between 0 and 1 informing the processing engine of the
 *   completion level for the operation.
 *
 *   1 (or no value explicitly set) means the operation is finished and the
 *   batch processing can continue to the next operation.
 *
 *   Batch API resets this to 1 each time the operation callback is called.
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
			sleep(1);

			// Update our progress information.
			$context['sandbox']['progress']++;
			$context['message'] = 'Now processing: Comment #' . $comment['comment_id'];
			$context['results'][] = $comment['comment_id'];
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
		$message = $tp->lanVars('[x] items successfully processed.', array(
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
