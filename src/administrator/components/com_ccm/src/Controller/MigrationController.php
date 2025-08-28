<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Reem\Component\CCM\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

class MigrationController extends BaseController
{
    public function apply()
    {
        $data = $this->input->post->get('jform', [], 'array');
        if (empty($data)) {
            $this->setMessage('No data provided for migration.', 'error');
            $this->setRedirect('index.php?option=com_ccm&view=migration');
            return;
        }

        $sourceCmsId = isset($data['source_cms']) ? (int) $data['source_cms'] : 0;
        $targetCmsId = isset($data['target_cms']) ? (int) $data['target_cms'] : 0;

        /** @var MigrationModel $model */
        $model = $this->getModel();
        
        try {
            // Define migration mappings in the recommended order
            // Categories first (referenced by other content), then media, then content items
            $migrationMappings = [
                ['source' => 'categories', 'target' => 'categories'],
                ['source' => 'tags', 'target' => 'tags'],
                ['source' => 'media', 'target' => 'media'],
                ['source' => 'users', 'target' => 'users'],
                ['source' => 'pages', 'target' => 'articles'],
                ['source' => 'posts', 'target' => 'articles'],
                ['source' => 'menus', 'target' => 'menus'],
                ['source' => 'menu_items', 'target' => 'menu_items']
            ];

            $successfulMigrations = [];
            $failedMigrations = [];

            // Loop through all migration mappings in the recommended order
            foreach ($migrationMappings as $mapping) {
                $sourceType = $mapping['source'];
                $targetType = $mapping['target'];
                
                try {
                    $migrationStatus = $model->migrate($sourceCmsId, $targetCmsId, $sourceType, $targetType);
                    if ($migrationStatus) {
                        $successfulMigrations[] = "$sourceType → $targetType";
                    } else {
                        $failedMigrations[] = "$sourceType → $targetType (status false)";
                    }
                } catch (\Exception $e) {
                    $failedMigrations[] = "$sourceType → $targetType (" . $e->getMessage() . ")";
                }
            }

            // Prepare summary message
            if (!empty($successfulMigrations) && empty($failedMigrations)) {
                $this->setMessage('All migrations completed successfully: ' . implode(', ', $successfulMigrations));
            } elseif (!empty($successfulMigrations) && !empty($failedMigrations)) {
                $message = 'Partial migration completed. ';
                $message .= 'Successful: ' . implode(', ', $successfulMigrations) . '. ';
                $message .= 'Failed: ' . implode(', ', $failedMigrations);
                $this->setMessage($message, 'warning');
            } else {
                $this->setMessage('All migrations failed: ' . implode(', ', $failedMigrations), 'error');
            }
            
        } catch (\Exception $e) {
            $this->setMessage('Migration failed: ' . $e->getMessage(), 'error');
        }
        $this->setRedirect('index.php?option=com_ccm&view=migration');
    }
}