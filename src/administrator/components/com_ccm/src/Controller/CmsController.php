<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\MVC\Controller\FormController;
use Joomla\Component\CCM\Administrator\Model\CmsModel;

class CmsController extends FormController
{
    protected function getRedirectToListAppend()
    {
        return '&view=cmss';
    }

    public function migrate()
    {
        error_log('CmsController::migrate called');
        $migration = new \Joomla\Component\CCM\Administrator\Migration\Migration();
        $migration->migrate();

        // Optionally redirect or set a message
        $this->setMessage('Migration completed!');
        // $this->setRedirect('index.php?option=com_ccm');
    }
    /**
     * Save the CMS item.
     *
     * @param   string  $key      The name of the primary key of the URL variable.
     * @param   string  $urlVar   The name of the URL variable if different from the primary key.
     *
     * @return  void
     *
     * @since 1.0.0
     */
    public function save($key = null, $urlVar = null)
    {
        $data    = $this->input->post->get('jform', [], 'array');
        $url = isset($data['url']) ? $data['url'] : '';

        /** @var CmsModel $model */
        $model   = $this->getModel();
        $oldItem = $model->getItem($this->input->getInt('id', 0));
        $old_url = $oldItem ? $oldItem->url : null;

        // if ($url !== $old_url && $url !== '') {
        //     $model->discoverCmsProperties($url);
        //     $model->mapCmsToCCM();
        // }

        parent::save($key, $urlVar);
    }
}