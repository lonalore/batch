CREATE TABLE `batch` (
`batch_id` int(10) UNSIGNED NOT NULL COMMENT 'Primary Key. Unique batch ID.',
`batch_token` varchar(64) NOT NULL COMMENT 'A string token generated against the current user session id and the batch id, used to ensure that only the user who submitted the batch can effectively access it.',
`batch_timestamp` int(11) NOT NULL COMMENT 'A Unix timestamp indicating when this batch was submitted for processing. Stale batches are purged at cron time.',
`batch_data` longblob COMMENT 'A serialized array containing the processing data for the batch.',
PRIMARY KEY (`batch_id`),
KEY `batch_token` (`batch_token`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `queue` (
`queue_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary Key. Unique ID.',
`queue_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'The queue name.',
`queue_data` longblob COMMENT 'The arbitrary data for the item.',
`queue_expire` int(11) NOT NULL DEFAULT '0' COMMENT 'Timestamp when the claim lease expires on the item.',
`queue_created` int(11) NOT NULL DEFAULT '0' COMMENT 'Timestamp when the item was created.',
PRIMARY KEY (`queue_id`),
KEY `queue_name` (`queue_name`),
KEY `queue_created` (`queue_created`),
KEY `queue_expire` (`queue_expire`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `sequences` (
`value` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The value of the sequence.',
PRIMARY KEY (`value`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
