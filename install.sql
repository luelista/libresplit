-- --------------------------------------------------------
-- LibreSplit SQL database initialisation
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

-- Exportiere Struktur von Tabelle expense
CREATE TABLE IF NOT EXISTS `expense` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'Expense',
  `who_paid` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_expense_group_member` (`who_paid`),
  KEY `FK_expense_group` (`group_id`),
  CONSTRAINT `FK_expense_group` FOREIGN KEY (`group_id`) REFERENCES `group` (`id`),
  CONSTRAINT `FK_expense_group_member` FOREIGN KEY (`who_paid`) REFERENCES `group_member` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




-- Exportiere Struktur von Tabelle expense_split_user
CREATE TABLE IF NOT EXISTS `expense_split_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `weight` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `FK_expense_split_user_expense` (`expense_id`),
  KEY `FK_expense_split_user_group_member` (`member_id`),
  CONSTRAINT `FK_expense_split_user_expense` FOREIGN KEY (`expense_id`) REFERENCES `expense` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_expense_split_user_group_member` FOREIGN KEY (`member_id`) REFERENCES `group_member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




-- Exportiere Struktur von Tabelle group
CREATE TABLE IF NOT EXISTS `group` (
  `id` varchar(36) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




-- Exportiere Struktur von Tabelle group_member
CREATE TABLE IF NOT EXISTS `group_member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(36) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `joined_at` timestamp NULL DEFAULT NULL,
  `invited_name` varchar(100) DEFAULT NULL,
  `invited_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_id_user_id` (`group_id`,`user_id`),
  KEY `FK_group_member_user` (`user_id`),
  CONSTRAINT `FK_group_member_group` FOREIGN KEY (`group_id`) REFERENCES `group` (`id`),
  CONSTRAINT `FK_group_member_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




-- Exportiere Struktur von Tabelle user
CREATE TABLE IF NOT EXISTS `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL DEFAULT '',
  `email` varchar(75) NOT NULL DEFAULT '',
  `openid` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IF(@OLD_FOREIGN_KEY_CHECKS IS NULL, 1, @OLD_FOREIGN_KEY_CHECKS) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
