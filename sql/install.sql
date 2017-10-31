CREATE TABLE `civicrm_cardvault` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Entry ID',
  `contribution_id` int(10) unsigned DEFAULT NULL COMMENT 'CiviCRM contribution ID' REFERENCES civicrm_contribution(id),
  `invoice_id` varchar(255) DEFAULT NULL COMMENT 'Contribution invoice ID, implicit reference to contribution',
  `contact_id` int(10) unsigned NOT NULL COMMENT 'CiviCRM contact ID' REFERENCES civicrm_contact(id),
  `created_date` timestamp NOT NULL default CURRENT_TIMESTAMP  COMMENT 'Timestamp of when record was added',
  `ccinfo` text COMMENT 'Credit card information',
  `hash` varchar(128) DEFAULT NULL COMMENT 'Credit card information hash',
  `token` varchar(128) DEFAULT NULL COMMENT 'Credit card token',
  `expiry_date` datetime DEFAULT NULL COMMENT 'Date this card expires',
  `masked_account_number` varchar(255) DEFAULT NULL COMMENT 'Holds the part of the card number or account details that may be retained or displayed',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Keeps transaction information about recurring transactions.'
