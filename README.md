# Batch API

> e107 (v2) plugin - API for Batch operations.

Batch processing API for processes to run in multiple HTTP requests.

**Example**:
```php
$batch = array(
	'title' => t('Exporting'),
	'operations' => array(
		array('my_function_1', array($author, 'story')),
		array('my_function_2', array()),
	),
	'finished' => 'my_finished_callback',
	'file' => '{e_PLUGIN}plugin/path/file.php',
);

batch_set($batch);
// Setting redirect in batch_process.
batch_process(e_HTTP);
```

**Note**: if the batch 'title', 'init_message', 'progress_message', or 'error_message' could contain any user input, it is the responsibility of the code calling `batch_set($batch);` to sanitize them first with a function like `e107::getParser()->filter()`. Furthermore, if the batch operation returns any user input in the 'results' or 'message' keys of $context, it must also sanitize them first.

Batch API operations are added as new batch sets. Batch sets are used to spread processing over several page requests. This helps to ensure that the processing is not interrupted due to PHP timeouts, while users are still able to receive feedback on the progress of the ongoing operations. Combining related operations into distinct batch sets provides clean code independence for each batch set, ensuring that two or more batches, submitted independently, can be processed without mutual interference. Each batch set may specify its own set of operations and results, produce its own UI messages, and trigger its own 'finished' callback. Batch sets are processed sequentially, with the progress bar starting afresh for each new set.

For more details please see `example.php` file.

### Screenshots

![In action](https://www.dropbox.com/s/msya3noqmvl8f3y/01.png?dl=1)

![Finished](https://www.dropbox.com/s/wtbbua9nuerrvxt/02.png?dl=1)

## Support on Beerpay
Hey dude! Help me out for a couple of :beers:!

[![Beerpay](https://beerpay.io/lonalore/batch/badge.svg?style=beer-square)](https://beerpay.io/lonalore/batch)  [![Beerpay](https://beerpay.io/lonalore/batch/make-wish.svg?style=flat-square)](https://beerpay.io/lonalore/batch?focus=wish)
