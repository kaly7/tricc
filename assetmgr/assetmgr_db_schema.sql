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
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
(17,1,2,1,9,'2026-02-24 22:57:22','újabb teszt','accepted','2026-02-26 21:57:22','2026-02-24 22:57:54',NULL),
(18,1,1,2,2,'2026-03-08 10:24:15',NULL,'accepted','2026-03-10 09:24:15','2026-03-08 10:30:15',NULL),
(19,2,1,2,2,'2026-03-08 11:21:06',NULL,'cancelled','2026-03-10 10:21:06','2026-03-08 11:22:11','Admin által visszavonva'),
(20,2,1,2,2,'2026-03-08 11:26:25','Átadva.','accepted','2026-03-10 10:26:25','2026-03-08 11:27:20','Köszi'),
(21,1,1,3,2,'2026-03-10 12:42:32',NULL,'accepted','2026-03-12 11:42:32','2026-03-10 12:45:59',NULL),
(22,1,3,1,11,'2026-03-10 12:50:06',NULL,'accepted','2026-03-12 11:50:06','2026-03-10 12:50:38','Odaadta nekem'),
(23,53,NULL,1,11,'2026-03-17 12:24:42',NULL,'accepted',NULL,NULL,NULL),
(24,84,NULL,50,15,'2026-03-27 11:06:50',NULL,'accepted',NULL,NULL,NULL),
(25,85,NULL,50,15,'2026-03-27 11:10:49',NULL,'accepted',NULL,NULL,NULL),
(26,86,NULL,50,15,'2026-03-27 11:15:16',NULL,'accepted',NULL,NULL,NULL),
(27,87,NULL,50,15,'2026-03-27 11:17:40',NULL,'accepted',NULL,NULL,NULL),
(28,88,NULL,50,15,'2026-03-27 11:19:35',NULL,'accepted',NULL,NULL,NULL),
(29,89,NULL,50,15,'2026-03-27 11:21:20',NULL,'accepted',NULL,NULL,NULL),
(30,90,NULL,50,15,'2026-03-27 11:25:19',NULL,'accepted',NULL,NULL,NULL),
(31,91,NULL,50,15,'2026-03-27 11:26:27',NULL,'accepted',NULL,NULL,NULL),
(32,92,NULL,50,15,'2026-03-27 11:29:13',NULL,'accepted',NULL,NULL,NULL),
(33,93,NULL,50,15,'2026-03-27 11:32:01',NULL,'accepted',NULL,NULL,NULL),
(34,94,NULL,50,15,'2026-03-27 11:32:53',NULL,'accepted',NULL,NULL,NULL),
(35,95,NULL,50,15,'2026-03-27 11:33:41',NULL,'accepted',NULL,NULL,NULL),
(36,96,NULL,50,15,'2026-03-27 11:36:31',NULL,'accepted',NULL,NULL,NULL),
(37,97,NULL,50,15,'2026-03-27 11:38:36',NULL,'accepted',NULL,NULL,NULL),
(38,98,NULL,50,15,'2026-03-27 11:42:06',NULL,'accepted',NULL,NULL,NULL);
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
(2,3),
(3,8),
(4,8),
(5,8),
(6,8),
(7,9),
(8,9),
(9,8),
(10,8),
(11,9),
(12,9),
(13,9),
(14,9),
(15,8),
(16,8),
(17,8),
(18,8),
(19,8),
(20,12),
(21,8),
(22,8),
(23,8),
(24,8),
(25,8),
(26,8),
(27,8),
(28,8),
(29,8),
(30,8),
(31,8),
(32,8),
(33,8),
(34,8),
(35,8),
(36,8),
(37,8),
(38,8),
(39,8),
(40,8),
(41,8),
(42,8),
(43,8),
(44,8),
(45,8),
(46,8),
(47,8),
(48,8),
(49,8),
(50,8),
(51,8),
(52,8),
(53,8),
(55,9),
(56,12),
(57,12),
(58,9),
(59,12),
(60,12),
(61,12),
(62,12),
(63,12),
(64,8),
(65,12),
(66,12),
(67,12),
(68,12),
(69,12),
(70,12),
(71,12),
(72,12),
(73,12),
(74,12),
(75,9),
(76,9),
(77,9),
(78,9),
(79,9),
(80,9),
(81,9),
(82,9),
(83,9),
(84,8),
(85,8),
(86,8),
(87,8),
(88,14),
(89,8),
(90,8),
(91,9),
(92,8),
(93,14),
(94,8),
(95,10),
(95,14),
(96,14),
(97,14);
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
  `signature_path` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `return_pdf_path` varchar(255) DEFAULT NULL,
  `ext_company` varchar(190) DEFAULT NULL,
  `ext_contact` varchar(190) DEFAULT NULL,
  `ext_phone` varchar(80) DEFAULT NULL,
  `ext_email` varchar(255) DEFAULT NULL,
  `assigned_by_user_id` int(11) DEFAULT NULL,
  `source_warehouse_id` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `returned_at` datetime DEFAULT NULL,
  `returned_by_user_id` int(11) DEFAULT NULL,
  `returned_to_employee_id` int(11) DEFAULT NULL,
  `returned_to_warehouse_id` int(10) unsigned DEFAULT NULL,
  `return_note` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_aea_asset_status` (`asset_id`,`status`),
  KEY `idx_aea_holder_status` (`external_holder_id`,`status`),
  KEY `idx_aea_source_warehouse` (`source_warehouse_id`),
  KEY `idx_aea_returned_to_warehouse` (`returned_to_warehouse_id`),
  CONSTRAINT `fk_aea_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aea_holder` FOREIGN KEY (`external_holder_id`) REFERENCES `external_holders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_external_assignments`
--

LOCK TABLES `asset_external_assignments` WRITE;
/*!40000 ALTER TABLE `asset_external_assignments` DISABLE KEYS */;
INSERT INTO `asset_external_assignments` VALUES
(1,1,1,'987654321','Mer\' kellett nekije',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-02-24 23:10:21','2026-02-24 23:17:10',1,NULL,NULL,NULL,'returned'),
(2,1,2,'1234555',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,9,NULL,'2026-03-03 10:15:43','2026-03-03 13:52:27',1,NULL,NULL,NULL,'returned'),
(3,2,3,'qqq','qqq',NULL,NULL,NULL,NULL,NULL,NULL,NULL,9,NULL,'2026-03-03 13:51:44','2026-03-03 13:52:23',1,NULL,NULL,NULL,'returned'),
(4,1,5,'',NULL,'/storage/uploads/external_signatures/5/sig_20260303_135307_9a11fd9d.png',NULL,NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-03-03 14:53:07','2026-03-03 15:46:50',1,NULL,NULL,NULL,'returned'),
(5,2,6,'',NULL,'/storage/uploads/external_signatures/6/sig_20260303_144007_88eb825f.png',NULL,NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-03-03 15:40:07','2026-03-03 15:46:45',1,NULL,NULL,NULL,'returned'),
(6,1,7,'',NULL,'/storage/uploads/external_signatures/7/sig_20260303_144723_e186d33e.png',NULL,NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-03-03 15:47:23','2026-03-03 20:44:19',1,NULL,NULL,NULL,'returned'),
(7,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260303_194444_7fc16eb1.png',NULL,NULL,'maverick BT','Kaly','123456789',NULL,2,NULL,'2026-03-03 20:44:44','2026-03-03 20:53:09',1,NULL,NULL,NULL,'returned'),
(8,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260303_195332_167356ca.png',NULL,NULL,'maverick BT','Kaly','123456789',NULL,2,NULL,'2026-03-03 20:53:32','2026-03-03 20:58:00',2,NULL,NULL,NULL,'returned'),
(9,1,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260303_200837_b738607b.png',NULL,NULL,'Akárki Kft','Gipsz Jakab','12345677',NULL,2,NULL,'2026-03-03 21:08:37','2026-03-03 21:13:27',2,1,NULL,'Zizizizi','returned'),
(10,1,2,'Xxx-007','Köll nekik','/storage/uploads/external_signatures/2/sig_20260303_201457_6ad470b5.png',NULL,NULL,'Akárki Kft','Gipsz Jakab 2','987654322',NULL,2,NULL,'2026-03-03 21:14:57','2026-03-03 21:15:33',2,1,NULL,'Aztán ezt is','returned'),
(11,2,2,'Xxx-007','Köll nekik','/storage/uploads/external_signatures/2/sig_20260303_201457_6ad470b5.png',NULL,NULL,'Akárki Kft','Gipsz Jakab 2','987654322',NULL,2,NULL,'2026-03-03 21:14:57','2026-03-03 21:15:23',2,1,NULL,'Visszavezsem','returned'),
(12,1,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260303_201625_954a9d7c.png',NULL,NULL,'Akárki Kft','Gipsz Jakab 2','987654322',NULL,2,NULL,'2026-03-03 21:16:25','2026-03-03 21:16:49',2,1,NULL,'Qqqqq','returned'),
(13,1,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260303_202453_46f6d46e.png',NULL,NULL,'Akárki Kft','Gipsz Jakab 2','987654322',NULL,2,NULL,'2026-03-03 21:24:53','2026-03-03 21:25:31',2,1,NULL,'Vissza a feladónak','returned'),
(16,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_101128_7ae4a0ef.png','/storage/documents/external_handover/atadas_atvetel_20260305_101128_4bfc6a82.pdf',NULL,'maverick BT','Kaly','123456789','kalamar.janos@gmail.com',0,NULL,'2026-03-05 11:11:28','2026-03-05 14:17:04',1,NULL,NULL,NULL,'returned'),
(17,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_131734_8427405f.png','/storage/documents/external_handover/atadas_atvetel_20260305_131734_90f580be.pdf',NULL,'maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 14:17:34','2026-03-05 14:23:43',1,NULL,NULL,NULL,'returned'),
(18,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_133321_333d1f5a.png','/storage/documents/external_handover/atadas_atvetel_20260305_133321_77f6b67c.pdf',NULL,'maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 14:33:21','2026-03-05 20:39:37',2,1,NULL,NULL,'returned'),
(19,2,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_133718_09f95ea9.png','/storage/documents/external_handover/atadas_atvetel_20260305_133718_232b1b14.pdf',NULL,'maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 14:37:18','2026-03-05 20:39:32',2,1,NULL,NULL,'returned'),
(20,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_202740_dac13ac0.png','/storage/documents/external_handover/atadas_atvetel_20260305_202741_af483cba.pdf',NULL,'maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 21:27:40','2026-03-05 21:29:03',2,1,NULL,'Vissza vettem','returned'),
(21,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_212452_d1964295.png','/storage/documents/external_handover/atadas_atvetel_20260305_212452_f1cb0aad.pdf','/storage/documents/external_return/visszavetel_atvetel_20260305_215428_72065bd1.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 22:24:52','2026-03-05 22:54:28',2,1,NULL,'Vissza','returned'),
(22,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_225138_2585b06b.png','/storage/documents/external_handover/atadas_atvetel_20260305_225139_54944351.pdf','/storage/documents/external_return/visszavetel_atvetel_20260305_225345_959e021f.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-05 23:51:38','2026-03-05 23:53:44',2,1,NULL,NULL,'returned'),
(23,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_230122_da6833c1.png','/storage/documents/external_handover/atadas_atvetel_20260305_230124_ccf008e1.pdf','/storage/documents/external_return/visszavetel_atvetel_20260305_230251_047ca719.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 00:01:22','2026-03-06 00:02:50',2,1,NULL,'Well','returned'),
(24,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_232105_2c469fde.png','/storage/documents/external_handover/atadas_atvetel_20260305_232105_5aa4b330.pdf','/storage/documents/external_return/visszavetel_atvetel_20260305_232710_39e590a0.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 00:21:05','2026-03-06 00:27:10',2,1,NULL,NULL,'returned'),
(25,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260305_234153_0fbeb2cd.png','/storage/documents/external_handover/atadas_atvetel_20260305_234153_6301ea78.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_000345_9a19648e.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 00:41:53','2026-03-06 01:03:45',2,1,NULL,NULL,'returned'),
(26,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_000432_8bbf7035.png','/storage/documents/external_handover/atadas_atvetel_20260306_000432_a656ca0b.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_202418_d4ce224f.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 01:04:32','2026-03-06 21:24:18',2,1,NULL,NULL,'returned'),
(27,2,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_202330_e165cadc.png','/storage/documents/external_handover/atadas_atvetel_20260306_202330_eec3fdcd.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_202416_aac78a69.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 21:23:30','2026-03-06 21:24:16',2,1,NULL,NULL,'returned'),
(28,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_202749_d758ce86.png','/storage/documents/external_handover/atadas_atvetel_20260306_202749_b4e46854.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_202909_4bda51d3.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 21:27:49','2026-03-06 21:29:09',2,1,NULL,'Visszavettem. Minden OK','returned'),
(29,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_203217_3d4c5573.png','/storage/documents/external_handover/atadas_atvetel_20260306_203217_ada20775.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_213727_8cf26d1c.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 21:32:17','2026-03-06 22:37:27',2,1,NULL,'Vissza','returned'),
(30,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_214018_bbd47776.png','/storage/documents/external_handover/atadas_atvetel_20260306_214018_9e34eeb3.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_215248_45b8bf00.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-06 22:40:18','2026-03-06 22:52:47',2,1,NULL,'Tttt','returned'),
(31,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_221304_a9b7898b.png','/storage/documents/external_handover/atadas_atvetel_20260306_221304_1f7f444d.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_222759_ff896128.pdf','maverick BT','Kaly','123456789','kanis@kalamar.hu',2,NULL,'2026-03-06 23:13:04','2026-03-06 23:27:59',2,1,NULL,NULL,'returned'),
(32,2,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_221615_883d4aeb.png','/storage/documents/external_handover/atadas_atvetel_20260306_221616_a93bcfa1.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_222754_c7a2ee69.pdf','maverick BT','Kaly','123456789','kalamar.janos@gmail.com',2,NULL,'2026-03-06 23:16:16','2026-03-06 23:27:54',2,1,NULL,NULL,'returned'),
(33,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_223005_072bb9f5.png','/storage/documents/external_handover/atadas_atvetel_20260306_223005_0494cd7e.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_223137_2f27f65a.pdf','maverick BT','Kaly','123456789','kalamar.janos@gmail.com',2,NULL,'2026-03-06 23:30:05','2026-03-06 23:31:37',2,1,NULL,'Vissza vettem.','returned'),
(34,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_224952_85396c92.png','/storage/documents/external_handover/atadas_atvetel_20260306_224952_7e4ed0c6.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_225037_5da7f081.pdf','maverick BT','Kaly','123456789','kalamar.janos@gmail.com',2,NULL,'2026-03-06 23:49:52','2026-03-06 23:50:37',2,1,NULL,'Ide nekem','returned'),
(35,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_230107_f14a2110.png','/storage/documents/external_handover/atadas_atvetel_20260306_230107_4b2ac1e9.pdf',NULL,'maverick BT','Kaly','123456789','kalamar.janos@gmail.com',2,NULL,'2026-03-07 00:01:07','2026-03-07 00:01:45',2,1,NULL,NULL,'returned'),
(36,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_231715_a6b4b8fb.png','/storage/documents/external_handover/atadas_atvetel_20260306_231715_5a472401.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_231726_25b6978a.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-07 00:17:15','2026-03-07 00:17:26',2,1,NULL,NULL,'returned'),
(37,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_232816_7826b8d1.png','/storage/documents/external_handover/atadas_atvetel_20260306_232816_40b969cb.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_232833_11747b68.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-07 00:28:16','2026-03-07 00:28:33',2,1,NULL,'Back','returned'),
(38,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_233540_c61c21df.png','/storage/documents/external_handover/atadas_atvetel_20260306_233540_44850459.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_233614_2cbe0cc1.pdf','maverick BT','Kaly','123456789','janos@kalamt.hu',2,NULL,'2026-03-07 00:35:40','2026-03-07 00:36:14',2,1,NULL,NULL,'returned'),
(39,1,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260306_233717_1d32de9f.png','/storage/documents/external_handover/atadas_atvetel_20260306_233717_6130075e.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_233731_6f89e6fd.pdf','Akárki Kft','Gipsz Jakab 2','987654322','janos@kalamar.hu',2,NULL,'2026-03-07 00:37:17','2026-03-07 00:37:31',2,1,NULL,'Ide','returned'),
(40,1,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260306_234019_0b3530fe.png','/storage/documents/external_handover/atadas_atvetel_20260306_234019_57dc4017.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_234150_828311c2.pdf','maverick BT','Kaly','123456789','janos@kalamar.hu',2,NULL,'2026-03-07 00:40:19','2026-03-07 00:41:50',2,1,NULL,NULL,'returned'),
(41,1,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260306_235044_c7bab073.png','/storage/documents/external_handover/atadas_atvetel_20260306_235044_a515a62f.pdf','/storage/documents/external_return/visszavetel_atvetel_20260306_235111_c56a2248.pdf','Akárki Kft','Gipsz Jakab 2','987654322','janos@kalamar.hu',2,NULL,'2026-03-07 00:50:44','2026-03-07 00:51:11',2,1,NULL,'Vissza is jött egyből','returned'),
(42,1,8,'Sz-10000','Javításra átadva','/storage/uploads/external_signatures/8/sig_20260307_084656_7463ceea.png','/storage/documents/external_handover/atadas_atvetel_20260307_084656_6d32327e.pdf','/storage/documents/external_return/visszavetel_atvetel_20260307_084940_de1121da.pdf','Legújabb kft','Bármi Áron','112233445566','kalamar.janos@gmail.com',2,NULL,'2026-03-07 09:46:56','2026-03-07 09:49:40',2,1,NULL,'Sikerült a javítás. Úgy néz ki legalábbis :)','returned'),
(43,1,8,'',NULL,'/storage/uploads/external_signatures/8/sig_20260308_092434_9fbee1af.png','/storage/documents/external_handover/atadas_atvetel_20260308_092434_7f0ee3f1.pdf','/storage/documents/external_return/visszavetel_atvetel_20260308_092947_422b5683.pdf','Legújabb kft','Bármi Áron','112233445566',NULL,2,NULL,'2026-03-08 10:24:34','2026-03-08 10:29:47',2,1,NULL,NULL,'returned'),
(44,2,2,'Xxx111',NULL,'/storage/uploads/external_signatures/2/sig_20260308_102244_560c25f1.png','/storage/documents/external_handover/atadas_atvetel_20260308_102244_25340fcb.pdf',NULL,'Akárki Kft','Gipsz Jakab 2','987654322','janos@kalamar.hu',2,NULL,'2026-03-08 11:22:44','2026-03-08 11:23:44',1,NULL,NULL,NULL,'returned'),
(45,1,8,'',NULL,'/storage/uploads/external_signatures/8/sig_20260308_104603_9eb87b67.png','/storage/documents/external_handover/atadas_atvetel_20260308_104604_59bc399f.pdf','/storage/documents/external_return/visszavetel_atvetel_20260308_104658_3433a4e0.pdf','Legújabb kft','Bármi Áron','112233445566','janos@kalamar.hu',9,NULL,'2026-03-08 11:46:04','2026-03-08 11:46:58',1,1,NULL,NULL,'returned'),
(46,2,8,'',NULL,'/storage/uploads/external_signatures/8/sig_20260308_104831_3529dbf0.png','/storage/documents/external_handover/atadas_atvetel_20260308_104831_33251233.pdf','/storage/documents/external_return/visszavetel_atvetel_20260308_104849_7316097b.pdf','Legújabb kft','Bármi Áron','112233445566','janos@kalamar.hu',9,NULL,'2026-03-08 11:48:31','2026-03-08 11:48:49',9,2,NULL,NULL,'returned'),
(47,2,2,'',NULL,'/storage/uploads/external_signatures/2/sig_20260308_105010_09bb88a9.png','/storage/documents/external_handover/atadas_atvetel_20260308_105010_11dff6a9.pdf','/storage/documents/external_return/visszavetel_atvetel_20260308_105039_e1ff944c.pdf','Akárki Kft','Gipsz Jakab 2','987654322','janos@kalamar.hu',9,NULL,'2026-03-08 11:50:10','2026-03-08 11:50:39',1,1,NULL,NULL,'returned'),
(48,53,8,'',NULL,'/storage/uploads/external_signatures/8/sig_20260317_140137_9da4f929.png','/storage/documents/external_handover/atadas_atvetel_20260317_140138_07d89c0c.pdf','/storage/documents/external_return/visszavetel_atvetel_20260317_140255_74975645.pdf','Legújabb kft','Bármi Áron','112233445566','kaly@compunet.hu',2,NULL,'2026-03-17 15:01:38','2026-03-17 15:02:55',2,1,NULL,NULL,'returned'),
(49,1,8,'',NULL,'/storage/uploads/external_signatures/8/sig_20260319_131659_8b4cf462.png','/storage/documents/external_handover/atadas_atvetel_20260319_131659_26160dea.pdf','/storage/documents/external_return/visszavetel_atvetel_20260319_132133_1a23e594.pdf','Legújabb kft','Bármi Áron','112233445566','kaly@compunet.hu',1,1,'2026-03-19 14:16:59','2026-03-19 14:21:33',1,6,NULL,NULL,'returned'),
(50,2,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260319_132554_06e9fe61.png','/storage/documents/external_handover/atadas_atvetel_20260319_132555_ecbaba48.pdf','/storage/documents/external_return/visszavetel_atvetel_20260319_135143_b363aba4.pdf','maverick BT','Kaly','123456789','kaly@compunet.hu',1,1,'2026-03-19 14:25:55','2026-03-19 14:51:43',1,NULL,1,'vissazagyütt','returned'),
(51,53,1,'',NULL,'/storage/uploads/external_signatures/1/sig_20260319_135448_5911223f.png','/storage/documents/external_handover/atadas_atvetel_20260319_135448_89a08250.pdf','/storage/documents/external_return/visszavetel_atvetel_20260324_095222_7159e220.pdf','maverick BT','Kaly','123456789','kaly@compunet.hu',1,1,'2026-03-19 14:54:48','2026-03-24 10:52:22',11,NULL,1,NULL,'returned');
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
  `current_warehouse_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assets_name` (`name`),
  KEY `idx_assets_sku` (`sku`),
  KEY `idx_assets_qr` (`qr_value`),
  KEY `idx_assets_deleted` (`is_deleted`),
  KEY `idx_assets_current_employee` (`current_employee_id`),
  KEY `idx_assets_current_warehouse` (`current_warehouse_id`),
  CONSTRAINT `fk_assets_current_warehouse` FOREIGN KEY (`current_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
INSERT INTO `assets` VALUES
(1,'akármi',NULL,NULL,NULL,'HUF','qqqqq',0,NULL,'2026-02-20 00:52:16','2026-03-19 14:21:33',6,NULL),
(2,'Laptopompom','222222',NULL,100000000.00,'HUF',NULL,0,NULL,'2026-02-20 11:18:36','2026-03-27 00:36:27',1,NULL),
(3,'Krone tűző/1.',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:26:58','2026-03-12 13:47:43',NULL,NULL),
(4,'Krone tűző/2.',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:27:29','2026-03-12 13:47:50',NULL,NULL),
(5,'AMP fogó/2.','251101-4',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:27:46','2026-03-12 13:48:04',NULL,NULL),
(6,'AMP fogó/1.','251101-4',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:28:09','2026-03-12 13:47:56',NULL,NULL),
(7,'3M Érkötőgép 25 érpáras','A23930',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:29:36','2026-03-12 13:29:36',NULL,NULL),
(8,'3M MS2 TM Érkötőgép 10 érpáras','E95252',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:29:55','2026-03-12 13:29:55',NULL,NULL),
(9,'Yato Yt-18907 Hidraulikus lemez prés','YT-18907',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:30:47','2026-03-12 13:30:47',NULL,NULL),
(10,'Crova készlet 90 db-os',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:31:01','2026-03-12 13:31:01',NULL,NULL),
(11,'M-IPC-900C CCTV Tester','2017112500013161',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:31:23','2026-03-12 13:31:23',NULL,NULL),
(12,'Dräger Pac 6500 Gázérzékelő/1.','ARUJ-0273',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:31:42','2026-03-12 13:47:16',NULL,NULL),
(13,'Dräger Pac 6500 Gázérzékelő/2.','ARUJ-0274',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:31:57','2026-03-12 13:47:24',NULL,NULL),
(14,'DSTS Tempo kézibeszélő/1.','No 0003',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:32:15','2026-03-12 13:46:44',NULL,NULL),
(15,'Bormann Gázégőfej','SU1203250',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:32:30','2026-03-12 13:32:30',NULL,NULL),
(16,'PB Palack 11kg',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:33:15','2026-03-12 13:33:15',NULL,NULL),
(17,'Vízmérték/1.',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:33:27','2026-03-12 13:46:13',NULL,NULL),
(18,'Vízmérték/2.',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:33:33','2026-03-12 13:46:21',NULL,NULL),
(19,'Bosh rezgőfűrész','207003441',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:33:50','2026-03-12 13:33:50',NULL,NULL),
(20,'Lézeres szálazonosító',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:34:07','2026-03-12 13:34:07',NULL,NULL),
(21,'GORILLA hegesztő','SN: PP100221',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:34:41','2026-03-12 13:34:41',NULL,NULL),
(22,'Lemezvágó olló','74360',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:35:09','2026-03-12 13:35:09',NULL,NULL),
(23,'Hilti fúrógép','2015811',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:35:23','2026-03-12 13:35:23',NULL,NULL),
(24,'Vizes tartály 10 L','35868',NULL,NULL,'HUF',NULL,1,'2026-03-12 13:37:50','2026-03-12 13:35:42','2026-03-12 13:37:50',NULL,NULL),
(25,'Fúróállvány',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:36:09','2026-03-12 13:36:09',NULL,NULL),
(26,'Korona fúrószár készlet',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:37:20','2026-03-12 13:37:20',NULL,NULL),
(27,'Dewalt 230V flex','N 173923',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:38:10','2026-03-12 13:38:10',NULL,NULL),
(28,'Dewalt 230V flex','N 520387',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:38:24','2026-03-12 13:38:24',NULL,NULL),
(29,'Dewalt 54V ütvefúró','N 491324',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:38:34','2026-03-12 13:38:34',NULL,NULL),
(30,'Dewalt akkus ütvefúró','NA130517',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:38:47','2026-03-12 13:38:47',NULL,NULL),
(31,'Dewalt akkus ütvefúró/2.','N4049719',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:39:00','2026-03-12 13:45:58',NULL,NULL),
(32,'Dewalt akkus csavarozó gép','NA413957',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:39:12','2026-03-12 13:39:12',NULL,NULL),
(33,'Dewalt körfűrész gép','NA 131911',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:39:21','2026-03-12 13:39:21',NULL,NULL),
(34,'Dewalt akkus multiszerszám','NA391494',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:39:33','2026-03-12 13:39:33',NULL,NULL),
(35,'Dewalt akkus ütvefúró/1.','N4049719',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:39:51','2026-03-12 13:45:45',NULL,NULL),
(36,'Dewalt akkus ragasztó kinyomó','NA 144526',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:40:03','2026-03-12 13:40:03',NULL,NULL),
(37,'Dewalt akkus csavarozó gép/1.','N135568',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:40:15','2026-03-12 13:45:15',NULL,NULL),
(38,'Dewalt akkus csavarozó gép/2.','N135568',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:40:23','2026-03-12 13:45:32',NULL,NULL),
(39,'Dewalt akkus sarokcsiszoló','N173923',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:40:33','2026-03-12 13:40:33',NULL,NULL),
(40,'Dewalt akkus csavarozó gép','N421656',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:40:51','2026-03-12 13:40:51',NULL,NULL),
(41,'Dewalt akkus csavarozó gép','N119140',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:41:01','2026-03-12 13:41:01',NULL,NULL),
(42,'Alko víz szivattyú','N 112823',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:41:15','2026-03-12 13:41:15',NULL,NULL),
(43,'Honda Agregátor','GCBCT 1789560',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:41:25','2026-03-12 13:41:25',NULL,NULL),
(44,'Hitachi DH40MR Vésőgép','2008081731',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:41:38','2026-03-12 13:41:38',NULL,NULL),
(45,'Weld Industrial heggesztő trafó+pajzs','143D44274',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:41:51','2026-03-12 13:41:51',NULL,NULL),
(46,'Imbusz készlet/1.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:42:24','2026-03-12 13:43:43',NULL,NULL),
(47,'Imbusz készlet/2.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:42:43','2026-03-12 13:43:59',NULL,NULL),
(48,'Imbusz készlet/3.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:42:50','2026-03-12 13:44:06',NULL,NULL),
(49,'Imbusz készlet/4.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:42:56','2026-03-12 13:44:12',NULL,NULL),
(50,'Imbusz készlet/5.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:43:03','2026-03-12 13:44:18',NULL,NULL),
(51,'Imbusz készlet/6.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:43:10','2026-03-12 13:44:26',NULL,NULL),
(52,'Imbusz készlet/7.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:43:23','2026-03-12 13:44:32',NULL,NULL),
(53,'Imbusz készlet/8.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:43:36','2026-03-27 00:36:27',1,NULL),
(54,'Imbusz készlet/9.','1404757',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:44:57','2026-03-12 13:44:57',NULL,NULL),
(55,'DSTS Tempo kézibeszélő/2.','No 0003',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:47:04','2026-03-12 13:47:04',NULL,NULL),
(56,'EXFO MAX.945 mérőműszer','20453627,2039005, 20430404,2039008',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:48:49','2026-03-12 13:48:49',NULL,NULL),
(57,'Fluke Networks DSX-8000','SA01255014,SA01255013',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:49:13','2026-03-12 13:49:13',NULL,NULL),
(58,'OMNI Scenner MM OLTS','XN 000105.1, XN 000105.2',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:49:45','2026-03-12 13:49:45',NULL,NULL),
(59,'Fluke Networks Pro MM OLTS','SA01255005',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:49:57','2026-03-12 13:49:57',NULL,NULL),
(60,'Fluke Networks Pro MM OLTS','SA01255006',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:50:07','2026-03-12 13:50:07',NULL,NULL),
(61,'SUMITOMO otikai hegesztő gép','234921751053',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:50:19','2026-03-12 13:50:19',NULL,NULL),
(62,'SUMITOMO otikai hegesztő gép','234921751054',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:50:31','2026-03-12 13:50:31',NULL,NULL),
(63,'Fitel 179 otikai hegesztő gép','O4799',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:50:43','2026-03-12 13:50:43',NULL,NULL),
(64,'Makita JN3201J folyamatos lyukasztó','16848E',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:51:09','2026-03-12 13:51:09',NULL,NULL),
(65,'Fot 930 mérőműszer','849170',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:51:34','2026-03-12 13:51:34',NULL,NULL),
(66,'Fot 930 mérőműszer','4799',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:51:43','2026-03-12 13:51:43',NULL,NULL),
(67,'EXFO FTB-200 optikai mérőműszer','18000715',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:51:54','2026-03-12 13:51:54',NULL,NULL),
(68,'Furukawa élőszál azonosító Fitel','758400',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:52:06','2026-03-12 13:52:06',NULL,NULL),
(69,'EXFO FIP-430B optikai mikroszkóp','No.002',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:52:20','2026-03-12 13:52:20',NULL,NULL),
(70,'EXFO FTB-1v2 mérőműszer','1389306',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:52:31','2026-03-12 13:52:31',NULL,NULL),
(71,'EXFO FTB-730C mérőműszer','1409264',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:52:42','2026-03-12 13:52:42',NULL,NULL),
(72,'EXFO FTB-945 SM4 mérőműszer','1411115',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:52:52','2026-03-12 13:52:52',NULL,NULL),
(73,'Fluke Networks DTX-1800','9891101',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:53:03','2026-03-12 13:53:03',NULL,NULL),
(74,'Fluke Networks DTX-1800','9891102',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:53:15','2026-03-12 13:53:15',NULL,NULL),
(75,'SEBA KMT Easyloc Rx-Plus KMT','SN:2100003030',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:53:26','2026-03-12 13:53:26',NULL,NULL),
(76,'SEBA KMT Easyloc Tx-Plus KMT','SN:2100003332',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:53:57','2026-03-12 13:53:57',NULL,NULL),
(77,'Metrel szigetelésmérő zsinórokkal','13010402',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:54:28','2026-03-12 13:54:28',NULL,NULL),
(78,'Seba Dynatronic műszer zsinórokkal','D96148',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:54:47','2026-03-12 13:54:47',NULL,NULL),
(79,'Tonegenerátor Érpár kereső','PTS 93-9',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:55:00','2026-03-12 13:55:00',NULL,NULL),
(80,'Tonegenerátor Érpár kereső','PTS 93-10',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:55:11','2026-03-12 13:55:11',NULL,NULL),
(81,'Érpárkereső jeladó cable tracher','EM415-T',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:55:25','2026-03-12 13:55:25',NULL,NULL),
(82,'Érpárkereső vevő cable tracher','EM415-R',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:55:34','2026-03-12 13:55:34',NULL,NULL),
(83,'EFL-10 Mérőműszer zsinórokkal','9292626',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-12 13:56:21','2026-03-12 13:56:21',NULL,NULL),
(84,'AMP fogó MR1/1',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:05:15','2026-03-27 11:06:50',50,NULL),
(85,'Siemens Kábelvágó',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:10:05','2026-03-27 11:10:49',50,NULL),
(86,'Érpárkereső adó-vevő','EM415',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:14:59','2026-03-27 11:15:16',50,NULL),
(87,'Kézibeszélő','DSTS2',NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:17:24','2026-03-27 11:17:40',50,NULL),
(88,'Kalapács 500gr.',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:19:24','2026-03-27 11:19:35',50,NULL),
(89,'Krone Tűző',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:21:08','2026-03-27 11:21:20',50,NULL),
(90,'Quante tűző piros',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:25:06','2026-03-27 11:25:19',50,NULL),
(91,'Quante tűző kék',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:26:15','2026-03-27 11:26:27',50,NULL),
(92,'Siemens Érbekekötő',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:28:56','2026-03-27 11:29:13',50,NULL),
(93,'Csavarhúzó Készlet 8db-os',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:31:38','2026-03-27 11:32:01',50,NULL),
(94,'Plug fogó 6-os',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:32:44','2026-03-27 11:32:53',50,NULL),
(95,'Kombinált fogó',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:33:32','2026-03-27 11:33:41',50,NULL),
(96,'Oldalcsípő fogó 100',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:35:12','2026-03-27 11:37:35',50,NULL),
(97,'Oldalcsípő fogó 140',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:38:12','2026-03-27 11:38:36',50,NULL),
(98,'Szerszámláda',NULL,NULL,NULL,'HUF',NULL,0,NULL,'2026-03-27 11:41:55','2026-03-27 11:42:06',50,NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES
(1,'IT',NULL,0,0,'2026-02-20 00:52:48','2026-02-20 00:52:48'),
(2,'Erős áram',NULL,0,0,'2026-02-20 00:52:56','2026-03-13 18:10:09'),
(3,'Számítógép',1,0,0,'2026-02-20 00:53:06','2026-02-20 00:53:06'),
(4,'Tablet',1,0,0,'2026-02-20 00:53:18','2026-02-20 00:53:18'),
(5,'FAM eszköz',2,0,0,'2026-02-20 00:53:33','2026-02-20 00:53:33'),
(6,'Ipad',4,0,0,'2026-02-20 10:31:12','2026-02-20 10:31:12'),
(7,'Rezes eszközök',NULL,0,0,'2026-03-12 11:51:11','2026-03-12 11:51:11'),
(8,'Rezes szerszámok',7,0,0,'2026-03-12 11:51:22','2026-03-12 11:51:22'),
(9,'Rezes műszerek',7,0,0,'2026-03-12 11:51:32','2026-03-12 11:51:32'),
(10,'Optikai eszközök',NULL,0,0,'2026-03-12 11:51:41','2026-03-12 11:51:41'),
(11,'Optikai szerszámok',10,0,0,'2026-03-12 11:51:49','2026-03-12 11:51:49'),
(12,'Optikai műszerek',10,0,0,'2026-03-12 11:52:14','2026-03-12 11:52:14'),
(13,'Szerszámgépek',NULL,0,0,'2026-03-12 11:53:08','2026-03-12 11:53:08'),
(14,'Kézi szerszám',NULL,0,0,'2026-03-27 11:35:43','2026-03-27 11:35:43');
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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `external_holders`
--

LOCK TABLES `external_holders` WRITE;
/*!40000 ALTER TABLE `external_holders` DISABLE KEYS */;
INSERT INTO `external_holders` VALUES
(1,'maverick BT','Kaly','123456789',1,'2026-02-24 23:10:21'),
(2,'Akárki Kft','Gipsz Jakab 2','987654322',1,'2026-03-03 10:15:43'),
(3,'qqq','qqq','qqq',1,'2026-03-03 13:51:44'),
(5,'Rrr','Zzzz','123457',1,'2026-03-03 14:53:07'),
(6,'Zttzt','Ghbhh','Tzhg',1,'2026-03-03 15:40:07'),
(7,'Addfg','Rzfggggh',NULL,1,'2026-03-03 15:47:23'),
(8,'Legújabb kft','Bármi Áron','112233445566',1,'2026-03-07 09:46:56');
/*!40000 ALTER TABLE `external_holders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_admins`
--

DROP TABLE IF EXISTS `warehouse_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_admins` (
  `warehouse_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`warehouse_id`,`user_id`),
  KEY `idx_warehouse_admins_user` (`user_id`),
  CONSTRAINT `fk_warehouse_admins_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_admins`
--

LOCK TABLES `warehouse_admins` WRITE;
/*!40000 ALTER TABLE `warehouse_admins` DISABLE KEYS */;
INSERT INTO `warehouse_admins` VALUES
(1,1,'2026-03-18 00:34:19');
/*!40000 ALTER TABLE `warehouse_admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_intake_documents`
--

DROP TABLE IF EXISTS `warehouse_intake_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_intake_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `doc_date` datetime NOT NULL DEFAULT current_timestamp(),
  `source_label` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wint_asset` (`asset_id`),
  KEY `idx_wint_wh` (`warehouse_id`),
  KEY `idx_wint_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_intake_documents`
--

LOCK TABLES `warehouse_intake_documents` WRITE;
/*!40000 ALTER TABLE `warehouse_intake_documents` DISABLE KEYS */;
INSERT INTO `warehouse_intake_documents` VALUES
(1,1,1,1,'2026-03-18 22:57:41','Dolgozó: Kalamár János (HR)',NULL,'kaly@compunet.hu','/storage/documents/warehouse_intake/raktarba_vetel_20260318_225740_3e9d6e02.pdf','2026-03-18 23:57:41'),
(2,2,1,1,'2026-03-18 22:57:41','Dolgozó: Kalamár János (HR)',NULL,'kaly@compunet.hu','/storage/documents/warehouse_intake/raktarba_vetel_20260318_225740_3e9d6e02.pdf','2026-03-18 23:57:41'),
(3,53,1,1,'2026-03-18 22:57:41','Dolgozó: Kalamár János (HR)',NULL,'kaly@compunet.hu','/storage/documents/warehouse_intake/raktarba_vetel_20260318_225740_3e9d6e02.pdf','2026-03-18 23:57:41');
/*!40000 ALTER TABLE `warehouse_intake_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_issue_documents`
--

DROP TABLE IF EXISTS `warehouse_issue_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_issue_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `to_employee_id` int(10) unsigned NOT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `doc_date` datetime NOT NULL DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wid_asset` (`asset_id`),
  KEY `idx_wid_wh` (`warehouse_id`),
  KEY `idx_wid_emp` (`to_employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_issue_documents`
--

LOCK TABLES `warehouse_issue_documents` WRITE;
/*!40000 ALTER TABLE `warehouse_issue_documents` DISABLE KEYS */;
INSERT INTO `warehouse_issue_documents` VALUES
(1,1,1,1,1,'2026-03-18 15:40:29',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_144029_f52db71c.pdf','2026-03-18 15:40:29'),
(2,1,1,1,1,'2026-03-18 23:45:38',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224538_a9dcef9c.pdf','2026-03-18 23:45:38'),
(3,2,1,1,1,'2026-03-18 23:45:38',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224538_a9dcef9c.pdf','2026-03-18 23:45:38'),
(4,53,1,1,1,'2026-03-18 23:45:38',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224538_a9dcef9c.pdf','2026-03-18 23:45:38'),
(5,1,1,1,1,'2026-03-18 23:49:42',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224941_b67bdd08.pdf','2026-03-18 23:49:42'),
(6,2,1,1,1,'2026-03-18 23:49:42',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224941_b67bdd08.pdf','2026-03-18 23:49:42'),
(7,53,1,1,1,'2026-03-18 23:49:42',NULL,'janos@kalamar.hu','/storage/documents/warehouse_issue/raktari_kiadas_20260318_224941_b67bdd08.pdf','2026-03-18 23:49:42'),
(8,2,1,1,1,'2026-03-27 00:36:28',NULL,NULL,'/storage/documents/warehouse_issue/raktari_kiadas_20260326_233627_fe3bd473.pdf','2026-03-27 00:36:28'),
(9,53,1,1,1,'2026-03-27 00:36:28',NULL,NULL,'/storage/documents/warehouse_issue/raktari_kiadas_20260326_233627_fe3bd473.pdf','2026-03-27 00:36:28');
/*!40000 ALTER TABLE `warehouse_issue_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_warehouses_active` (`is_active`),
  KEY `idx_warehouses_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES
(1,'Gyengeáram 1','Szekszárd iroda',NULL,1,'2026-03-18 00:34:04','2026-03-18 00:34:19');
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
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

-- Dump completed on 2026-03-27 11:49:37
