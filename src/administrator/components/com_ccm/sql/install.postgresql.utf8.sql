--
-- Table structure for table #__ccm_cms
--

CREATE TABLE IF NOT EXISTS "#__ccm_cms" (
  "id" serial NOT NULL,
  "name" varchar(100) DEFAULT '' NOT NULL,
  "url" varchar(255) DEFAULT '' NOT NULL,
  "credentials" varchar(255) DEFAULT NULL,
  "content_keys_types" json DEFAULT NULL,
  "ccm_mapping" json DEFAULT NULL,
  "created" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "modified" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY ("id")
);

-- Inserting initial data for table #__ccm_cms
INSERT INTO "#__ccm_cms" ("id", "name") VALUES
(1, 'Joomla'),
(2, 'WordPress');
