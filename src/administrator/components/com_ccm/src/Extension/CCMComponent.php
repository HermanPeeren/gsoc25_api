<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

// starting point for the component
class CCMComponent extends MVCComponent implements BootableExtensionInterface
{
    public function boot(ContainerInterface $container) {
        // Initialize your component here
    }

    /**
     * Returns valid contexts
     *
     * @return  array
     *
     * @since 1.0.0
     */
    public function getContexts(): array
    {
        return ['com_ccm.cms', 'com_ccm.migration'];
    }
}
