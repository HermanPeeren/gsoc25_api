--
-- Update for version 1.0.1
-- Change credentials field to authentication field to store full authentication headers
--

-- Add new authentication field
ALTER TABLE `#__ccm_cms` ADD COLUMN `authentication` JSON DEFAULT NULL AFTER `url`;

-- Migrate existing credentials data to authentication format
UPDATE `#__ccm_cms` 
SET `authentication` = JSON_OBJECT(
    'type', 'basic',
    'headers', JSON_OBJECT(
        'Authorization', CONCAT('Basic ', `credentials`)
    )
)
WHERE `credentials` IS NOT NULL AND `credentials` != '';

-- Drop old credentials field
ALTER TABLE `#__ccm_cms` DROP COLUMN `credentials`;
