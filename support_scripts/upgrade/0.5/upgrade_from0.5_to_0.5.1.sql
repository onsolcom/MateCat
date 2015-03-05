USE matecat;

ALTER TABLE `engines`
DROP COLUMN `gloss_get_relative_url`,
DROP COLUMN `gloss_set_relative_url`,
DROP COLUMN `gloss_update_relative_url`,
DROP COLUMN `gloss_delete_relative_url`,
DROP COLUMN `tmx_import_relative_url`,
DROP COLUMN `tmx_status_relative_url`,
ADD COLUMN `uid`  int(11) UNSIGNED NULL DEFAULT NULL AFTER `active`,
ADD index uid_idx(uid),
CHANGE COLUMN penalty penalty int(11) NOT NULL DEFAULT '14',
CHANGE COLUMN `extra_parameters`  varchar(2048) NOT NULL DEFAULT '{}',
ADD COLUMN `class_load`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `others`;
