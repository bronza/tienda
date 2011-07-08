<?php defined('_JEXEC') or die('Restricted access'); ?>
<?php JHTML::_('script', 'tienda.js', 'media/com_tienda/js/'); ?>
<?php $state = @$vars->state; ?>

    <p><?php echo JText::_( "THIS REPORTS ON SALES FOR EVERY DAY DURING DATE RANGE" ); ?></p>

    <div class="note">
	    <?php echo JText::_("SELECT DATE RANGE"); ?>:
	    <?php $attribs = array('class' => 'inputbox', 'size' => '1'); ?>
	    <?php echo TiendaSelect::reportrange( @$state->filter_range ? $state->filter_range : 'custom', 'filter_range', $attribs, 'range', true ); ?>
	            <span class="label"><?php echo JText::_("FROM"); ?>:</span>
	            <?php echo JHTML::calendar( @$state->filter_date_from, "filter_date_from", "filter_date_from", '%Y-%m-%d 00:00:00' ); ?>
	            <span class="label"><?php echo JText::_("TO"); ?>:</span>
	            <?php echo JHTML::calendar( @$state->filter_date_to, "filter_date_to", "filter_date_to", '%Y-%m-%d 00:00:00' ); ?>
    </div>
        