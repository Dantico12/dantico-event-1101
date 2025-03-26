-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Feb 08, 2025 at 05:37 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `event`
--

-- --------------------------------------------------------

--
-- Table structure for table `contributions`
--

CREATE TABLE `contributions` (
  `id` int(11) NOT NULL,
  `sender_phone` varchar(15) DEFAULT NULL,
  `recipient_phone` varchar(15) DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `checkout_request_id` varchar(50) DEFAULT NULL,
  `mpesa_receipt_number` varchar(50) DEFAULT NULL,
  `transaction_status` varchar(20) DEFAULT NULL,
  `mpesa_response` text DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contributions`
--

INSERT INTO `contributions` (`id`, `sender_phone`, `recipient_phone`, `transaction_id`, `checkout_request_id`, `mpesa_receipt_number`, `transaction_status`, `mpesa_response`, `remarks`) VALUES
(1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `event_code` varchar(10) NOT NULL,
  `status` varchar(50) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `phone_paybill` varchar(50) DEFAULT NULL,
  `event_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `event_code`, `status`, `created_at`, `phone_paybill`, `event_name`) VALUES
(3, 'JOH5PJ1D4L', 'active', '2025-01-27 18:17:24', '33422', 'John Gathogo Burial'),
(4, 'DAVTN059JO', 'active', '2025-02-04 13:39:15', NULL, 'Davis Okeyo Wedding'),
(6, 'ALEI1TWFDE', 'active', '2025-02-04 15:17:47', NULL, 'alex Mwakindeu Dowry');

-- --------------------------------------------------------

--
-- Table structure for table `event_members`
--

CREATE TABLE `event_members` (
  `id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT 'member',
  `committee_role` varchar(100) DEFAULT NULL,
  `joined_via_link` tinyint(4) DEFAULT 0,
  `joined_at` datetime DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_members`
--

INSERT INTO `event_members` (`id`, `event_id`, `user_id`, `role`, `committee_role`, `joined_via_link`, `joined_at`, `status`) VALUES
(1, 1, 2, 'admin', 'organizer', 0, '2025-01-23 14:10:36', 'active'),
(2, 1, 1, 'member', NULL, 0, '2025-01-23 14:11:03', 'active'),
(3, 2, 1, 'admin', 'organizer', 0, '2025-01-23 14:18:25', 'active'),
(4, 2, 2, 'member', NULL, 0, '2025-01-23 14:19:24', 'active'),
(5, 2, 3, 'member', NULL, 0, '2025-01-23 15:14:24', 'active'),
(6, 1, 3, 'member', NULL, 0, '2025-01-23 15:18:46', 'active'),
(7, 2, 4, 'member', NULL, 0, '2025-01-23 15:30:20', 'active'),
(8, 3, 1, 'admin', 'organizer', 0, '2025-01-27 18:17:24', 'active'),
(9, 3, 2, 'member', 'chairman', 0, '2025-01-27 18:24:10', 'active'),
(10, 3, 3, 'member', 'secretary', 0, '2025-02-04 11:47:10', 'active'),
(11, 4, 1, 'admin', 'organizer', 0, '2025-02-04 13:39:15', 'active'),
(12, 5, 1, 'admin', 'organizer', 0, '2025-02-04 13:39:30', 'active'),
(13, 6, 1, 'admin', 'organizer', 0, '2025-02-04 15:17:47', 'active'),
(14, 6, 2, 'member', 'secretary', 0, '2025-02-04 15:24:20', 'active'),
(15, 3, 4, 'member', 'treasurer', 0, '2025-02-06 09:00:23', 'active'),
(16, 6, 3, 'member', NULL, 0, '2025-02-06 20:19:01', 'active'),
(17, 3, 6, 'member', NULL, 0, '2025-02-06 20:25:27', 'active'),
(18, 6, 6, 'member', NULL, 0, '2025-02-07 10:31:59', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `meeting_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `meeting_type` enum('board','committee','planning') NOT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('Scheduled','In Progress','Ended','Completed','Cancelled') DEFAULT 'Scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) NOT NULL,
  `meeting_start` datetime GENERATED ALWAYS AS (timestamp(`meeting_date`,`meeting_time`)) STORED,
  `meeting_end` datetime GENERATED ALWAYS AS (timestamp(`meeting_date`,`end_time`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meetings`
--

INSERT INTO `meetings` (`meeting_id`, `event_id`, `meeting_type`, `meeting_date`, `meeting_time`, `end_time`, `status`, `created_at`, `updated_at`, `created_by`) VALUES
(14, 3, 'committee', '2025-01-31', '16:00:00', '18:00:00', 'Ended', '2025-01-29 20:22:15', '2025-02-08 16:30:31', 1),
(18, 3, 'committee', '2025-01-31', '14:00:00', '16:00:00', 'Ended', '2025-01-30 13:34:38', '2025-02-08 16:30:31', 1),
(19, 3, 'board', '2025-02-05', '15:00:00', '17:00:00', 'Ended', '2025-01-31 13:39:48', '2025-02-08 16:30:31', 1),
(20, 3, 'planning', '2025-02-05', '14:00:00', '15:30:00', 'Ended', '2025-01-31 14:23:45', '2025-02-08 16:30:31', 2),
(22, 3, 'committee', '2025-03-31', '15:30:00', '16:30:00', 'Scheduled', '2025-01-31 20:20:16', '2025-02-08 16:30:31', 1),
(24, 3, 'planning', '2025-02-01', '10:00:00', '00:00:00', 'Ended', '2025-02-01 14:35:55', '2025-02-08 16:30:31', 1),
(25, 3, 'board', '2025-02-01', '10:30:00', '12:00:00', 'Ended', '2025-02-01 15:30:27', '2025-02-08 16:30:31', 1),
(27, 3, 'committee', '2025-02-03', '08:30:00', '11:00:00', 'Ended', '2025-02-03 13:09:03', '2025-02-08 16:30:31', 1),
(28, 3, 'committee', '2025-02-03', '14:00:00', '16:00:00', 'Ended', '2025-02-03 16:12:09', '2025-02-08 16:30:31', 1),
(29, 6, 'committee', '2025-02-05', '14:00:00', '16:00:00', 'Ended', '2025-02-04 20:46:45', '2025-02-08 16:30:31', 2),
(30, 4, 'planning', '2025-02-07', '15:00:00', '17:00:00', 'Ended', '2025-02-04 21:27:37', '2025-02-08 16:30:31', 1),
(31, 3, 'planning', '2025-02-05', '16:00:00', '18:00:00', 'Ended', '2025-02-05 20:58:50', '2025-02-08 16:30:31', 1),
(32, 3, 'planning', '2025-02-05', '18:50:00', '08:00:00', 'Ended', '2025-02-05 23:46:30', '2025-02-08 16:30:31', 1),
(36, 3, 'planning', '2025-02-07', '12:00:00', '14:00:00', 'Ended', '2025-02-07 17:03:53', '2025-02-08 16:30:31', 1),
(37, 6, 'committee', '2025-02-07', '12:50:00', '15:00:00', 'Ended', '2025-02-07 17:48:14', '2025-02-08 16:30:31', 1),
(38, 6, 'planning', '2025-03-01', '14:00:00', '17:00:00', 'Scheduled', '2025-02-07 17:49:50', '2025-02-08 16:30:31', 1),
(39, 3, 'planning', '2025-02-07', '14:50:00', '18:00:00', 'Ended', '2025-02-07 19:44:15', '2025-02-08 16:30:31', 1),
(40, 3, 'board', '2025-02-07', '15:10:00', '17:00:00', 'Ended', '2025-02-07 19:54:12', '2025-02-08 16:30:31', 1);

--
-- Triggers `meetings`
--
DELIMITER $$
CREATE TRIGGER `before_meeting_insert` BEFORE INSERT ON `meetings` FOR EACH ROW BEGIN
    SET NEW.created_at = CURRENT_TIMESTAMP();
    SET NEW.updated_at = CURRENT_TIMESTAMP();
    
    IF NEW.meeting_start > CURRENT_TIMESTAMP() THEN
        SET NEW.status = 'Scheduled';
    ELSEIF NEW.meeting_start <= CURRENT_TIMESTAMP() AND NEW.meeting_end > CURRENT_TIMESTAMP() THEN
        SET NEW.status = 'In Progress';
    ELSE
        SET NEW.status = 'Ended';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_meeting_update` BEFORE UPDATE ON `meetings` FOR EACH ROW BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP();
    
    -- Only update status if not manually cancelled
    IF NEW.status != 'Cancelled' THEN
        IF NEW.meeting_start > CURRENT_TIMESTAMP() THEN
            SET NEW.status = 'Scheduled';
        ELSEIF NEW.meeting_start <= CURRENT_TIMESTAMP() AND NEW.meeting_end > CURRENT_TIMESTAMP() THEN
            SET NEW.status = 'In Progress';
        ELSE
            SET NEW.status = 'Ended';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `message_type` varchar(50) DEFAULT 'text',
  `attachment_url` text DEFAULT NULL,
  `read_status` varchar(50) DEFAULT 'unread',
  `client_message_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `event_id`, `sender_id`, `message`, `sent_at`, `message_type`, `attachment_url`, `read_status`, `client_message_id`) VALUES
(1, 3, 1, 'hello sir', '2025-01-28 12:31:44', 'text', NULL, 'unread', NULL),
(2, 3, 1, 'how is the event planning going', '2025-01-28 12:32:56', 'text', NULL, 'unread', NULL),
(3, 0, 2, 'hello sir', '2025-01-28 12:34:11', 'text', NULL, 'unread', NULL),
(4, 3, 1, 'can we do a meeting', '2025-01-28 12:42:08', 'text', NULL, 'unread', NULL),
(5, 3, 2, 'yes we can do a meeting but i cant be available today maybe tomorrow', '2025-01-28 12:43:08', 'text', NULL, 'unread', NULL),
(6, 0, 1, 'hello', '2025-01-28 13:24:14', 'text', NULL, 'unread', NULL),
(7, 0, 1, 'hello sir', '2025-01-28 14:24:55', 'text', NULL, 'unread', NULL),
(8, 0, 1, 'hello sir how are you doing?', '2025-01-28 14:25:58', 'text', NULL, 'unread', NULL),
(9, 3, 2, 'sure the planning are fine we can restructure the meemting please?', '2025-01-28 14:26:39', 'text', NULL, 'unread', NULL),
(10, 3, 2, 'sure thing sir i think we are set we should create that mmeting', '2025-01-28 14:44:40', 'text', NULL, 'unread', NULL),
(11, 3, 1, 'okay lets do this', '2025-01-28 14:45:07', 'text', NULL, 'unread', NULL),
(12, 6, 2, 'hello sir', '2025-02-06 08:57:23', 'text', NULL, 'unread', NULL),
(13, 3, 1, 'are you ready for the meeting?', '2025-02-06 08:58:07', 'text', NULL, 'unread', NULL),
(14, 6, 1, 'hello how are you doing', '2025-02-06 08:59:09', 'text', NULL, 'unread', NULL),
(15, 3, 4, 'hello how are the meeting pland going so far', '2025-02-06 09:01:00', 'text', NULL, 'unread', NULL),
(16, 3, 6, 'thinking the same thing sir', '2025-02-07 09:02:22', 'text', NULL, 'unread', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `meeting_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `assigned_to` varchar(50) NOT NULL DEFAULT '',
  `due_date` date NOT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`task_id`, `event_id`, `meeting_id`, `description`, `assigned_to`, `due_date`, `status`, `created_at`) VALUES
(16, 0, 18, 'create event profile', 'Karenju', '2025-02-02', 'pending', '2025-01-31 18:16:37'),
(17, 0, 19, 'Venue Selection', 'Davis', '2025-02-04', 'completed', '2025-01-31 18:18:39'),
(18, 6, 29, 'paybill number registartion', 'Karenju', '2025-02-05', 'pending', '2025-02-04 20:53:03'),
(19, 6, 29, 'hightlight the event needs ', 'Karenju', '2025-02-07', 'pending', '2025-02-04 20:54:04'),
(20, 3, 20, 'venue selection please', 'Mboya', '2025-02-20', 'completed', '2025-02-04 21:12:38'),
(21, 4, 30, 'Clearing and clearing venue monitoring', 'Karenju', '2025-02-05', 'completed', '2025-02-04 21:28:20');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'user',
  `last_login` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active` datetime DEFAULT NULL,
  `online_status` varchar(20) DEFAULT 'offline'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `last_login`, `created_at`, `last_active`, `online_status`) VALUES
(1, 'Karenju', 'karenjuduncan750@gmail.com', '$2y$10$MpTU3xlCHAf7s9mPN7RbyOtjMYdXcpntQ1NHM5d54udyUdVO35DvW', 'user', '2025-02-08 16:30:23', '2025-01-23 19:08:24', '2025-02-08 11:30:23', 'online'),
(2, 'Davis', 'dokeyo390@gmail.com', '$2y$10$etjPWJV3EwcIPbvWRXiTReSxBzfVFkpB9pxIsLc9f8HgZiCTt4lsu', 'user', '2025-02-07 13:21:25', '2025-01-23 19:09:39', '2025-02-07 08:21:25', 'online'),
(3, 'Mboya', 'mboya12@gmail.com', '$2y$10$PYK9k0F3fN9pa6K6iQVYjuFDovz9dBfOAa.aF9J/EMQ9.RsqnzhSy', 'user', '2025-02-07 20:18:33', '2025-01-23 20:13:46', '2025-02-07 15:18:33', 'online'),
(4, 'Anne', 'annem13@gmail.com', '$2y$10$Fvvn4wEqPorpMxOWcNel4eZEsxgUs5rNquucHno3CIvBQYWPAdLuC', 'user', '2025-02-07 19:43:35', '2025-01-23 20:30:05', '2025-02-07 14:43:35', 'online'),
(5, 'Njoki', 'njoki98@gmail.com', '$2y$10$Aodr2GZG0P60S4w8coQFOeU.ZtCBeHTcCK1pw4y6VADx227TyLWn.', 'user', '2025-02-07 01:23:15', '2025-02-07 01:23:15', NULL, 'offline'),
(6, 'Elisha', 'elishatoto@gmail.com', '$2y$10$eZcoekbKlj.KjCZ6c0SfquCgcC4V07pX6NJnhLRukXhgqPuY8w1yC', 'user', '2025-02-08 15:39:46', '2025-02-07 01:24:43', '2025-02-08 10:39:46', 'online');

-- --------------------------------------------------------

--
-- Table structure for table `video_meetings`
--

CREATE TABLE `video_meetings` (
  `id` varchar(255) NOT NULL,
  `host_id` int(11) DEFAULT NULL,
  `meeting_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contributions`
--
ALTER TABLE `contributions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_code` (`event_code`);

--
-- Indexes for table `event_members`
--
ALTER TABLE `event_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_membership` (`event_id`,`user_id`);

--
-- Indexes for table `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`meeting_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_meeting_times` (`meeting_start`,`meeting_end`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_id` (`event_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `fk_meeting_id` (`meeting_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `video_meetings`
--
ALTER TABLE `video_meetings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `host_id` (`host_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contributions`
--
ALTER TABLE `contributions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `event_members`
--
ALTER TABLE `event_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `meeting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `meetings`
--
ALTER TABLE `meetings`
  ADD CONSTRAINT `meetings_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`),
  ADD CONSTRAINT `meetings_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_meeting_id` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`meeting_id`);

--
-- Constraints for table `video_meetings`
--
ALTER TABLE `video_meetings`
  ADD CONSTRAINT `video_meetings_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
