/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: hr
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
-- Table structure for table `custom_field_options`
--

DROP TABLE IF EXISTS `custom_field_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_field_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `field_id` int(10) unsigned NOT NULL,
  `option_value` varchar(190) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_cf_opt_field` (`field_id`),
  KEY `idx_cf_opt_active` (`is_active`),
  CONSTRAINT `fk_cf_opt_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_field_options`
--

LOCK TABLES `custom_field_options` WRITE;
/*!40000 ALTER TABLE `custom_field_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_field_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_fields`
--

DROP TABLE IF EXISTS `custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `field_key` varchar(80) NOT NULL,
  `label` varchar(190) NOT NULL,
  `field_type` enum('text','multiselect','file') NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `help_text` varchar(255) DEFAULT NULL,
  `settings_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_fields_key` (`field_key`),
  KEY `idx_custom_fields_type` (`field_type`),
  KEY `idx_custom_fields_active` (`is_active`),
  KEY `idx_custom_fields_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_fields`
--

LOCK TABLES `custom_fields` WRITE;
/*!40000 ALTER TABLE `custom_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `divisions`
--

DROP TABLE IF EXISTS `divisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `divisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_divisions_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `divisions`
--

LOCK TABLES `divisions` WRITE;
/*!40000 ALTER TABLE `divisions` DISABLE KEYS */;
INSERT INTO `divisions` VALUES
(1,'Irodaház',1,'2026-01-23 20:10:22',NULL),
(2,'Központos',1,'2026-01-23 20:10:29',NULL),
(3,'Széltoló',1,'2026-01-23 20:10:38',NULL),
(4,'Érdekfeszítő',1,'2026-01-23 20:10:49',NULL);
/*!40000 ALTER TABLE `divisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_types`
--

DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_types_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_types`
--

LOCK TABLES `document_types` WRITE;
/*!40000 ALTER TABLE `document_types` DISABLE KEYS */;
INSERT INTO `document_types` VALUES
(1,'Jogosítvány',1,'2026-01-23 20:35:45','2026-01-24 00:55:36'),
(2,'FAM',1,'2026-01-23 20:35:50',NULL),
(3,'Oklevél',1,'2026-01-24 08:42:59',NULL);
/*!40000 ALTER TABLE `document_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_addresses`
--

DROP TABLE IF EXISTS `employee_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_addresses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `postal_code` varchar(16) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address_line` varchar(200) DEFAULT NULL,
  `type` enum('home','temporary','other') NOT NULL DEFAULT 'home',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_empaddr_employee_id` (`employee_id`),
  CONSTRAINT `fk_empaddr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_addresses`
--

LOCK TABLES `employee_addresses` WRITE;
/*!40000 ALTER TABLE `employee_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_documents`
--

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `document_type_id` int(10) unsigned NOT NULL,
  `doc_type` varchar(120) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_empdoc_employee_id` (`employee_id`),
  KEY `idx_empdoc_doc_type` (`doc_type`),
  KEY `idx_empdoc_expires` (`expires_on`),
  KEY `idx_empdocs_type` (`document_type_id`),
  KEY `idx_empdocs_expires` (`expires_at`),
  CONSTRAINT `fk_empdoc_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_empdocs_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_documents`
--

LOCK TABLES `employee_documents` WRITE;
/*!40000 ALTER TABLE `employee_documents` DISABLE KEYS */;
INSERT INTO `employee_documents` VALUES
(1,1,1,NULL,'akármi','/uploads/docs/d_20260123_204723_171379fd.pdf','1.pdf','application/pdf',NULL,39735,'2026-01-02',NULL,NULL,'2026-01-23 20:47:23',NULL),
(2,1,2,NULL,'Fammmmmm','/uploads/docs/d_20260123_205159_755cb860.pdf','2 melléklet 2 oldal_LRTKTV_alairt.pdf','application/pdf',NULL,983370,'2026-05-14',NULL,NULL,'2026-01-23 20:51:59',NULL);
/*!40000 ALTER TABLE `employee_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_emails`
--

DROP TABLE IF EXISTS `employee_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_emails` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `label` varchar(60) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_email_emp` (`employee_id`),
  KEY `idx_emp_email_email` (`email`),
  CONSTRAINT `fk_emp_email_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_emails`
--

LOCK TABLES `employee_emails` WRITE;
/*!40000 ALTER TABLE `employee_emails` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_emails` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_field_value_options`
--

DROP TABLE IF EXISTS `employee_field_value_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_field_value_options` (
  `employee_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `option_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`employee_id`,`field_id`,`option_id`),
  KEY `fk_efvo_opt` (`option_id`),
  KEY `idx_efvo_emp` (`employee_id`),
  KEY `idx_efvo_field` (`field_id`),
  CONSTRAINT `fk_efvo_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_efvo_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_efvo_opt` FOREIGN KEY (`option_id`) REFERENCES `custom_field_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_field_value_options`
--

LOCK TABLES `employee_field_value_options` WRITE;
/*!40000 ALTER TABLE `employee_field_value_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_field_value_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_field_values`
--

DROP TABLE IF EXISTS `employee_field_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_field_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `value` text DEFAULT NULL,
  `show_on_card` tinyint(1) NOT NULL DEFAULT 1,
  `value_text` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_efv_emp_field` (`employee_id`,`field_id`),
  KEY `idx_efv_emp` (`employee_id`),
  KEY `idx_efv_field` (`field_id`),
  KEY `idx_efv_field_id` (`field_id`),
  CONSTRAINT `fk_efv_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_efv_employee_fields` FOREIGN KEY (`field_id`) REFERENCES `employee_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_field_values`
--

LOCK TABLES `employee_field_values` WRITE;
/*!40000 ALTER TABLE `employee_field_values` DISABLE KEYS */;
INSERT INTO `employee_field_values` VALUES
(2,1,1,'qqq',1,NULL,'2026-01-23 22:29:15','2026-02-20 10:53:09'),
(10,1,2,'[\"óvoda\",\"középsuti\"]',1,NULL,'2026-01-23 23:37:05','2026-02-20 10:53:09');
/*!40000 ALTER TABLE `employee_field_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_fields`
--

DROP TABLE IF EXISTS `employee_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_type` enum('text','textarea','select','multiselect','date','number') NOT NULL DEFAULT 'text',
  `options` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_fields_key` (`field_key`),
  KEY `idx_employee_fields_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_fields`
--

LOCK TABLES `employee_fields` WRITE;
/*!40000 ALTER TABLE `employee_fields` DISABLE KEYS */;
INSERT INTO `employee_fields` VALUES
(1,'Biztosítás','biztositas','text',NULL,1,'2026-01-23 22:09:10',NULL),
(2,'Iskolai végzettség','iskolai_vegzettseg','multiselect','[\"óvoda\",\"általános iskola\",\"középsuti\",\"főiskola\",\"egyetem\",\"az élet iskolája\"]',1,'2026-01-23 23:36:48','2026-01-24 00:56:21'),
(3,'akármi','akarmi','text',NULL,1,'2026-01-27 10:03:11',NULL);
/*!40000 ALTER TABLE `employee_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_files`
--

DROP TABLE IF EXISTS `employee_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `doc_type_label` varchar(190) DEFAULT NULL,
  `stored_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_emp_files_uploader` (`uploaded_by`),
  KEY `idx_emp_files_emp` (`employee_id`),
  KEY `idx_emp_files_field` (`field_id`),
  KEY `idx_emp_files_doc` (`doc_type_label`),
  CONSTRAINT `fk_emp_files_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_files_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_files_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_files`
--

LOCK TABLES `employee_files` WRITE;
/*!40000 ALTER TABLE `employee_files` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_phones`
--

DROP TABLE IF EXISTS `employee_phones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_phones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `label` varchar(60) DEFAULT NULL,
  `phone` varchar(60) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_phone_emp` (`employee_id`),
  KEY `idx_emp_phone_phone` (`phone`),
  CONSTRAINT `fk_emp_phone_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_phones`
--

LOCK TABLES `employee_phones` WRITE;
/*!40000 ALTER TABLE `employee_phones` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_phones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(190) NOT NULL,
  `birth_name` varchar(190) DEFAULT NULL,
  `mother_name` varchar(190) DEFAULT NULL,
  `birth_place` varchar(190) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `addr_zip` varchar(20) DEFAULT NULL,
  `addr_city` varchar(120) DEFAULT NULL,
  `addr_line` varchar(255) DEFAULT NULL,
  `company_emp_no` varchar(60) DEFAULT NULL,
  `company_division` varchar(120) DEFAULT NULL,
  `division_id` int(10) unsigned DEFAULT NULL,
  `tax_id` varchar(32) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `profile_image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_company_emp_no` (`company_emp_no`),
  KEY `idx_employees_name` (`full_name`),
  KEY `idx_employees_active` (`is_active`),
  KEY `idx_employees_tax` (`tax_id`),
  KEY `idx_employees_division_id` (`division_id`),
  CONSTRAINT `fk_employees_division` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES
(1,'Kalamár János (HR)',NULL,'Aur Mária','Nagykanizsa','1970-01-06','8800','Nagykanizsa','Magyar u. 4-6','XXX007',NULL,4,'12345678','janos@kalamar.hu','707768006','wwwww','/uploads/profile/p_20260123_202327_c32ae7cb.png',1,'2026-01-23 20:23:27','2026-02-20 10:53:09'),
(2,'Kalamár János SECOND',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-02-20 13:14:38','2026-02-20 13:14:38'),
(3,'Mónus József (TESZT)',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-03-10 10:47:46','2026-03-10 10:47:46'),
(4,'Nagy Zsolt (TESZT)',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-03-10 10:48:02','2026-03-10 10:48:02');
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` char(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expires` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_sessions`
--

LOCK TABLES `user_sessions` WRITE;
/*!40000 ALTER TABLE `user_sessions` DISABLE KEYS */;
INSERT INTO `user_sessions` VALUES
('2c07d655978aa34733d02654b45c56204f08c47e37dee8973f58a7c7b54423cf',3,'192.168.16.124','Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:147.0) Gecko/20100101 Firefox/147.0','2026-01-27 12:28:29','2026-01-28 12:28:29'),
('8e5368320c6cade952a9444b179cd5c7803636754195d4eef6e0d86b9d1bdef2',3,'192.168.16.124','Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:147.0) Gecko/20100101 Firefox/147.0','2026-01-27 14:33:15','2026-01-28 14:33:15');
/*!40000 ALTER TABLE `user_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Admin','admin@a','$2y$10$jrmv29QpURJbSfh7j2Mw2O5ubHMVqPYBjMUKV6I4siU6M9CyCs8Wu','admin',1,'2026-01-27 10:00:55','2026-01-23 17:01:00','2026-01-27 10:00:55'),
(2,'Kaly','kaly@a.a','$2y$10$MoQ.oJl0zRO5jzC9hBTJHO0.g4WpBRXot.iqPcvwqSXbo/Af.JxPe','admin',1,NULL,'2026-01-23 19:25:37','2026-01-23 19:25:37'),
(3,'Csilla','csilla@perfect-phone.hu','$2y$10$.S5CO4Sl.4DQL8TS8irmfecCrkiO.jBtzvAUfA3TqzzhaWWMXBNMK','admin',1,'2026-01-27 14:33:15','2026-01-27 10:06:18','2026-01-27 14:33:15');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-10 13:32:29
