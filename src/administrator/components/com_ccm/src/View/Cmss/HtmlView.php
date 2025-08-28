<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  CCM
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Reem\Component\CCM\Administrator\View\Cmss;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Content\Administrator\Helper\ContentHelper;
use Joomla\CMS\Language\Text;

class HtmlView extends BaseHtmlView
{
    public $items;
    public $filterForm;
    public $activeFilters;
    public $pagination;
    public $state;

    public function display($tpl = null): void
    {
        /** @var Cmss $model */
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar()
    {
        $canDo = ContentHelper::getActions('com_ccm');
        $toolbar = $this->getDocument()->getToolbar();
        ToolbarHelper::title(Text::_('COM_CCM_TITLE_CMS'), 'generic');

        if ($canDo->get('core.create')) {
            $toolbar->addNew('cms.add');
        }

        if ($canDo->get('core.edit')) {
            $toolbar->edit('cms.edit');
        }

        if ($canDo->get('core.delete')) {
            $toolbar->delete('cmss.delete');
        }
    }
}