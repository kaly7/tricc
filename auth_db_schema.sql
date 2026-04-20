/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: auth_db
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
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(60) NOT NULL,
  `module_name` varchar(120) NOT NULL,
  `port` smallint(5) unsigned NOT NULL,
  `path` varchar(190) NOT NULL DEFAULT '/',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `modules`
--

LOCK TABLES `modules` WRITE;
/*!40000 ALTER TABLE `modules` DISABLE KEYS */;
INSERT INTO `modules` VALUES
(1,'auth','Auth Center',90,'/',1),
(2,'pbx','PBX',81,'/',1),
(3,'dokutool','DokuTool',82,'/',1),
(4,'projectmgr','ProjectMgr',83,'/index.php?module=projectmgr',1),
(5,'payslip','Payslip',85,'/',1),
(6,'hr','HR',86,'/',1),
(7,'vehicles','Járművek',83,'/vehicles.php?module=vehicles',1),
(8,'assetmgr','Eszköz nyilvántartó',8787,'/',1),
(9,'time_tracker','Munkaidő',8788,'/index.php?module=time_tracker',1);
/*!40000 ALTER TABLE `modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(50) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'admin','Admin'),
(2,'user','User'),
(3,'viewer','Viewer');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_module_roles`
--

DROP TABLE IF EXISTS `user_module_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_module_roles` (
  `user_id` int(10) unsigned NOT NULL,
  `module_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`module_id`),
  KEY `fk_umr_module` (`module_id`),
  KEY `fk_umr_role` (`role_id`),
  CONSTRAINT `fk_umr_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_umr_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_umr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_module_roles`
--

LOCK TABLES `user_module_roles` WRITE;
/*!40000 ALTER TABLE `user_module_roles` DISABLE KEYS */;
INSERT INTO `user_module_roles` VALUES
(1,1,1),
(1,2,1),
(1,3,1),
(1,4,1),
(1,5,1),
(1,6,1),
(1,7,1),
(1,8,1),
(1,9,1),
(2,1,1),
(2,3,1),
(3,5,1),
(3,6,1),
(6,1,1),
(6,2,1),
(6,3,1),
(6,4,1),
(6,5,1),
(6,6,1),
(6,7,1),
(7,3,1),
(7,4,1),
(10,8,1),
(11,8,1),
(2,2,2),
(2,4,2),
(2,9,2),
(9,8,2),
(9,9,2),
(2,8,3),
(7,7,3);
/*!40000 ALTER TABLE `user_module_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(190) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `full_name` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `hr_employee_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_hr_employee` (`hr_employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin',NULL,'Admin user','$2y$10$elM//KKe3yR/cthYwosfdOp/K7Yk5sjxVF0gTjzUCOFN1FOTN0HaW',1,'2026-01-27 20:00:52','2026-03-11 10:02:46','2026-03-11 11:02:46',NULL),
(2,'kaly','kaly@compunet.hu','Kalamár János','$2y$10$ePfOXSQC7xc4kip1AAjnH.YbO0p3UeULmRsYKuHlsP5L.U4TeJN32',1,'2026-01-27 20:47:38','2026-03-10 22:46:57','2026-03-05 14:16:14',1),
(3,'Csilla','csilla@perfect-phone.hu','Budai-Péterbence Csilla','$2y$10$6UdDYCg4MpQHypx6eiJi/.ZRF87Zaeq3m0CXJeOiv70hiPH9aLS0C',1,'2026-01-27 22:46:43','2026-03-10 12:18:38','2026-03-10 13:18:38',NULL),
(6,'alfoldis','alfoldis@dunakanyar.net','Alföldi Sándor','$2y$10$LHV2BxCmpx.0i1532QoDu.9Hstoi49..Okzrc6ls0.HZToUoVUY0y',1,'2026-01-29 20:55:42','2026-02-05 12:30:11','2026-02-05 13:30:11',NULL),
(7,'Bálint','bremenyik@perfect-phone.hu','Remenyik Bálint','$2y$10$IlTWaLVhvxEQJuaAHOGvdex6viuFlcyAVhNttV5nk.obJwhx02DDm',1,'2026-02-05 13:42:55','2026-02-09 08:49:06','2026-02-09 09:49:06',NULL),
(9,'kaly2','kaly2@compunet.hu','Kalamár János SECOND','$2y$10$CD7pZr5jdW/dnOTIu8EHH.k/5WxCJZ8.gnaZIMAfUVSa0ybg/jW5u',1,'2026-02-20 12:17:12','2026-03-03 13:14:21','2026-03-03 14:14:21',2),
(10,'Nagy Zsolt','zsnagy@perfect-phone.hu','Nagy Zsolt','$2y$10$LVS/mrCGG67DL6sGl6nxAuuYtIsTGLGtNvoggSNOIgWmLkShLusvy',1,'2026-03-10 09:35:48','2026-03-10 09:48:38',NULL,4),
(11,'Mónus József','jmonus@perfect-phone.hu','Mónus József','$2y$10$EyzxI.x1uOz4zQKJQc.voe6T4Qi0QQV0X5Dl4xRDShvGMnagHKgcu',1,'2026-03-10 09:36:51','2026-03-10 09:48:32',NULL,3);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'auth_db'
--

--
-- Dumping routines for database 'auth_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-11 19:15:19
