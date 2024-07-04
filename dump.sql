-- MariaDB dump 10.19  Distrib 10.4.21-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: certs
-- ------------------------------------------------------
-- Server version       10.4.21-MariaDB-1:10.4.21+maria~buster-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `certs`
--

DROP TABLE IF EXISTS `certs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entryid` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Entry ID from the log',
  `logid` smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT 'Log ID from the loglist',
  `urlid` bigint(20) unsigned NOT NULL DEFAULT 1 COMMENT 'Url ID matched with URL table',
  `derid` bigint(20) unsigned DEFAULT NULL COMMENT 'Der ID matched with der table',
  PRIMARY KEY (`id`),
  UNIQUE KEY `entryid_logid_urlid` (`entryid`,`logid`,`urlid`),
  KEY `entryid` (`entryid`),
  KEY `logid` (`logid`),
  KEY `urlid` (`urlid`),
  KEY `derid` (`derid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ders`
--

DROP TABLE IF EXISTS `ders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `der` blob /*!100301 COMPRESSED*/ NOT NULL DEFAULT '',
  `hash` binary(32) GENERATED ALWAYS AS (unhex(sha2(`der`,'256'))) STORED COMMENT 'SHA-256 hash of der in binary',
  `validfrom` int(10) unsigned DEFAULT NULL,
  `validto` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`,`validto`),
  KEY `hash` (`hash`(5)) USING HASH,
  KEY `validfrom` (`validfrom`),
  KEY `validto` (`validto`)
) ENGINE=Aria DEFAULT CHARSET=utf8 COLLATE=utf8_bin
 PARTITION BY RANGE (`validto`)
(PARTITION `p2017` VALUES LESS THAN (1514761200) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2018` VALUES LESS THAN (1546297200) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2019` VALUES LESS THAN (1577833200) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2020` VALUES LESS THAN (1609455600) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2021` VALUES LESS THAN (1640991600) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2022` VALUES LESS THAN (1672527600) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2023` VALUES LESS THAN (1704063600) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2024` VALUES LESS THAN (1735686000) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2025` VALUES LESS THAN (1767222000) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2026` VALUES LESS THAN (1798758000) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2027` VALUES LESS THAN (1830294000) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2028` VALUES LESS THAN (1861916400) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2029` VALUES LESS THAN (1893452400) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2030` VALUES LESS THAN (1924988400) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria,
 PARTITION `p2031` VALUES LESS THAN (1956524400) DATA DIRECTORY = '/data/mysql' INDEX DIRECTORY = '/ssd/mysql_index' ENGINE = Aria);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `logid` smallint(5) unsigned NOT NULL,
  `start` int(10) unsigned NOT NULL,
  `end` int(10) unsigned NOT NULL,
  `added_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `claimed` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0 = False, 1 = True - Has the job been assigned?',
  `claim_time` timestamp NULL DEFAULT NULL,
  `complete` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0 = False, 1 = True, 99 = Failure - Job completation',
  `complete_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `logid` (`logid`),
  KEY `start` (`start`),
  KEY `claimed` (`claimed`) USING HASH,
  KEY `complete` (`complete`) USING HASH
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `log` tinytext COLLATE utf8_bin NOT NULL DEFAULT '',
  `last` int(10) unsigned DEFAULT 0 COMMENT 'Last received id from this log',
  `updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Last time the value was updated (Started at 2020-03-10 14:32:43)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `log` (`log`) USING HASH
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance`
--

DROP TABLE IF EXISTS `performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` int(10) unsigned NOT NULL COMMENT 'Unix timestamp',
  `jobid` int(10) unsigned DEFAULT NULL,
  `der_inserted` smallint(5) unsigned NOT NULL DEFAULT 0,
  `url` smallint(5) unsigned NOT NULL DEFAULT 0,
  `url_inserted` smallint(5) unsigned NOT NULL DEFAULT 0,
  `cert` smallint(5) unsigned NOT NULL DEFAULT 0,
  `time` mediumint(8) unsigned DEFAULT NULL COMMENT 'Time it took to finish the script in ms',
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`),
  KEY `jobid` (`jobid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `search_jobs`
--

DROP TABLE IF EXISTS `search_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `search_jobs` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `search` tinytext COLLATE utf8_bin NOT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result`)),
  `added_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `claimed` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0 = False, 1 = True - Has the job been assigned?',
  `claim_time` timestamp NULL DEFAULT NULL,
  `complete` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0 = False, 1 = True, 99 = Failure - Job completation',
  `complete_time` timestamp NULL DEFAULT NULL,
  `key` varchar(8) COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`),
  KEY `claimed` (`claimed`) USING HASH,
  KEY `complete` (`complete`) USING HASH,
  KEY `search` (`search`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `urls`
--

DROP TABLE IF EXISTS `urls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `urls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(254) COLLATE utf8_bin NOT NULL,
  `vurl` varchar(254) GENERATED ALWAYS AS (reverse(`url`)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`),
  KEY `vurl` (`vurl`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2021-11-22 19:40:16
