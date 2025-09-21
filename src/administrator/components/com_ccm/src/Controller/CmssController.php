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

use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
/**
 * CmssController class.
 *
 * @since 1.0.0
 */
class CmssController extends AdminController
{
    public function add()
    {
        echo "Adding a new CMS item.\n";
        error_log("CmssController::add called");
        $this->setRedirect(Route::_('index.php?option=com_ccm&task=cmss.edit', false));
    }
}