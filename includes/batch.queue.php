<?php

/**
 * @file
 * Queue handlers used by the Batch API.
 *
 * These implementations:
 * - Ensure FIFO ordering.
 * - Allow an item to be repeatedly claimed until it is actually deleted (no
 *   notion of lease time or 'expire' date), to allow multi-pass operations.
 */

if(!defined('e107_INIT'))
{
	require_once('../../../class2.php');
}


/**
 * Defines a batch queue.
 *
 * Stale items from failed batches are cleaned from the {queue} table on cron
 * using the 'created' date.
 */
class BatchQueue
{

	/**
	 * The name of the queue this instance is working with.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Start working with a queue.
	 *
	 * @param $name
	 *   Arbitrary string. The name of the queue to work with.
	 */
	public function __construct($name)
	{
		$this->name = $name;
	}

	/**
	 * Add a queue item and store it directly to the queue.
	 *
	 * @param $data
	 *   Arbitrary data to be associated with the new task in the queue.
	 * @return bool
	 *   TRUE if the item was successfully created and was (best effort) added
	 *   to the queue, otherwise FALSE. We don't guarantee the item was
	 *   committed to disk etc, but as far as we know, the item is now in the
	 *   queue.
	 */
	public function createItem($data)
	{
		$db = e107::getDb('BatchQueueCreateItem');

		$insert = array(
			'data' => array(
				'queue_name'    => $this->name,
				'queue_data'    => base64_encode(serialize($data)),
				// We cannot rely on REQUEST_TIME because many items might be created
				// by a single request which takes longer than 1 second.
				'queue_created' => time(),
			),
		);

		return (bool) $db->insert('queue', $insert, false);
	}

	/**
	 * Retrieve the number of items in the queue.
	 *
	 * This is intended to provide a "best guess" count of the number of items in
	 * the queue. Depending on the implementation and the setup, the accuracy of
	 * the results of this function may vary.
	 *
	 * e.g. On a busy system with a large number of consumers and items, the
	 * result might only be valid for a fraction of a second and not provide an
	 * accurate representation.
	 *
	 * @return int
	 *   An integer estimate of the number of items in the queue.
	 */
	public function numberOfItems()
	{
		$db = e107::getDb('BatchQueueNumberOfItems');
		$tp = e107::getParser();

		$count = $db->count('queue', '(queue_id)', 'queue_name = "' . $tp->toDB($this->name) . '"');

		return $count;
	}

	/**
	 * Release an item that the worker could not process, so another
	 * worker can come in and process it before the timeout expires.
	 *
	 * @param $item
	 * @return boolean
	 */
	public function releaseItem($item)
	{
		$db = e107::getDb('BatchQueueReleaseItem');

		$update = array(
			'data'  => array(
				'queue_expire' => 0,
			),
			'WHERE' => 'queue_id = ' . (int) $item->item_id,
		);

		return (bool) $db->update('queue', $update, false);
	}

	/**
	 * Delete a finished item from the queue.
	 *
	 * @param $item
	 */
	public function deleteItem($item)
	{
		$db = e107::getDb('BatchQueueDeleteItem');
		$db->delete('queue', 'queue_id = ' . (int) $item->item_id);
	}

	/**
	 * Create a queue.
	 *
	 * Called during installation and should be used to perform any necessary
	 * initialization operations. This should not be confused with the
	 * constructor for these objects, which is called every time an object is
	 * instantiated to operate on a queue. This operation is only needed the
	 * first time a given queue is going to be initialized (for example, to make
	 * a new database table or directory to hold tasks for the queue -- it
	 * depends on the queue implementation if this is necessary at all).
	 */
	public function createQueue()
	{
		// All tasks are stored in a single database table (which is created when
		// plugin is installed) so there is nothing we need to do to create a new
		// queue.
	}

	/**
	 * Delete a queue and every item in the queue.
	 */
	public function deleteQueue()
	{
		$tp = e107::getParser();
		$db = e107::getDb('BatchQueueDeleteQueue');
		$db->delete('queue', 'queue_name = "' . $tp->toDB($this->name) . '"');
	}

	/**
	 * This method allows the item to be claimed repeatedly until it is deleted.
	 */
	public function claimItem()
	{
		$tp = e107::getParser();
		$db = e107::getDb('BatchQueueClaimItem');
		$db->select('queue', 'queue_id, queue_data', 'queue_name = "' . $tp->toDB($this->name) . '" ORDER BY queue_id ASC LIMIT 1');

		$item = array();
		while($row = $db->fetch())
		{
			$item = $row;
		}

		if(isset($item['queue_data']))
		{
			$item['queue_data'] = unserialize(base64_decode($item['queue_data']));
			return $item;
		}

		return false;
	}

	/**
	 * Retrieves all remaining items in the queue.
	 */
	public function getAllItems()
	{
		$tp = e107::getParser();
		$db = e107::getDb('BatchQueueGetAllItems');
		$db->retrieve('queue', 'queue_data', 'queue_name = "' . $tp->toDB($this->name) . '" ORDER BY queue_id ASC');

		$result = array();

		while($item = $db->fetch())
		{
			$result[] = unserialize(base64_decode($item['data']));
		}

		return $result;
	}
}


/**
 * Defines a batch queue for non-progressive batches.
 */
class BatchMemoryQueue
{

	/**
	 * The queue data.
	 *
	 * @var array
	 */
	protected $queue;

	/**
	 * Counter for item ids.
	 *
	 * @var int
	 */
	protected $id_sequence;

	/**
	 * Start working with a queue.
	 *
	 * @param $name
	 *   Arbitrary string. The name of the queue to work with.
	 */
	public function __construct($name)
	{
		$this->queue = array();
		$this->id_sequence = 0;
	}

	/**
	 * Add a queue item and store it directly to the queue.
	 *
	 * @param $data
	 *   Arbitrary data to be associated with the new task in the queue.
	 * @return bool
	 *   TRUE if the item was successfully created and was (best effort) added
	 *   to the queue, otherwise FALSE. We don't guarantee the item was
	 *   committed to disk etc, but as far as we know, the item is now in the
	 *   queue.
	 */
	public function createItem($data)
	{
		$item = array(
			'queue_id'      => $this->id_sequence++,
			'queue_name'    => '',
			'queue_data'    => $data,
			'queue_expire'  => 0,
			'queue_created' => time(),
		);

		$this->queue[$item['queue_id']] = $item;

		return true;
	}

	/**
	 * Retrieve the number of items in the queue.
	 *
	 * This is intended to provide a "best guess" count of the number of items in
	 * the queue. Depending on the implementation and the setup, the accuracy of
	 * the results of this function may vary.
	 *
	 * e.g. On a busy system with a large number of consumers and items, the
	 * result might only be valid for a fraction of a second and not provide an
	 * accurate representation.
	 *
	 * @return int
	 *   An integer estimate of the number of items in the queue.
	 */
	public function numberOfItems()
	{
		return count($this->queue);
	}

	/**
	 * Delete a finished item from the queue.
	 *
	 * @param $item
	 */
	public function deleteItem($item)
	{
		unset($this->queue[$item['queue_id']]);
	}

	/**
	 * Release an item that the worker could not process, so another worker can
	 * come in and process it before the timeout expires.
	 *
	 * @param $item
	 * @return boolean
	 */
	public function releaseItem($item)
	{
		if(isset($this->queue[$item['queue_id']]) && $this->queue[$item['queue_id']]['queue_expire'] != 0)
		{
			$this->queue[$item['queue_id']]['queue_expire'] = 0;

			return true;
		}

		return false;
	}

	/**
	 * Create a queue.
	 *
	 * Called during installation and should be used to perform any necessary
	 * initialization operations. This should not be confused with the
	 * constructor for these objects, which is called every time an object is
	 * instantiated to operate on a queue. This operation is only needed the
	 * first time a given queue is going to be initialized (for example, to make
	 * a new database table or directory to hold tasks for the queue -- it
	 * depends on the queue implementation if this is necessary at all).
	 */
	public function createQueue()
	{
		// Nothing needed here.
	}

	/**
	 * Delete a queue and every item in the queue.
	 */
	public function deleteQueue()
	{
		$this->queue = array();
		$this->id_sequence = 0;
	}

	/**
	 * This method allows the item to be claimed repeatedly until it is deleted.
	 *
	 * @return bool
	 *   On success we return an item object. If the queue is unable to claim an
	 *   item it returns false. This implies a best effort to retrieve an item
	 *   and either the queue is empty or there is some other non-recoverable
	 *   problem.
	 */
	public function claimItem()
	{
		if(!empty($this->queue))
		{
			reset($this->queue);

			return current($this->queue);
		}

		return false;
	}

	/**
	 * Retrieves all remaining items in the queue.
	 */
	public function getAllItems()
	{
		$result = array();

		foreach($this->queue as $item)
		{
			$result[] = $item['queue_data'];
		}

		return $result;
	}
}
