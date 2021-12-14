-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/Wikibase/repo/sql/abstract/wb_changes_dispatch.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE wb_changes_dispatch (
  chd_site TEXT NOT NULL,
  chd_db TEXT NOT NULL,
  chd_seen INT DEFAULT 0 NOT NULL,
  chd_touched TEXT DEFAULT '00000000000000' NOT NULL,
  chd_lock TEXT DEFAULT NULL,
  chd_disabled SMALLINT DEFAULT 0 NOT NULL,
  PRIMARY KEY(chd_site)
);

CREATE INDEX wb_changes_dispatch_chd_seen ON wb_changes_dispatch (chd_seen);

CREATE INDEX wb_changes_dispatch_chd_touched ON wb_changes_dispatch (chd_touched);