START TRANSACTION;

-- Define the prefix
SET @prefix = 'test1';

-- Create Users table
SET @table_structure = "
CREATE TABLE %susers (
  `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(60) COLLATE utf8_unicode_ci NOT NULL,
  `alias` varchar(60) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(80) COLLATE utf8_unicode_ci NOT NULL,
  `session_id` varchar(60) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL,
  `is_crew` tinyint(1) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `is_password_reset` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `preferences` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Conversations table
SET @table_structure = "
CREATE TABLE %sconversations (
  `conversation_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(130) COLLATE utf8_unicode_ci NOT NULL,
  `parent_conversation_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT NOW(),
  `last_message` datetime NOT NULL DEFAULT NOW(),
  PRIMARY KEY (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Participants table
SET @table_structure = "
CREATE TABLE %sparticipants (
  `conversation_id` int(10) UNSIGNED NOT NULL ,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Participant',
  PRIMARY KEY (`conversation_id`, `user_id`),
  FOREIGN KEY(`conversation_id`) REFERENCES %sconversations(`conversation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY(`user_id`) REFERENCES %susers(`user_id`) ON DELETE CASCADE ON UPDATE CASCADE  
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Messages table
SET @table_structure = "
CREATE TABLE %smessages (
  `message_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Author',
  `conversation_id` int(10) UNSIGNED NOT NULL ,
  `text` text CHARACTER SET utf8 DEFAULT NULL,
  `type` enum('text','important','video','audio','file') COLLATE utf8_unicode_ci NOT NULL,
  `from_crew` tinyint(1) NOT NULL,
  `message_id_alt` int(10) UNSIGNED DEFAULT NULL,
  `recv_time_hab` datetime NOT NULL,
  `recv_time_mcc` datetime NOT NULL,
  PRIMARY KEY(`message_id`, `user_id`, `conversation_id`),
  FOREIGN KEY(`conversation_id`) REFERENCES %sconversations(`conversation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY(`user_id`) REFERENCES %susers(`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Msg Status table
SET @table_structure = "
CREATE TABLE %smsg_status (
  `message_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Recipient',
  PRIMARY KEY(`message_id`, `user_id`),
  FOREIGN KEY(`user_id`) REFERENCES %susers(`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY(`message_id`) REFERENCES %smessages(`message_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Msg Files table
SET @table_structure = "
CREATE TABLE %smsg_files (
  `message_id` int(10) UNSIGNED NOT NULL,
  `server_name` text CHARACTER SET utf8 NOT NULL,
  `original_name` text CHARACTER SET utf8 NOT NULL,
  `mime_type` text CHARACTER SET utf8 NOT NULL,
  PRIMARY KEY(`message_id`),
  FOREIGN KEY(`message_id`) REFERENCES %smessages(`message_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Mission Config table
SET @table_structure = "
CREATE TABLE %smission_config (  `name` varchar(32) COLLATE utf8_unicode_ci NOT NULL UNIQUE,
  `value` text CHARACTER SET utf8 NOT NULL,
  `type` enum('string','int','float','bool', 'json') COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY(`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create Mission Archives table
SET @table_structure = "
CREATE TABLE %smission_archives (  `archive_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `server_name` text CHARACTER SET utf8 NOT NULL,
  `notes` text CHARACTER SET utf8 NOT NULL,
  `mime_type` text CHARACTER SET utf8 NOT NULL,
  `timestamp` datetime NOT NULL,
  `content_tz` varchar(64) COLLATE utf8_unicode_ci NOT NULL, 
  PRIMARY KEY(`archive_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";
SET @sql = REPLACE(@table_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @insert_structure = "
INSERT INTO `%smission_config` (`name`, `type`, `value`) VALUES
('name',               'string', 'Analog Mission Name'),
('date_start',         'string', '2021-08-10 00:00:00'),
('date_end',           'string', '2021-11-10 00:00:00'),
('mcc_name',           'string', 'Mission Control'),
('mcc_planet',         'string', 'Earth'),
('mcc_user_role',      'string', 'Mission Control'),
('mcc_timezone',       'string', 'America/New_York'),
('hab_name',           'string', 'Analog Habitat'),
('hab_planet',         'string', 'Mars'),
('hab_user_role',      'string', 'Astronaut'),
('hab_timezone',       'string', 'America/Chicago'),
('hab_day_name',       'string', 'Mission Day'),
('delay_type',         'string', 'manual'),
('delay_config',       'json', '[{\"ts\":\"2021-01-01 00:00:00\",\"eq\":0}]'),
('login_timeout',      'int',    '3600'),
('feat_audio_notification',  'bool', '1'),
('feat_badge_notification',  'bool', '1'),
('feat_unread_msg_counts',   'bool', '1'),
('feat_convo_list_order',    'bool', '1'),
('feat_est_delivery_status', 'bool', '1'),
('feat_progress_bar',        'bool', '1'),
('feat_markdown_support',    'bool', '1'),
('feat_important_msgs',      'bool', '1'),
('feat_convo_threads',       'bool', '1'), 
('feat_convo_threads_all',   'bool', '1'),
('feat_out_of_seq',          'bool', '1'),
('debug',                    'bool', '0');
";
SET @sql = REPLACE(@insert_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @insert_structure = "
INSERT INTO `%susers` (`user_id`, `username`, `alias`, `password`, `session_id`, `is_admin`, `is_crew`, `last_login`, `is_password_reset`, `preferences`) VALUES
(1, 'admin', 'Admin', '2bb80d537b1da3e38bd30361aa855686bde0eacd7162fef6a25fe97bf527a25b', NULL, 1, 0, '2021-07-23 14:52:17', 1, '');
";
SET @sql = REPLACE(@insert_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @insert_structure = "
INSERT INTO `%sconversations` (`conversation_id`, `name`, `parent_conversation_id`, `date_created`, `last_message`) VALUES
(1, 'Mission Chat', NULL, '2021-07-23 14:57:49', NOW());
";
SET @sql = REPLACE(@insert_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @insert_structure = "
INSERT INTO `%sparticipants` (`conversation_id`, `user_id`) VALUES
(1, 1);
";
SET @sql = REPLACE(@insert_structure, '%s', @prefix);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;