CREATE TABLE `civicrm_cardvault` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Entry ID',
  `contribution_id` int(11) NOT NULL COMMENT 'CiviCRM contribution ID',
  `contact_id` int(11) NOT NULL COMMENT 'CiviCRM contact ID',
  `timestamp` int(11) NOT NULL COMMENT 'A Unix timestamp indicating when this entry was added.',
  `ccinfo` text COMMENT 'Credit card information',
  `hash` varchar(128) DEFAULT NULL COMMENT 'Credit card information hash',
  `token` varchar(128) DEFAULT NULL COMMENT 'Credit card token',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Keeps transaction information about recurring transactions.'
