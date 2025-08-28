<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  CCM
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper; // use web asset manager. look at content in joomla cms

defined('_JEXEC') or die;

$listDirn  = $this->escape($this->state->get('list.direction'));
$listOrder = $this->escape($this->state->get('list.ordering'));

HTMLHelper::_('behavior.multiselect');
HTMLHelper::_('formbehavior.chosen', '#filter_search', null, array('placeholder_text_single' => Text::_('JOPTION_SELECT_PUBLISHED')));
?>

<form action="<?php echo Route::_('index.php?option=com_ccm&view=cmss'); ?>" method="post" name="adminForm" id="adminForm">

    <div class="row">
        <div class="col-md-12">
            <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
        </div>
    </div>

    <?php if (empty($this->items)) : ?>
        <div class="alert alert-info">
            <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
            <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
        </div>
    <?php else : ?>
        <table class="table table-striped" id="cmsList">
            <thead>
                <tr>
                    <th width="1%" class="nowrap center">
                        <?php echo HTMLHelper::_('grid.checkall'); ?>
                    </th>
                    <th class="nowrap">
                        <?php echo HTMLHelper::_('searchtools.sort', 'COM_CCM_CMS_NAME', 'a.name', $listDirn, $listOrder); ?>
                    </th>
                    <th width="10%" class="nowrap center">
                        <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->items as $i => $item) : ?>
                    <tr class="row<?php echo $i % 2; ?>">
                        <td class="center">
                            <?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
                        </td>
                        <td class="nowrap has-context">
                            <a href="<?php echo Route::_('index.php?option=com_ccm&task=cms.edit&id=' . (int) $item->id); ?>">
                                <?php echo $this->escape($item->name); ?>
                            </a>
                        </td>
                        <td class="center">
                            <?php echo (int) $item->id; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="" />
    <input type="hidden" name="boxchecked" value="0" />
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
