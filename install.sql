-- MySQL dump 10.13  Distrib 5.7.19, for Linux (x86_64)
--
-- Host: localhost    Database: mw_test_libretabbt
-- ------------------------------------------------------
-- Server version	5.7.19-0ubuntu0.16.04.1


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

--
-- Table structure for table `expense`
--

CREATE TABLE IF NOT EXISTS `expense` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'Expense',
  `who_paid` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `FK_expense_group_member` (`who_paid`),
  KEY `FK_expense_group` (`group_id`),
  CONSTRAINT `FK_expense_group` FOREIGN KEY (`group_id`) REFERENCES `group` (`id`),
  CONSTRAINT `FK_expense_group_member` FOREIGN KEY (`who_paid`) REFERENCES `group_member` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `expense_split_user`
--

CREATE TABLE IF NOT EXISTS `expense_split_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_expense_split_user_expense` (`expense_id`),
  KEY `FK_expense_split_user_group_member` (`member_id`),
  CONSTRAINT `FK_expense_split_user_expense` FOREIGN KEY (`expense_id`) REFERENCES `expense` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_expense_split_user_group_member` FOREIGN KEY (`member_id`) REFERENCES `group_member` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `group`
--

CREATE TABLE IF NOT EXISTS `group` (
  `id` varchar(36) NOT NULL,
  `name` varchar(50) NOT NULL,
  `color` varchar(6) NOT NULL,
  `comment` text NOT NULL,
  `readonly_token` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `group_member`
--

CREATE TABLE IF NOT EXISTS `group_member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(36) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notifications` tinyint(4) NOT NULL DEFAULT '1',
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

--
-- Table structure for table `login_token`
--

CREATE TABLE IF NOT EXISTS `login_token` (
  `token` varchar(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`token`),
  KEY `FK__user__logintoken` (`user_id`),
  CONSTRAINT `FK__user__logintoken` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `papertrail`
--

CREATE TABLE IF NOT EXISTS `papertrail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) NOT NULL,
  `group_id` varchar(36) DEFAULT NULL,
  `action` varchar(36) NOT NULL,
  `object_type` varchar(36) NOT NULL,
  `repr` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `FK__user` (`actor_user_id`),
  KEY `FK__group` (`group_id`),
  CONSTRAINT `FK__group` FOREIGN KEY (`group_id`) REFERENCES `group` (`id`),
  CONSTRAINT `FK__user` FOREIGN KEY (`actor_user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `user`
--

CREATE TABLE IF NOT EXISTS `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL DEFAULT '',
  `email` varchar(75) NOT NULL DEFAULT '',
  `openid` varchar(255) NOT NULL,
  `last_login_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dump completed on 2017-09-04 19:47:05
