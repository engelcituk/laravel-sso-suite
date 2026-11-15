CREATE DATABASE fpm_one CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE DATABASE fpm_two CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
GRANT ALL ON fpm_one.* TO 'sso_lab'@'%';
GRANT ALL ON fpm_two.* TO 'sso_lab'@'%';
