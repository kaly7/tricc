/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: assetmgr_db
-- ------------------------------------------------------
-- Server version	10.11.11-MariaDB-0+deb12u1

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
-- Table structure for table `asset_assignments`
--

DROP TABLE IF EXISTS `asset_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `from_employee_id` int(11) DEFAULT NULL,
  `to_employee_id` int(11) DEFAULT NULL,
  `assigned_by_user_id` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'accepted',
  `expires_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `response_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_assignments_asset` (`asset_id`),
  KEY `idx_asset_assignments_to` (`to_employee_id`),
  KEY `idx_asset_assignments_status_to` (`status`,`to_employee_id`),
  KEY `idx_asset_assignments_asset_status` (`asset_id`,`status`),
  CONSTRAINT `fk_asset_assignments_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_assignments`
--

LOCK TABLES `asset_assignments` WRITE;
/*!40000 ALTER TABLE `asset_assignments` DISABLE KEYS */;
INSERT INTO `asset_assignments` VALUES
(1,1,NULL,1,1,'2026-02-20 09:51:59',NULL,'accepted',NULL,NULL,NULL),
(2,2,NULL,1,1,'2026-02-20 11:18:44',NULL,'accepted',NULL,NULL,NULL),
(3,1,1,2,2,'2026-02-20 13:14:58',NULL,'accepted','2026-02-22 12:14:58','2026-02-20 13:22:27',NULL),
(4,2,1,2,2,'2026-02-20 13:26:12',NULL,'accepted','2026-02-22 12:26:12','2026-02-20 13:26:34',NULL),
(5,1,2,1,9,'2026-02-20 13:26:43',NULL,'rejected','2026-02-22 12:26:43','2026-02-20 13:27:05',NULL),
(6,2,2,1,9,'2026-02-20 13:26:43',NULL,'accepted','2026-02-22 12:26:43','2026-02-20 13:27:00',NULL),
(7,1,2,1,9,'2026-02-20 13:27:38',NULL,'accepted','2026-02-22 12:27:38','2026-02-20 13:27:51',NULL),
(8,2,1,2,2,'2026-02-20 13:42:43',NULL,'accepted','2026-02-22 12:42:43','2026-02-20 13:43:34',NULL),
(9,2,2,1,9,'2026-02-20 13:47:56',NULL,'accepted','2026-02-22 12:47:56','2026-02-20 13:48:40',NULL),
(10,1,1,2,2,'2026-02-20 14:28:31','Használd','accepted','2026-02-22 13:28:31','2026-02-20 14:28:50','Na... koszos volt ez a szutyok'),
(11,2,1,2,2,'2026-02-20 17:23:42','Nesze!','accepted','2026-02-22 16:23:42','2026-02-20 17:24:18','Köszi magamnak'),
(12,1,2,1,9,'2026-02-20 17:30:12',NULL,'accepted','2026-02-22 16:30:12','2026-02-20 17:30:58',NULL),
(13,2,2,1,9,'2026-02-20 17:30:12',NULL,'accepted','2026-02-22 16:30:12','2026-02-20 17:30:56',NULL),
(14,1,1,2,2,'2026-02-20 17:31:11',NULL,'expired','2026-02-22 16:31:11','2026-02-24 22:56:22','Lejárt automatikusan'),
(15,1,1,2,2,'2026-02-24 10:02:24',NULL,'accepted','2026-02-26 09:02:24','2026-02-24 10:03:22',NULL),
(16,2,1,2,2,'2026-02-24 17:18:35',NULL,'accepted','2026-02-26 16:18:35','2026-02-24 17:19:03',NULL),
(17,1,2,1,9,'2026-02-24 22:57:22','újabb teszt','accepted','2026-02-26 21:57:22','2026-02-24 22:57:54',NULL);
/*!40000 ALTER TABLE `asset_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_category`
--

DROP TABLE IF EXISTS `asset_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_category` (
  `asset_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`asset_id`,`category_id`),
  KEY `idx_ac_category` (`category_id`),
  CONSTRAINT `fk_ac_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ac_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_category`
--

LOCK TABLES `asset_category` WRITE;
/*!40000 ALTER TABLE `asset_category` DISABLE KEYS */;
INSERT INTO `asset_category` VALUES
(1,6),
(2,3);
/*!40000 ALTER TABLE `asset_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_external_assignments`
--

DROP TABLE IF EXISTS `asset_external_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_external_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `external_holder_id` int(10) unsigned NOT NULL,
  `courier_ref` varchar(190) NOT NULL,
  `note` text DEFAULT NULL,
  `assigned_by_user_id` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `returned_at` datetime DEFAULT NULL,
  `returned_by_user_id` int(11) DEFAULT NULL,
  `return_note` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_aea_asset_status` (`asset_id`,`status`),
  KEY `idx_aea_holder_status` (`external_holder_id`,`status`),
  CONSTRAINT `fk_aea_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aea_holder` FOREIGN KEY (`external_holder_id`) REFERENCES `external_holders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_external_assignments`
--

LOCK TABLES `asset_external_assignments` WRITE;
/*!40000 ALTER TABLE `asset_external_assignments` DISABLE KEYS */;
INSERT INTO `asset_external_assignments` VALUES
(1,1,1,'987654321','Mer\' kellett nekije',2,'2026-02-24 23:10:21','2026-02-24 23:17:10',1,NULL,'returned'),
(2,1,2,'1234555',NULL,9,'2026-03-03 10:15:43',NULL,NULL,NULL,'active');
/*!40000 ALTER TABLE `asset_external_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_photos`
--

DROP TABLE IF EXISTS `asset_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_photos_asset` (`asset_id`),
  KEY `idx_photos_primary` (`asset_id`,`is_primary`),
  CONSTRAINT `fk_photos_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_photos`
--

LOCK TABLES `asset_photos` WRITE;
/*!40000 ALTER TABLE `asset_photos` DISABLE KEYS */;
INSERT INTO `asset_photos` VALUES
(1,1,'/storage/uploads/assets/1/p_20260220_090618_6015e644.png',NULL,NULL,NULL,0,'2026-02-20 10:06:18'),
(2,1,'/storage/uploads/assets/1/p_20260220_090634_1e271f61.png',NULL,NULL,NULL,1,'2026-02-20 10:06:34'),
(3,1,'/storage/uploads/assets/1/p_20260220_090700_661e03c2.png',NULL,NULL,NULL,0,'2026-02-20 10:07:00');
/*!40000 ALTER TABLE `asset_photos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sku` varchar(128) DEFAULT NULL,
  `qr_value` varchar(255) DEFAULT NULL,
  `value_amount` decimal(12,2) DEFAULT NULL,
  `value_currency` char(3) DEFAULT 'HUF',
  `note` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `current_employee_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assets_name` (`name`),
  KEY `idx_assets_sku` (`sku`),
  KEY `idx_assets_qr` (`qr_value`),
  KEY `idx_assets_deleted` (`is_deleted`),
  KEY `idx_assets_current_employee` (`current_employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
INSERT INTO `assets` VALUES
(1,'akármi',NULL,NULL,NULL,'HUF','qqqqq',0,NULL,'2026-02-20 00:52:16','2026-03-03 10:15:43',NULL),
(2,'Laptopompom','222222',NULL,100000000.00,'HUF',NULL,0,NULL,'2026-02-20 11:18:36','2026-02-24 17:19:03',2);
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_deleted` (`is_deleted`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES
(1,'IT',NULL,0,0,'2026-02-20 00:52:48','2026-02-20 00:52:48'),
(2,'Durung áram',NULL,0,0,'2026-02-20 00:52:56','2026-02-20 00:52:56'),
(3,'Számítógép',1,0,0,'2026-02-20 00:53:06','2026-02-20 00:53:06'),
(4,'Tablet',1,0,0,'2026-02-20 00:53:18','2026-02-20 00:53:18'),
(5,'FAM eszköz',2,0,0,'2026-02-20 00:53:33','2026-02-20 00:53:33'),
(6,'Ipad',4,0,0,'2026-02-20 10:31:12','2026-02-20 10:31:12');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `external_holders`
--

DROP TABLE IF EXISTS `external_holders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_holders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(190) NOT NULL,
  `contact_name` varchar(190) NOT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_external_holders_company` (`company_name`),
  KEY `idx_external_holders_contact` (`contact_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `external_holders`
--

LOCK TABLES `external_holders` WRITE;
/*!40000 ALTER TABLE `external_holders` DISABLE KEYS */;
INSERT INTO `external_holders` VALUES
(1,'maverick BT','Kaly','123456789',1,'2026-02-24 23:10:21'),
(2,'Akárki Kft','Gipsz Jakab','12345677',1,'2026-03-03 10:15:43');
/*!40000 ALTER TABLE `external_holders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'assetmgr_db'
--

--
-- Dumping routines for database 'assetmgr_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-03 13:27:16
