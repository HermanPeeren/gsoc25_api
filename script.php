<?php
/**
 * @package     CCM Package
 * @subpackage  Installation Script
 *
 * @copyright   Copyright (C) 2025 Reem. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Installer\InstallerScript;

/**
 * Installation class to perform additional changes during install/uninstall/update
 *
 * @since  1.0.0
 */
class Pkg_CcmInstallerScript extends InstallerScript
{
    /**
     * Allow downgrades of your extension
     *
     * Use at your own risk as if there is a change in functionality people may wish to downgrade.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $allowDowngrades = true;

    /**
     * Extension script constructor.
     *
     * @since   1.0.0
     */
    public function __construct()
    {
        $this->minimumJoomla = '4.4.0';
        $this->minimumPhp    = JOOMLA_MINIMUM_PHP;
    }
}
