<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\Service;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Router\ApiRouter;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Component\Router\RouterView;

return new ApiRouter([
    'cmss' => [
        'controller' => 'cmss', // This should match your controller/resource
    ],
]);