<?php
/**
 * @version	1.5
 * @package	Tienda
 * @author 	Dioscouri Design
 * @link 	http://www.dioscouri.com
 * @copyright Copyright (C) 2007 Dioscouri Design. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
*/

/** ensure this file is being included by a parent file */
defined('_JEXEC') or die('Restricted access');

JLoader::import( 'com_tienda.models._base', JPATH_ADMINISTRATOR.DS.'components' );

class TiendaModelCountries extends TiendaModelBase 
{
    protected function _buildQueryWhere(&$query)
    {
       	$filter     = $this->getState('filter');
        $filter_id_from = $this->getState('filter_id_from');
        $filter_id_to   = $this->getState('filter_id_to');
        $filter_name    = $this->getState('filter_name');
        $filter_code2    = $this->getState('filter_code2');
        $filter_code3    = $this->getState('filter_code3');
       	
       	if ($filter) 
       	{
			$key	= $this->_db->Quote('%'.$this->_db->getEscaped( trim( strtolower( $filter ) ) ).'%');
			$where = array();
			$where[] = 'LOWER(tbl.country_id) LIKE '.$key;
			$where[] = 'LOWER(tbl.country_name) LIKE '.$key;
			$where[] = 'LOWER(tbl.country_isocode_2) LIKE '.$key;
			$where[] = 'LOWER(tbl.country_isocode_3) LIKE '.$key;
			$query->where('('.implode(' OR ', $where).')');			
       	}
        if (strlen($filter_id_from))
        {
            if (strlen($filter_id_to))
            {
                $query->where('tbl.country_id >= '.(int) $filter_id_from);
            }
                else
            {
                $query->where('tbl.country_id = '.(int) $filter_id_from);
            }
        }
        if (strlen($filter_id_to))
        {
            $query->where('tbl.country_id <= '.(int) $filter_id_to);
        }
        if ($filter_name) 
        {
            $key    = $this->_db->Quote('%'.$this->_db->getEscaped( trim( strtolower( $filter_name ) ) ).'%');
            $where = array();
            $where[] = 'LOWER(tbl.country_name) LIKE '.$key;
            $query->where('('.implode(' OR ', $where).')');
        }
        if ($filter_code2) 
        {
            $key    = $this->_db->Quote('%'.$this->_db->getEscaped( trim( strtolower( $filter_code2 ) ) ).'%');
            $where = array();
            $where[] = 'LOWER(tbl.country_isocode_2) LIKE '.$key;
            $query->where('('.implode(' OR ', $where).')');
        }
        if ($filter_code3) 
        {
            $key    = $this->_db->Quote('%'.$this->_db->getEscaped( trim( strtolower( $filter_code3 ) ) ).'%');
            $where = array();
            $where[] = 'LOWER(tbl.country_isocode_3) LIKE '.$key;
            $query->where('('.implode(' OR ', $where).')');
        }
    }
        	
	public function getList()
	{
		$list = parent::getList(); 
		foreach(@$list as $item)
		{
			$item->link = 'index.php?option=com_tienda&controller=countries&view=countries&task=edit&id='.$item->country_id;
		}
		return $list;
	}
}
