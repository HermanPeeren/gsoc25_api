<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_ccm
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\CCM\Administrator\View\Migration;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\CCM\Administrator\Migration\Migration;

class HtmlView extends BaseHtmlView
{
    public $item;
    public $form;
    public $state;

    public function display($tpl = null): void
    {
        /** @var Migration $model */
        $model = $this->getModel();
        
        $this->item  = $model->getItem();
        $this->form  = $model->getForm();
        $this->state = $model->getState();

        $this->addToolbar();

        // TODO
        // after each step say echo "Mapping is done" --> then echo "Migration is done"
        // this can be added in js in frontend in media folder
        // from webassets 
        parent::display($tpl);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_CCM_TITLE_MIGRATION'), 'refresh');
        
        // Don't add apply button here - we'll use the form button instead
        ToolbarHelper::cancel('migration.cancel');
    }
}