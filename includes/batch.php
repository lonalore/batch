<?php

/**
 * @file
 * Batch processing API for processes to run in multiple HTTP requests.
 *
 * Example:
 * @code
 * $batch = array(
 *   'title' => t('Exporting'),
 *   'operations' => array(
 *     array('my_function_1', array($author, 'story')),
 *     array('my_function_2', array()),
 *   ),
 *   'finished' => 'my_finished_callback',
 *   'file' => '{e_PLUGIN}plugin/path/file.php',
 * );
 *
 * batch_set($batch);
 * // Setting redirect in batch_process.
 * batch_process(e_HTTP);
 * @endcode
 *
 * Note: if the batch 'title', 'init_message', 'progress_message', or 'error_message'
 * could contain any user input, it is the responsibility of the code calling
 * batch_set($batch); to sanitize them first with a function like e107::getParser()->filter().
 * Furthermore, if the batch operation returns any user input in the 'results' or 'message'
 * keys of $context, it must also sanitize them first.
 */

if(!defined('e107_INIT'))
{
	require_once('../../../class2.php');
}


e107_require_once(e_PLUGIN . 'batch/includes/batch.token.php');
e107_require_once(e_PLUGIN . 'batch/includes/batch.queue.php');


/**
 * Adds a new batch.
 *
 * Batch operations are added as new batch sets. Batch sets are used to spread processing
 * over several page requests. This helps to ensure that the processing is not interrupted
 * due to PHP timeouts, while users are still able to receive feedback on the progress of
 * the ongoing operations. Combining related operations into distinct batch sets provides
 * clean code independence for each batch set, ensuring that two or more batches, submitted
 * independently, can be processed without mutual interference. Each batch set may specify
 * its own set of operations and results, produce its own UI messages, and trigger its own
 * 'finished' callback. Batch sets are processed sequentially, with the progress bar starting
 * afresh for each new set.
 *
 * @param $batch_definition
 *   An associative array defining the batch, with the following elements (all are optional
 *   except as noted):
 *   - operations: (required) Array of operations to be performed, where each item is an array
 *     consisting of the name of an implementation of callback_batch_operation() and an array
 *     of parameter.
 *     Example:
 * @code
 *     array(
 *       array('callback_batch_operation_1', array($arg1)),
 *       array('callback_batch_operation_2', array($arg2_1, $arg2_2)),
 *     )
 * @endcode
 *   - title: A safe, translated string to use as the title for the progress page. Defaults to
 *     'Processing'.
 *   - init_message: Message displayed while the processing is initialized. Defaults to
 *     'Initializing.'.
 *   - progress_message: Message displayed while processing the batch. Available placeholders
 *     are @current, @remaining, @total, @percentage, @estimate and @elapsed. Defaults to
 *     'Completed @current of @total.'.
 *   - error_message: Message displayed if an error occurred while processing the batch.
 *     Defaults to 'An error has occurred.'.
 *   - finished: Name of an implementation of callback_batch_finished(). This is executed after
 *     the batch has completed. This should be used to perform any result massaging that may be
 *     needed, and possibly save data in $_SESSION for display after final page redirection.
 *   - file: Path to the file containing the definitions of the 'operations' and 'finished'
 *     functions. For example: {e_PLUGIN}plugin/file.php
 *   - css: Array of paths to CSS files to be used on the progress page.
 *   - url_options: options used for constructing redirect URLs for the batch.
 */
function batch_set($batch_definition)
{
	if($batch_definition)
	{
		$batch =& batch_get();

		// Initialize the batch if needed.
		if(empty($batch))
		{
			$batch = array(
				'sets' => array(),
			);
		}

		// Base and default properties for the batch set.
		$init = array(
			'sandbox' => array(),
			'results' => array(),
			'success' => false,
			'start'   => 0,
			'elapsed' => 0,
		);
		$defaults = array(
			'title'            => 'Processing',
			'init_message'     => 'Initializing.',
			'progress_message' => 'Completed @current of @total.',
			'error_message'    => 'An error has occurred.',
			'css'              => array(),
		);
		$batch_set = $init + $batch_definition + $defaults;

		// Tweak init_message to avoid the bottom of the page flickering down after
		// init phase.
		$batch_set['init_message'] .= '<br/>&nbsp;';

		// The non-concurrent workflow of batch execution allows us to save
		// numberOfItems() queries by handling our own counter.
		$batch_set['total'] = count($batch_set['operations']);
		$batch_set['count'] = $batch_set['total'];

		// Add the set to the batch.
		if(empty($batch['id']))
		{
			// The batch is not running yet. Simply add the new set.
			$batch['sets'][] = $batch_set;
		}
		else
		{
			// The set is being added while the batch is running. Insert the new set
			// right after the current one to ensure execution order, and store its
			// operations in a queue.
			$index = $batch['current_set'] + 1;
			$slice1 = array_slice($batch['sets'], 0, $index);
			$slice2 = array_slice($batch['sets'], $index);
			$batch['sets'] = array_merge($slice1, array($batch_set), $slice2);
			_batch_populate_queue($batch, $index);
		}
	}
}

/**
 * Retrieves an unique id from a given sequence.
 *
 * Use this function if for some reason you can't use a serial field. For example,
 * MySQL has no ways of reading of the current value of a sequence. Or sometimes
 * you just need a unique integer.
 *
 * @param int $existing_id
 *  After a database import, it might be that the sequences table is behind, so by
 *  passing in the maximum existing id, it can be assured that we never issue the
 *  same id.
 *
 * @return int
 *  An integer number larger than any number returned by earlier calls and also
 *  larger than the $existing_id if one was passed in.
 */
function batch_next_id($existing_id = 0)
{
	$db = e107::getDb('BatchNextID');
	$db->gen('INSERT INTO #sequences () VALUES ()');

	$new_id = $db->lastInsertId();

	if($existing_id >= $new_id)
	{
		// If we INSERT a value manually into the sequences table, on the next
		// INSERT, MySQL will generate a larger value. However, there is no way
		// of knowing whether this value already exists in the table. MySQL
		// provides an INSERT IGNORE which would work, but that can mask problems
		// other than duplicate keys. Instead, we use INSERT ... ON DUPLICATE KEY
		// UPDATE in such a way that the UPDATE does not do anything. This way,
		// duplicate keys do not generate errors but everything else does.
		$db->gen('INSERT INTO #sequences (value) VALUES (' . $existing_id . ') ON DUPLICATE KEY UPDATE value = value');
		$db->gen('INSERT INTO #sequences () VALUES ()');
		$new_id = $db->lastInsertId();
	}

	$db->gen('DELETE FROM #sequences WHERE value < ' . $new_id);

	return $new_id;
}

/**
 * Processes the batch.
 *
 * Unless the batch has been marked with 'progressive' = FALSE, the function issues a
 * e107::redirect() and thus ends page execution.
 *
 * @param $redirect
 *   (optional) Path to redirect to when the batch has finished processing.
 */
function batch_process($redirect = null, $url = 'batch')
{
	$batch =& batch_get();

	if(isset($batch))
	{
		// Add process information
		$process_info = array(
			'current_set' => 0,
			'progressive' => true,
			'url'         => $url,
			'url_options' => array(),
			'source_url'  => e_SELF,
			'redirect'    => $redirect,
		);
		$batch += $process_info;

		// The batch is now completely built. Allow other modules to make changes
		// to the batch so that it is easier to reuse batch processes in other
		// environments.
		// TODO

		// Assign an arbitrary id: don't rely on a serial column in the 'batch'
		// table, since non-progressive batches skip database storage completely.
		$batch['id'] = batch_next_id();

		// Move operations to a job queue. Non-progressive batches will use a
		// memory-based queue.
		foreach($batch['sets'] as $key => $batch_set)
		{
			_batch_populate_queue($batch, $key);
		}

		// Initiate processing.
		if($batch['progressive'])
		{
			// Now that we have a batch id, we can generate the redirection link in
			// the generic error message.
			if($process_info['url'] == 'batch')
			{
				$url = e107::url('batch', 'batch', array(), array(
					'query' => array(
						'op' => 'finished',
						'id' => $batch['id'],
					),
				));
			}
			else
			{
				$url = explode('?', $process_info['url']);

				$query = array();
				if (!empty($url[1])) {
					parse_str($url[1], $query);
				}

				$query['op'] = 'finished';
				$query['id'] = $batch['id'];

				$url[1] = http_build_query($query);
				$url = implode('?', $url);
			}

			$batch['error_message'] = e107::getParser()->lanVars(LAN_PLUGIN_BATCH_01, array(
				'x' => '<a href="' . $url . '">' . LAN_PLUGIN_BATCH_02 . '</a>',
			));

			// Clear the way for the redirection to the batch processing page,
			// by saving and unsetting the 'destination', if there is any.
			if(isset($_GET['destination']))
			{
				$batch['destination'] = $_GET['destination'];
				unset($_GET['destination']);
			}

			e107_require_once(e_PLUGIN . 'batch/includes/batch.token.php');

			$insert = array(
				'data' => array(
					'batch_id'        => $batch['id'],
					'batch_token'     => batch_get_token($batch['id']),
					'batch_timestamp' => (int) $_SERVER['REQUEST_TIME'],
					'batch_data'      => base64_encode(serialize($batch)),
				),
			);

			// Store the batch.
			e107::getDb()->insert('batch', $insert);

			// Set the batch number in the session to guarantee that it will stay alive.
			$_SESSION['batches'][$batch['id']] = true;

			// Redirect for processing.
			if($process_info['url'] == 'batch')
			{
				$url = e107::url('batch', 'batch', array(), array(
					'query' => array(
						'op' => 'start',
						'id' => $batch['id'],
					),
				));
			}
			else
			{
				$url = explode('?', $process_info['url']);

				$query = array();
				if (!empty($url[1])) {
					parse_str($url[1], $query);
				}

				$query['op'] = 'start';
				$query['id'] = $batch['id'];

				$url[1] = http_build_query($query);
				$url = implode('?', $url);
			}

			e107::redirect($url);
		}
		else
		{
			// Non-progressive execution: bypass the whole progressbar workflow
			// and execute the batch in one pass.
			_batch_process();
		}
	}
}

/**
 * Retrieves the current batch.
 */
function &batch_get()
{
	static $batch = array();
	return $batch;
}

/**
 * Populates a job queue with the operations of a batch set.
 *
 * Depending on whether the batch is progressive or not, the BatchQueue or
 * BatchMemoryQueue handler classes will be used.
 *
 * @param $batch
 *   The batch array.
 * @param $set_id
 *   The id of the set to process.
 *
 * @return
 *   The name and class of the queue are added by reference to the batch set.
 */
function _batch_populate_queue(&$batch, $set_id)
{
	$batch_set = &$batch['sets'][$set_id];

	if(isset($batch_set['operations']))
	{
		$batch_set += array(
			'queue' => array(
				'name'  => 'e107_batch:' . $batch['id'] . ':' . $set_id,
				'class' => $batch['progressive'] ? 'BatchQueue' : 'BatchMemoryQueue',
			),
		);

		$queue = _batch_queue($batch_set);
		$queue->createQueue();

		foreach($batch_set['operations'] as $operation)
		{
			$queue->createItem($operation);
		}

		unset($batch_set['operations']);
	}
}

/**
 * Returns a queue object for a batch set.
 *
 * @param $batch_set
 *   The batch set.
 *
 * @return
 *   The queue object.
 */
function _batch_queue($batch_set)
{
	static $queues;

	if(!isset($queues))
	{
		$queues = array();
		e107_require_once(e_PLUGIN . 'batch/includes/batch.queue.php');
	}

	if(isset($batch_set['queue']))
	{
		$name = $batch_set['queue']['name'];
		$class = $batch_set['queue']['class'];

		if(!isset($queues[$class][$name]))
		{
			$queues[$class][$name] = new $class($name);
		}

		return $queues[$class][$name];
	}
}


/**
 * Loads a batch from the database.
 *
 * @param $id
 *   The ID of the batch to load. When a progressive batch is being processed,
 *   the relevant ID is found in $_REQUEST['id'].
 *
 * @return
 *   An array representing the batch, or FALSE if no batch was found.
 */
function batch_load($id)
{
	e107_require_once(e_PLUGIN . 'batch/includes/batch.token.php');

	$token = batch_get_token($id);

	$db = e107::getDb('BatchLoad');
	$batch = $db->retrieve('batch', 'batch_data', 'batch_id = ' . (int) $id . ' AND batch_token = "' . $token . '"');

	if($batch)
	{
		return unserialize(base64_decode($batch));
	}

	return false;
}

/**
 * Renders the batch processing page based on the current state of the batch.
 *
 * @see _batch_shutdown()
 */
function _batch_page()
{
	$batch = &batch_get();

	if(!isset($_REQUEST['id']))
	{
		return false;
	}

	// Retrieve the current state of the batch.
	if(!$batch)
	{
		$batch = batch_load($_REQUEST['id']);

		if(!$batch)
		{
			e107::getMessage()->add('No active batch.', E_MESSAGE_ERROR, true);
			e107::redirect();
		}
	}

	// Register database update for the end of processing.
	register_shutdown_function('_batch_shutdown');

	// Add batch-specific CSS.
	foreach($batch['sets'] as $batch_set)
	{
		if(isset($batch_set['css']))
		{
			foreach($batch_set['css'] as $css)
			{
				e107::css('url', $css);
			}
		}
	}

	$op = isset($_REQUEST['op']) ? $_REQUEST['op'] : '';
	$output = null;
	switch($op)
	{
		case 'start':
			$output = _batch_start();
			break;

		case 'do':
			// JavaScript-based progress page callback.
			_batch_do();
			break;

		case 'finished':
			_batch_finished();
			break;
	}

	return $output;
}

/**
 * Initializes the batch processing.
 */
function _batch_start()
{
	// TODO support no-JS?
	return _batch_progress_page();
}

/**
 * Outputs a batch processing page with JavaScript support.
 *
 * This initializes the batch and error messages. Note that in JavaScript-based
 * processing, the batch processing page is displayed only once and updated via
 * Ajax requests, so only the first batch set gets to define the page title.
 * Titles specified by subsequent batch sets are not displayed.
 *
 * @see batch_set()
 * @see _batch_do()
 */
function _batch_progress_page()
{
	$batch = batch_get();

	$current_set = _batch_current_set();
	$caption = $current_set['title'];

	// Merge required query parameters for batch processing into those provided by
	// batch_set() or hook_batch_alter().
	$batch['url_options']['query']['id'] = $batch['id'];

	$url = e107::url('batch', 'batch', array(), $batch['url_options']);

	$js_setting = array(
		'batch' => array(
			'errorMessage' => $current_set['error_message'] . '<br />' . $batch['error_message'],
			'initMessage'  => $current_set['init_message'],
			'uri'          => $url,
		),
	);

	e107::js('settings', $js_setting);
	e107::js('batch', 'js/batch.js');

	return array(
		'caption' => $caption,
		'content' => '<div id="progress"></div>',
	);
}

/**
 * Does one execution pass with JavaScript and returns progress to the browser.
 *
 * @see _batch_progress_page()
 * @see _batch_process()
 */
function _batch_do()
{
	// HTTP POST required.
	if($_SERVER['REQUEST_METHOD'] != 'POST')
	{
		// TODO
		e107::getMessage()->add('HTTP POST is required.', E_MESSAGE_ERROR, true);
		$caption = 'Error';
		return '';
	}

	// Perform actual processing.
	list($percentage, $message) = _batch_process();

	e107::getAjax()->response(array(
		'status'     => true,
		'percentage' => $percentage,
		'message'    => $message,
	));
}

/**
 * Starts the timer with the specified name.
 *
 * If you start and stop the same timer multiple times, the measured
 * intervals will be accumulated.
 *
 * @param $name
 *  The name of the timer.
 */
function batch_timer_start($name)
{
	global $batch_timers;

	$batch_timers[$name]['start'] = microtime(true);
	$batch_timers[$name]['count'] = isset($batch_timers[$name]['count']) ? ++$batch_timers[$name]['count'] : 1;
}

/**
 * Reads the current timer value without stopping the timer..
 *
 * @param $name
 *  The name of the timer.
 *
 * @return float
 *  The current timer value in ms.
 */
function batch_timer_read($name)
{
	global $batch_timers;

	if(isset($batch_timers[$name]['start']))
	{
		$stop = microtime(true);
		$diff = round(($stop - $batch_timers[$name]['start']) * 1000, 2);

		if(isset($batch_timers[$name]['time']))
		{
			$diff += $batch_timers[$name]['time'];
		}
		return $diff;
	}
	return $batch_timers[$name]['time'];
}

/**
 * Processes sets in a batch.
 *
 * If the batch was marked for progressive execution (default), this executes as
 * many operations in batch sets until an execution time of 1 second has been
 * exceeded. It will continue with the next operation of the same batch set in
 * the next request.
 *
 * @return array
 *   An array containing a completion value (in percent) and a status message.
 */
function _batch_process()
{
	$batch = &batch_get();
	$current_set = &_batch_current_set();
	// Indicate that this batch set needs to be initialized.
	$set_changed = true;

	// If this batch was marked for progressive execution, initialize a timer to
	// determine whether we need to proceed with the same batch phase when a
	// processing time of 1 second has been exceeded.
	if($batch['progressive'])
	{
		batch_timer_start('batch_processing');
	}

	if(empty($current_set['start']))
	{
		$current_set['start'] = microtime(true);
	}

	$queue = _batch_queue($current_set);

	while(!$current_set['success'])
	{
		// If this is the first time we iterate this batch set in the current
		// request, we check if it requires an additional file for functions
		// definitions.
		if($set_changed && isset($current_set['file']))
		{
			$tp = e107::getParser();
			$file = $tp->replaceConstants($current_set['file']);

			if(is_file($file))
			{
				e107_include_once($file);
			}
		}

		$task_message = '';
		// Assume a single pass operation and set the completion level to 1 by
		// default.
		$finished = 1;

		$item = $queue->claimItem();

		if($item)
		{
			list($function, $args) = $item['queue_data'];

			// Build the 'context' array and execute the function call.
			$batch_context = array(
				'sandbox'  => &$current_set['sandbox'],
				'results'  => &$current_set['results'],
				'finished' => &$finished,
				'message'  => &$task_message,
			);

			call_user_func_array($function, array_merge($args, array(&$batch_context)));

			if($finished >= 1)
			{
				// Make sure this step is not counted twice when computing $current.
				$finished = 0;
				// Remove the processed operation and clear the sandbox.
				$queue->deleteItem($item);
				$current_set['count']--;
				$current_set['sandbox'] = array();
			}
		}

		// When all operations in the current batch set are completed, browse
		// through the remaining sets, marking them 'successfully processed'
		// along the way, until we find a set that contains operations.
		// _batch_next_set() executes form submit handlers stored in 'control'
		// sets, which can in turn add new sets to the batch.
		$set_changed = false;
		$old_set = $current_set;
		while(empty($current_set['count']) && ($current_set['success'] = true) && _batch_next_set())
		{
			$current_set = &_batch_current_set();
			$current_set['start'] = microtime(true);
			$set_changed = true;
		}

		// At this point, either $current_set contains operations that need to be
		// processed or all sets have been completed.
		$queue = _batch_queue($current_set);

		// If we are in progressive mode, break processing after 1 second.
		if($batch['progressive'] && batch_timer_read('batch_processing') > 1000)
		{
			// Record elapsed wall clock time.
			$current_set['elapsed'] = round((microtime(true) - $current_set['start']) * 1000, 2);
			break;
		}
	}

	if($batch['progressive'])
	{
		// Gather progress information.

		// Reporting 100% progress will cause the whole batch to be considered
		// processed. If processing was paused right after moving to a new set,
		// we have to use the info from the new (unprocessed) set.
		if($set_changed && isset($current_set['queue']))
		{
			// Processing will continue with a fresh batch set.
			$remaining = $current_set['count'];
			$total = $current_set['total'];
			$progress_message = $current_set['init_message'];
			$task_message = '';
		}
		else
		{
			// Processing will continue with the current batch set.
			$remaining = $old_set['count'];
			$total = $old_set['total'];
			$progress_message = $old_set['progress_message'];
		}

		// Total progress is the number of operations that have fully run plus the
		// completion level of the current operation.
		$current = $total - $remaining + $finished;
		$percentage = _batch_api_percentage($total, $current);
		$elapsed = isset($current_set['elapsed']) ? $current_set['elapsed'] : 0;
		$values = array(
			'@remaining'  => $remaining,
			'@total'      => $total,
			'@current'    => floor($current),
			'@percentage' => $percentage,
			'@elapsed'    => e107::getParser()->toDate($elapsed / 1000, 'relative'),
			// If possible, estimate remaining processing time.
			'@estimate'   => ($current > 0) ? e107::getParser()->toDate(($elapsed * ($total - $current) / $current) / 1000, 'relative') : '-',
		);
		$message = strtr($progress_message, $values);
		if(!empty($message))
		{
			$message .= '<br />';
		}
		if(!empty($task_message))
		{
			$message .= $task_message;
		}

		return array($percentage, $message);
	}
	else
	{
		// If we are not in progressive mode, the entire batch has been processed.
		return _batch_finished();
	}
}

/**
 * Formats the percent completion for a batch set.
 *
 * @param $total
 *   The total number of operations.
 * @param $current
 *   The number of the current operation. This may be a floating point number
 *   rather than an integer in the case of a multi-step operation that is not
 *   yet complete; in that case, the fractional part of $current represents the
 *   fraction of the operation that has been completed.
 *
 * @return
 *   The properly formatted percentage, as a string. We output percentages
 *   using the correct number of decimal places so that we never print "100%"
 *   until we are finished, but we also never print more decimal places than
 *   are meaningful.
 *
 * @see _batch_process()
 */
function _batch_api_percentage($total, $current)
{
	if(!$total || $total == $current)
	{
		// If $total doesn't evaluate as true or is equal to the current set, then
		// we're finished, and we can return "100".
		$percentage = "100";
	}
	else
	{
		// We add a new digit at 200, 2000, etc. (since, for example, 199/200
		// would round up to 100% if we didn't).
		$decimal_places = max(0, floor(log10($total / 2.0)) - 1);
		do
		{
			// Calculate the percentage to the specified number of decimal places.
			$percentage = sprintf('%01.' . $decimal_places . 'f', round($current / $total * 100, $decimal_places));
			// When $current is an integer, the above calculation will always be
			// correct. However, if $current is a floating point number (in the case
			// of a multi-step batch operation that is not yet complete), $percentage
			// may be erroneously rounded up to 100%. To prevent that, we add one
			// more decimal place and try again.
			$decimal_places++;
		}
		while($percentage == '100');
	}
	return $percentage;
}

/**
 * Returns the batch set being currently processed.
 */
function &_batch_current_set()
{
	$batch = &batch_get();
	return $batch['sets'][$batch['current_set']];
}

/**
 * Retrieves the next set in a batch.
 *
 * If there is a subsequent set in this batch, assign it as the new set to
 * process and execute its form submit handler (if defined), which may add
 * further sets to this batch.
 *
 * @return
 *   TRUE if a subsequent set was found in the batch.
 */
function _batch_next_set()
{
	$batch = &batch_get();
	if(isset($batch['sets'][$batch['current_set'] + 1]))
	{
		$batch['current_set']++;
		$current_set = &_batch_current_set();
		if(isset($current_set['form_submit']) && ($function = $current_set['form_submit']) && function_exists($function))
		{
			// We use our stored copies of $form and $form_state to account for
			// possible alterations by previous form submit handlers.
			$function($batch['form_state']['complete form'], $batch['form_state']);
		}
		return true;
	}
}

/**
 * Ends the batch processing.
 *
 * Call the 'finished' callback of each batch set to allow custom handling of
 * the results and resolve page redirection.
 */
function _batch_finished()
{
	$batch = &batch_get();

	// Execute the 'finished' callbacks for each batch set, if defined.
	foreach($batch['sets'] as $batch_set)
	{
		if(isset($batch_set['finished']))
		{
			$tp = e107::getParser();

			// Check if the set requires an additional file for function definitions.
			if(isset($batch_set['file']))
			{
				$file = $tp->replaceConstants($batch_set['file']);

				if(is_file($file))
				{
					e107_include_once($file);
				}
			}

			if(is_callable($batch_set['finished']))
			{
				$queue = _batch_queue($batch_set);
				$operations = $queue->getAllItems();
				call_user_func($batch_set['finished'], $batch_set['success'], $batch_set['results'], $operations, $tp->toDate($batch_set['elapsed'] / 1000, 'relative'));
			}
		}
	}

	// Clean up the batch table and unset the static $batch variable.
	if($batch['progressive'])
	{
		$db = e107::getDb('BatchDelete');
		$db->delete('batch', 'batch_id = ' . (int) $batch['id']);

		foreach($batch['sets'] as $batch_set)
		{
			if($queue = _batch_queue($batch_set))
			{
				$queue->deleteQueue();
			}
		}
	}
	$_batch = $batch;
	$batch = null;

	// Clean-up the session. Not needed for CLI updates.
	if(isset($_SESSION))
	{
		unset($_SESSION['batches'][$batch['id']]);
		if(empty($_SESSION['batches']))
		{
			unset($_SESSION['batches']);
		}
	}

	// Redirect if needed.
	if($_batch['progressive'])
	{
		// Revert the 'destination' that was saved in batch_process().
		if(isset($_batch['destination']))
		{
			$_GET['destination'] = $_batch['destination'];
		}

		$url = !empty($_batch['redirect']) ? $_batch['redirect'] : $_batch['source_url'];

		e107::redirect($url . '?' . http_build_query(array(
				'op' => 'finish',
				'id' => $_batch['id'],
			)));
	}
}

/**
 * Shutdown function: Stores the current batch data for the next request.
 *
 * @see _batch_page()
 */
function _batch_shutdown()
{
	if($batch = batch_get())
	{
		$db = e107::getDb('BatchUpdate');

		$update = array(
			'data'  => array(
				'batch_data' => base64_encode(serialize($batch)),
			),
			'WHERE' => 'batch_id = ' . (int) $batch['id'],
		);

		$db->update('batch', $update, false);
	}
}
