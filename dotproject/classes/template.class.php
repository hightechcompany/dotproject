<?php
require_once($baseDir . '/lib/smarty/Smarty.class.php');

class CTemplate extends Smarty
{
//	var $plugins_dir = array('/var/www/html/dotproject/includes/smarty');
	var $page;

	function CTemplate()
	{
		global $AppUI, $baseDir, $m, $a, $dPconfig;
	
		parent::Smarty();
		// $this->template_dir = $baseDir . '/style/' . $AppUI->getPref('template');
		$this->template_dir = $baseDir . '/style/smarty1';
		$this->compile_dir	= $baseDir . '/files/cache/smarty_templates';
		$this->cache_dir		= $baseDir . '/files/cache/smarty';
		$this->plugins_dir[]= $baseDir . '/includes/smarty';
	}
	
	function init()
	{
		$this->assign('template', $this->template_dir);
		$this->assign('config', $dPconfig);
		$this->assign('m', $m);
		$this->assign('a', $a);

		$this->page = isset($_REQUEST['page'])?$_REQUEST['page']:1;
		$this->assign('page', $this->page);
	}
	
	function displayList($module, $rows, $totalRows = 0, $show = null)
	{
		if (!isset($show))
		{
			$keys = array_keys($rows);
			$show = array_keys($rows); //[$keys[0]]);
		}
		
		if (!$this->get_template_vars('current_url'))
			$this->assign('current_url', '?m=' . $module);			
			
		$this->assign('rows', $rows);
		$this->assign('show', $show);
		
		$this->displayPagination($this->page, $totalRows > 0?$totalRows:count($rows), $module);
		$this->displayFile('list', $module);
		$this->displayPagination($this->page, $totalRows > 0?$totalRows:count($rows), $module);
	}
	
	function displayView($item)
	{
		global $m;
		
		$this->assign('obj', $item);
		
		$this->displayFile('view');
	}
	
	function displayAddEdit($item)
	{
		global $m;
		
		$this->assign('obj', $item);
		
		$this->displayFile('addedit');
	}
	
	function displayPagination($currentPage, $totalRecords, $module = null)
	{
		$pagination['url'] = 'index.php?' . ereg_replace('&page=.+', '', $_SERVER['QUERY_STRING']);
		// The current page
		$pagination['page'] = $currentPage;
		// how many items in total there are in the list
		$pagination['total_records'] = $totalRecords;
		// how many records there will be per page
		$pagination['page_size'] = dPgetConfig('page_size');
		// how many direct page links to display in the pagination bar
		$pagination['pages_size'] = 30;
		// how many pages there are in total
		$pagination['total_pages'] = ceil($pagination['total_records'] / $pagination['page_size']);
		
		$start_page = ($pagination['page'] >= ($pagination['pages_size'] / 2))?$pagination['page'] : 1;
		$end_page = ($pagination['total_pages'] <= $pagination['page'] + $pagination['pages_size'] / 2)?$pagination['total_pages']:($pagination['page'] + ($pagination['pages_size'] / 2));
		if ($start_page >= $end_page) // no pagination necessary - only one page!
			return;
		// an array with the pages numbers to be displayed
		$pagination['pages'] = range($start_page, $end_page);

		$this->assign('pagination', $pagination);
		$this->display('pagination.html', $module);
	}
	
	function displayFile($file, $module = null)
	{
		global $m;
		
		if ($module == null)
			$module = $m;
			
		$this->display($module . '/' . $file . '.html');
	}
	
	function fetchFile($file, $module = null)
	{
		global $m;
		
		if ($module == null)
			$module = $m;
			
		return $this->fetch($module . '/' . $file . '.html');
	}
	
	function displayStyle($file)
	{
		global $baseDir, $dPconfig, $AppUI;
		global $file_id, $company_id, $task_id;
		global $currentTabId, $currentTabName;
		global $uistyle, $style_extras;
				
		include($baseDir . '/style/' . $uistyle . '/' . $file . '.php');
	}
}
?>