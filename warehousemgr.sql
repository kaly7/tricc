/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: warehousemgr
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
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(10) unsigned DEFAULT NULL,
  `action_key` varchar(80) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `request_uri` varchar(255) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_action` (`action_key`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`auth_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES
(1,1,'warehouse.create','warehouse',1,'{\"name\":\"RAKTÁR\",\"code\":\"1\",\"parent_id\":null}',NULL,NULL,NULL,NULL,'2026-03-11 18:42:54'),
(2,1,'warehouse.access.upsert','warehouse',1,'{\"auth_user_id\":1,\"role_key\":\"admin\"}',NULL,NULL,NULL,NULL,'2026-03-11 18:43:03'),
(3,1,'warehouse.access.upsert','warehouse',1,'{\"auth_user_id\":2,\"role_key\":\"user\"}',NULL,NULL,NULL,NULL,'2026-03-11 18:43:14'),
(4,1,'material.create','material',1,'{\"sku\":\"UTP001\",\"name\":\"UTP CAT6e Kábel Belden\"}',NULL,NULL,NULL,NULL,'2026-03-11 18:58:15'),
(5,1,'material.stock_locations_view','material',1,'{\"material_ids\":[1],\"material_count\":1}','192.168.16.199','/materials.php?sort=name&dir=asc&page=1&selected_material_ids%5B0%5D=1&show_locations=1','GET','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:22:02'),
(6,1,'material.stock_locations_view','material',1,'{\"material_ids\":[1],\"material_count\":1}','192.168.16.199','/materials.php?q=&sort=sku&dir=desc&page=1&show_locations=1&selected_material_ids%5B%5D=1&action=toggle_material_active&material_id=1','GET','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:28:44'),
(7,1,'warehouse.create','warehouse',2,'{\"name\":\"Raki2\",\"code\":\"r2\",\"parent_id\":null}','192.168.16.199','/warehouses.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:51:18'),
(8,1,'warehouse.access.upsert','warehouse',2,'{\"auth_user_id\":1,\"role_key\":\"admin\",\"warehouse_name\":\"Raki2\",\"warehouse_code\":\"r2\"}','192.168.16.199','/warehouse_access.php?id=2','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:51:23'),
(9,1,'transfer.create','stock_transfer',1,'{\"reference\":\"TR-000001\",\"source_warehouse_id\":1,\"source_warehouse_name\":\"RAKTÁR\",\"target_warehouse_id\":2,\"target_warehouse_name\":\"Raki2\",\"material_id\":1,\"material_sku\":\"UTP001\",\"material_name\":\"UTP CAT6e Kábel Belden\",\"quantity\":\"1.000\",\"reference_no\":null,\"note\":null}','192.168.16.199','/transfers.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:51:58'),
(10,1,'stock.receipt','stock_movement',1,'{\"warehouse_id\":1,\"warehouse_name\":\"RAKTÁR\",\"material_id\":1,\"material_sku\":\"UTP001\",\"material_name\":\"UTP CAT6e Kábel Belden\",\"quantity_change\":\"100.000\",\"quantity_before\":\"0.000\",\"quantity_after\":\"100.000\",\"reference_no\":null,\"note\":null}','192.168.16.199','/stock.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:52:57'),
(11,1,'transfer.accept','stock_transfer',1,'{\"reference\":\"TR-000001\",\"source_warehouse_id\":1,\"target_warehouse_id\":2,\"material_id\":1,\"quantity\":\"1.000\",\"source_movement_id\":2,\"target_movement_id\":3,\"decision_note\":null}','192.168.16.199','/transfers.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 20:53:52'),
(12,1,'material.create','material',2,'{\"sku\":\"333\",\"name\":\"UTP dugó\"}','192.168.16.199','/materials.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 21:30:13'),
(13,1,'stock.receipt','stock_movement',4,'{\"warehouse_id\":1,\"warehouse_name\":\"RAKTÁR\",\"material_id\":2,\"material_sku\":\"333\",\"material_name\":\"UTP dugó\",\"quantity_change\":\"150.000\",\"quantity_before\":\"0.000\",\"quantity_after\":\"150.000\",\"reference_no\":null,\"note\":null}','192.168.16.199','/stock.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 21:30:26'),
(14,1,'stock.adjustment_add','stock_movement',5,'{\"warehouse_id\":1,\"warehouse_name\":\"RAKTÁR\",\"material_id\":2,\"material_sku\":\"333\",\"material_name\":\"UTP dugó\",\"quantity_change\":\"100.000\",\"quantity_before\":\"150.000\",\"quantity_after\":\"250.000\",\"reference_no\":null,\"note\":null}','192.168.16.199','/stock.php?warehouse_id=0&q=','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 21:34:15'),
(15,1,'stock.receipt','stock_movement',6,'{\"warehouse_id\":1,\"warehouse_name\":\"RAKTÁR\",\"material_id\":2,\"material_sku\":\"333\",\"material_name\":\"UTP dugó\",\"quantity_change\":\"1.000\",\"quantity_before\":\"250.000\",\"quantity_after\":\"251.000\",\"reference_no\":null,\"note\":null}','192.168.16.199','/stock.php','POST','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15','2026-03-11 21:34:29');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_import_batches`
--

DROP TABLE IF EXISTS `material_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_import_batches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `imported_by` int(10) unsigned DEFAULT NULL,
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `inserted_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `error_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_material_import_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_import_batches`
--

LOCK TABLES `material_import_batches` WRITE;
/*!40000 ALTER TABLE `material_import_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_import_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_import_errors`
--

DROP TABLE IF EXISTS `material_import_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_import_errors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` int(10) unsigned NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `row_json` longtext DEFAULT NULL,
  `error_message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mie_batch` (`batch_id`),
  CONSTRAINT `fk_mie_batch` FOREIGN KEY (`batch_id`) REFERENCES `material_import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_import_errors`
--

LOCK TABLES `material_import_errors` WRITE;
/*!40000 ALTER TABLE `material_import_errors` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_import_errors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_items`
--

DROP TABLE IF EXISTS `material_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(120) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `category_name` varchar(120) DEFAULT NULL,
  `minimum_stock` decimal(14,3) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_material_sku` (`sku`),
  KEY `idx_material_name` (`name`),
  KEY `idx_material_category` (`category_name`),
  KEY `idx_material_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_items`
--

LOCK TABLES `material_items` WRITE;
/*!40000 ALTER TABLE `material_items` DISABLE KEYS */;
INSERT INTO `material_items` VALUES
(1,'UTP001','UTP CAT6e Kábel Belden','dob',NULL,NULL,NULL,1,1,1,'2026-03-11 18:58:15','2026-03-11 18:58:15'),
(2,'333','UTP dugó','db',NULL,NULL,NULL,1,1,1,'2026-03-11 21:30:13','2026-03-11 21:30:13');
/*!40000 ALTER TABLE `material_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(10) unsigned NOT NULL,
  `material_id` int(10) unsigned NOT NULL,
  `movement_type` varchar(40) NOT NULL,
  `quantity_change` decimal(14,3) NOT NULL,
  `quantity_before` decimal(14,3) NOT NULL,
  `quantity_after` decimal(14,3) NOT NULL,
  `reference_no` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `performed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sm_warehouse` (`warehouse_id`),
  KEY `idx_sm_material` (`material_id`),
  KEY `idx_sm_type` (`movement_type`),
  KEY `idx_sm_created` (`created_at`),
  KEY `idx_sm_user` (`performed_by`),
  CONSTRAINT `fk_sm_material` FOREIGN KEY (`material_id`) REFERENCES `material_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES
(1,1,1,'receipt',100.000,0.000,100.000,NULL,NULL,1,'2026-03-11 20:52:57'),
(2,1,1,'transfer_out',-1.000,100.000,99.000,'TR-000001','Raktárközi átadás #1 [1 → r2]',1,'2026-03-11 20:53:52'),
(3,2,1,'transfer_in',1.000,0.000,1.000,'TR-000001','Raktárközi átadás #1 [1 → r2]',1,'2026-03-11 20:53:52'),
(4,1,2,'receipt',150.000,0.000,150.000,NULL,NULL,1,'2026-03-11 21:30:26'),
(5,1,2,'adjustment_add',100.000,150.000,250.000,NULL,NULL,1,'2026-03-11 21:34:15'),
(6,1,2,'receipt',1.000,250.000,251.000,NULL,NULL,1,'2026-03-11 21:34:29');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_transfers`
--

DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_warehouse_id` int(10) unsigned NOT NULL,
  `target_warehouse_id` int(10) unsigned NOT NULL,
  `material_id` int(10) unsigned NOT NULL,
  `quantity` decimal(14,3) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reference_no` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `requested_by` int(10) unsigned DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_by` int(10) unsigned DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(10) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_st_source` (`source_warehouse_id`),
  KEY `idx_st_target` (`target_warehouse_id`),
  KEY `idx_st_material` (`material_id`),
  KEY `idx_st_status` (`status`),
  KEY `idx_st_requested_at` (`requested_at`),
  CONSTRAINT `fk_st_material` FOREIGN KEY (`material_id`) REFERENCES `material_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_source` FOREIGN KEY (`source_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_target` FOREIGN KEY (`target_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_transfers`
--

LOCK TABLES `stock_transfers` WRITE;
/*!40000 ALTER TABLE `stock_transfers` DISABLE KEYS */;
INSERT INTO `stock_transfers` VALUES
(1,1,2,1,1.000,'accepted',NULL,NULL,NULL,1,'2026-03-11 20:51:58',1,'2026-03-11 20:53:52',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `stock_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_stock`
--

DROP TABLE IF EXISTS `warehouse_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_stock` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(10) unsigned NOT NULL,
  `material_id` int(10) unsigned NOT NULL,
  `quantity` decimal(14,3) NOT NULL DEFAULT 0.000,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_warehouse_material` (`warehouse_id`,`material_id`),
  KEY `idx_ws_material` (`material_id`),
  KEY `idx_ws_qty` (`quantity`),
  CONSTRAINT `fk_ws_material` FOREIGN KEY (`material_id`) REFERENCES `material_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ws_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_stock`
--

LOCK TABLES `warehouse_stock` WRITE;
/*!40000 ALTER TABLE `warehouse_stock` DISABLE KEYS */;
INSERT INTO `warehouse_stock` VALUES
(1,1,1,99.000,1,1,'2026-03-11 20:52:57','2026-03-11 20:53:52'),
(2,2,1,1.000,1,1,'2026-03-11 20:53:52','2026-03-11 20:53:52'),
(3,1,2,251.000,1,1,'2026-03-11 21:30:26','2026-03-11 21:34:29');
/*!40000 ALTER TABLE `warehouse_stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_user_access`
--

DROP TABLE IF EXISTS `warehouse_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_user_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(10) unsigned NOT NULL,
  `auth_user_id` int(10) unsigned NOT NULL,
  `role_key` varchar(30) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_warehouse_user` (`warehouse_id`,`auth_user_id`),
  KEY `idx_wua_user` (`auth_user_id`),
  CONSTRAINT `fk_wua_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_user_access`
--

LOCK TABLES `warehouse_user_access` WRITE;
/*!40000 ALTER TABLE `warehouse_user_access` DISABLE KEYS */;
INSERT INTO `warehouse_user_access` VALUES
(1,1,1,'admin',1,'2026-03-11 18:43:03'),
(2,1,2,'user',1,'2026-03-11 18:43:14'),
(3,2,1,'admin',1,'2026-03-11 20:51:23');
/*!40000 ALTER TABLE `warehouse_user_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `code` varchar(80) NOT NULL,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_warehouses_code` (`code`),
  KEY `idx_warehouses_parent` (`parent_id`),
  CONSTRAINT `fk_warehouses_parent` FOREIGN KEY (`parent_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES
(1,NULL,'1','RAKTÁR',NULL,1,1,1,'2026-03-11 18:42:54','2026-03-11 18:42:54'),
(2,NULL,'r2','Raki2',NULL,1,1,1,'2026-03-11 20:51:18','2026-03-11 20:51:18');
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'warehousemgr'
--

--
-- Dumping routines for database 'warehousemgr'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-11 23:21:08
