<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Reem\Component\CCM\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\ListController;
use Joomla\CMS\Router\Route as JRoute;
/**
 * CmssController class.
 *
 * @since  __DEV__
 */
class CmssController extends ListController
{
    public function add()
    {
        echo "Adding a new CMS item.\n";
        error_log("CmssController::add called");
        $this->setRedirect(JRoute::_('index.php?option=com_ccm&task=cmss.edit', false));
    }
}