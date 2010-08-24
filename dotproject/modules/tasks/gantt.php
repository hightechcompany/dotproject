<?php /* TASKS $Id: gantt.php 5837 2008-11-20 23:23:35Z merlinyoda $ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly.');
}

/*
 * Gantt.php - by J. Christopher Pereira
 * MODIFIED BY Pedro Ferreira
 * TASKS $Id: gantt.php 5837 2008-11-20 23:23:35Z merlinyoda $
 */

/*
 *  First clear up the temp dir of previous gantt.png files used to generate hot gantts
 */
foreach (glob((dPgetConfig('overlay_dir') == '' ? DP_BASE_DIR : dPgetConfig('overlay_dir')) . "/files/temp/gantt*") as $filename) {
	if( @filemtime($filename) < (time() - 60 ))
   unlink($filename);
}

function strUTF8Decode($text) {
	global $locale_char_set;
	if (extension_loaded('mbstring')) {
		$encoding = mb_detect_encoding($text.' ');
	}
	if (function_exists('iconv')){
		$text = mb_convert_encoding($text, 'latin1', $encoding);
		//iconv($encoding, 'UTF-8', $text);
	} elseif (function_exists('utf8_decode')) {
		$text = utf8_decode($text);
	}
	// mb functions don't seam to work well here for some reason as the output gets corrupted.
	// iconv is doing the job just fine though
	return $text;
}

global $caller, $locale_char_set;
global $user_id, $dPconfig, $sortByName, $project_id;
global $gantt_map, $currentGanttImgSource, $currentImageMap;
$filedate=date('YmdHis'); // this is used to make all gantt images used in gantt's with links distinct

//$showLabels = dPgetParam($_GET, 'showLabels', 0);
//$showWork = dPgetParam($_GET, 'showWork', 0);
//$sortByName = dPgetParam($_GET, 'sortByName', 0);
$showLabels = dPgetParam($_REQUEST, 'showLabels', 0);
$showWork = dPgetParam($_REQUEST, 'showWork', 0);
$sortByName = dPgetParam($_REQUEST, 'sortByName', 0);
$ganttTaskFilter = dPgetParam($_REQUEST, 'ganttTaskFilter', 0);
$showPinned = dPgetParam( $_REQUEST, 'showPinned', false );
$showArcProjs = dPgetParam( $_REQUEST, 'showArcProjs', false );
$showHoldProjs = dPgetParam( $_REQUEST, 'showHoldProjs', false );
$showDynTasks = dPgetParam( $_REQUEST, 'showDynTasks', false );
$showLowTasks = dPgetParam( $_REQUEST, 'showLowTasks', true);

// Get the state of formatting variables here /////////////////////////////////////////////////////
$showTaskNameOnly = dPgetParam($_REQUEST, 'showTaskNameOnly', 0);
$showNoMilestones = dPgetParam($_REQUEST, 'showNoMilestones', 0);
$showhgrid = dPgetParam($_REQUEST, 'showhgrid', 0);
$addLinksToGantt = dPgetParam($_REQUEST, 'addLinksToGantt', 0);
$printpdf = dPgetParam($_REQUEST, 'printpdf', 0);
$monospacefont = dPgetParam($_REQUEST, 'monospacefont');
// Get the state of formatting variables here /////////////////////////////////////////////////////

ini_set('memory_limit', $dPconfig['reset_memory_limit']);

include ($AppUI->getLibraryClass('jpgraph/src/jpgraph'));
include ($AppUI->getLibraryClass('jpgraph/src/jpgraph_gantt'));
require_once 'tasks.class.php';

$project_id = dPgetParam($_REQUEST, 'project_id', 0);
$f = dPgetParam($_REQUEST, 'f', 0);

// get the prefered date format
$df = $AppUI->getPref('SHDATEFORMAT');

require_once $AppUI->getModuleClass('projects');
$project = new CProject;
if ($project_id > 0) {
	$criticalTasks = $project->getCriticalTasks($project_id);
	$project->load($project_id);
}

// pull valid projects and their percent complete information

$q = new DBQuery;
$q->addTable('projects');
$q->addQuery('project_id, project_color_identifier, project_name'
             . ', project_start_date, project_end_date');
$q->addJoin('tasks', 't1', 'projects.project_id = t1.task_project');
$q->addWhere('project_status != 7');
$q->addGroup('project_id');
$q->addOrder('project_name');
$project->setAllowedSQL($AppUI->user_id, $q);
$projects = $q->loadHashList('project_id');
$q->clear();

$caller = defVal(@$_REQUEST['caller'], null);

/**
 * if task filtering has been requested create the list of task_ids
 * which will be used to filter the query
 */
if ($ganttTaskFilter > 0) {
$task_child_search = new CTask();
$task_child_search->peek($ganttTaskFilter);
//$childrenlist[] = $ganttTaskFilter;
//print_r($childrenlist);
$childrenlist = $task_child_search->getDeepChildren();
//print_r($childrenlist);

$where .= ' t.task_id IN (' . $ganttTaskFilter . ', ' . implode(', ', $childrenlist) . ')';
}
//else {
//	$where = '';
//}
//echo '<pre> $where = ' . $where . '</pre>';
//die;

// gantt is called now by the todo page, too. There is a different filter approach in todo
// so we have to tweak a little bit, also we do not have a special project available

if ($caller == 'todo') {
	$user_id = defVal( @$_REQUEST['user_id'], 0 );

	$projects[$project_id]['project_name'] = ($AppUI->_('Todo for') . ' '
	                                          . dPgetUsernameFromID($user_id));
	$projects[$project_id]['project_color_identifier'] = 'ff6000';


	$q->addTable('tasks', 't');
	$q->innerJoin('projects', 'p', 'p.project_id = t.task_project');
	$q->innerJoin('user_tasks', 'ut', 'ut.task_id = t.task_id AND ut.user_id = ' . $user_id);
	$q->leftJoin('user_task_pin', 'tp', 'tp.task_id = t.task_id AND tp.user_id = ' . $user_id);

	$q->addQuery('t.*, p.project_name, p.project_id, p.project_color_identifier, tp.task_pinned');
	$q->addWhere('(t.task_percent_complete < 100 OR t.task_percent_complete IS NULL)');
	$q->addWhere('t.task_status = 0');
	if (!$showArcProjs) {
		$q->addWhere('project_status <> 7');
	}
	if (!$showLowTasks) {
		$q->addWhere('task_priority >= 0');
	}
	if (!$showHoldProjs) {
		$q->addWhere('project_status != 4');
	}
	if (!$showDynTasks) {
		$q->addWhere('task_dynamic != 1');
	}
	if ($showPinned) {
		$q->addWhere('task_pinned = 1');
	}

	if ($ganttTaskFilter) {
		$q->addWhere($where);
	}

 	$q->addGroup('t.task_id');
	$q->addOrder((($sortByName) ? 't.task_name, ' : 't.task_parent ASC, ') . 't.task_end_date, t.task_priority DESC');
//	$q->addOrder('t.task_dynamic ASC');
//	$q->addOrder((($sortByName) ? 't.task_name, ' : '') . 't.task_end_date, t.task_priority DESC');
//		echo '<pre> query  for todo= '; print_r($q); echo '</pre>';
} else {
	// pull tasks
	$q->addTable('tasks', 't');
	$q->addJoin('projects', 'p', 'p.project_id = t.task_project');

	$q->addQuery('t.task_id, task_parent, task_name, task_start_date, task_end_date'
	             . ', task_duration, task_duration_type, task_priority, task_percent_complete'
	             . ', task_order, task_project, task_milestone, project_name, task_dynamic');

	// don't add milestones if box is checked//////////////////////////////////////////////////////////
	if ($showNoMilestones == '1'){
		$q->addWhere('task_milestone != 1');
	}
	$q->addWhere('project_status != 7');
	if ($project_id) {
		$q->addWhere('task_project = ' . $project_id);
	}
	if ($f != 'myinact') {
		$q->addWhere('task_status > -1');
	}

	if ($ganttTaskFilter) {
		$q->addWhere($where);
	}
	switch ($f) {
		case 'all':
			break;
		case 'myproj':
			$q->addWhere('project_owner = ' . $AppUI->user_id);
			break;
		case 'mycomp':
			$q->addWhere('project_company = ' . $AppUI->user_company);
			break;
		case 'myinact':
			$q->innerJoin('user_tasks', 'ut', 'ut.task_id = t.task_id');
			$q->addWhere('ut.user_id = '.$AppUI->user_id);
			break;
		default:
			$q->innerJoin('user_tasks', 'ut', 'ut.task_id = t.task_id');
			$q->addWhere('ut.user_id = '.$AppUI->user_id);
			break;
	}

	$q->addOrder('p.project_id, ' . (($sortByName) ? 't.task_name, ' : '') . 't.task_start_date');
//		echo '<pre> query = '; print_r($q); echo '</pre>';
}
// get any specifically denied tasks
$task = new CTask;
$task->setAllowedSQL($AppUI->user_id, $q);
$proTasks = $q->loadHashList('task_id');
$q->clear();


$orrarr[] = array('task_id'=>0, 'order_up'=>0, 'order'=>'');
$end_max = '0000-00-00 00:00:00';
$start_min = date('Y-m-d H:i:s');

//pull the tasks into an array
$criticalTasks = $project->getCriticalTasks($project_id);
$actual_end_date = new CDate($criticalTasks[0]['task_end_date']);
$p_end_date = (($actual_end_date->after($project->project_end_date))
               ? $criticalTasks[0]['task_end_date'] : $project->project_end_date);

foreach ($proTasks as $row) {
	// calculate or set blank task_end_date if unset
	if ($row['task_end_date'] == '0000-00-00 00:00:00') {
		if ($row['task_duration'] && $row['task_start_date'] != '0000-00-00 00:00:00') {
			$start_date_unix_time = (db_dateTime2unix($row['task_start_date']) + SECONDS_PER_DAY
									 * convert2days($row['task_duration'],
													$row['task_duration_type']));
			$row['task_end_date'] = substr(db_unix2dateTime($start_date_unix_time), 1, -1);
		} else {
			$row['task_end_date'] = $p_end_date;
		}
	}

	if ($row['task_start_date'] == '0000-00-00 00:00:00') {
		$row['task_start_date'] = $project->project_start_date; //date('Y-m-d H:i:s');
	}

	if ($row['task_start_date'] == '0000-00-00 00:00:00') {
		$row['task_start_date'] = date(mktime (0,0,0,1,1,date('Y')), 'Y-m-d H:i:s');
	}

	$tsd = new CDate($row['task_start_date']);
	if ($tsd->before(new CDate($start_min))) {
		$start_min = $row['task_start_date'];
	}

	$ted = new CDate($row['task_end_date']);
	if ($ted->after(new CDate($end_max))) {
		$end_max = $row['task_end_date'];
	}
	if ($ted->after(new CDate($projects[$row['task_project']]['project_end_date']))
	    || $projects[$row['task_project']]['project_end_date'] == '') {
		$projects[$row['task_project']]['project_end_date'] = $row['task_end_date'];
	}

	$projects[$row['task_project']]['tasks'][] = $row;
}
unset($proTasks);

//consider critical (concerning end date) tasks as well
if ($caller != 'todo') {
	$start_min = $projects[$project_id]['project_start_date'];
	$end_max = (($projects[$project_id]['project_end_date'] > $criticalTasks[0]['task_end_date'])
	            ? $projects[$project_id]['project_end_date'] : $criticalTasks[0]['task_end_date']);
}
$start_date = dPgetParam($_GET, 'start_date', $start_min);
$end_date = dPgetParam($_GET, 'end_date', $end_max);

$count = 0;
$width = min(dPgetParam($_GET, 'width', 600), 1400);
// If hyperlinks are to be added then the graph is of a set width///////
if ($addLinksToGantt == '1') {
	$width = 950 ;
}

$graph = new GanttGraph($width);
$graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH | GANTT_HDAY | GANTT_HWEEK);
//$graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH | GANTT_HDAY);

$graph->SetFrame(false);
$graph->SetBox(true, array(0,0,0), 2);
$graph->scale->week->SetStyle(WEEKSTYLE_FIRSTDAY);
//$graph->scale->day->SetStyle(DAYSTYLE_SHORTDATE2);

//Check whether to show horizontal grid or not ////////////////////////////////////////////////////
if ($showhgrid == '1') {
	$graph->hgrid->Show ();
	$graph->hgrid->SetRowFillColor ('darkblue@0.95');
}

$pLocale = setlocale(LC_TIME, 0); // get current locale for LC_TIME
//$res = @setlocale(LC_TIME, $AppUI->user_lang[2]);
$res = @setlocale(LC_TIME, $AppUI->user_lang[0]);
if ($res) { // Setting locale doesn't fail
	$graph->scale->SetDateLocale($AppUI->user_lang[2]);
//	$graph->scale->SetDateLocale($AppUI->user_lang[0]);
}
setlocale(LC_TIME, $pLocale);

if ($start_date && $end_date) {
	$graph->SetDateRange($start_date, $end_date);
}
//if ($monospacefont) {
//	$graph->scale->actinfo->SetFont(FF_DEJAVUMONO, FS_NORMAL, 8); //specify the use of VeraMono
//} else {
	$graph->scale->actinfo->SetFont(FF_DEJAVU, FS_NORMAL, 8);
//}

//$graph->scale-> SetTableTitleBackground( 'white');
//$graph->scale-> tableTitle-> Show(true);
$graph->scale->actinfo->vgrid->SetColor('gray');
$graph->scale->actinfo->SetColor('darkgray');
// Show Task names only filtering /////////////////////////////////////////////////////////////////
if ($showTaskNameOnly == '1'){
	if ($caller == 'todo') {
		$graph->scale->actinfo->SetColTitles(array($AppUI->_('Task name', UI_OUTPUT_RAW)), array(300));
	} else {
		$graph->scale->actinfo->SetColTitles(array($AppUI->_('Task name', UI_OUTPUT_RAW)), array(300));
	}
} else {
	if ($caller == 'todo') {
		$graph->scale->actinfo->SetColTitles(array(	$AppUI->_('Task name', UI_OUTPUT_RAW),
													$AppUI->_('Project name', UI_OUTPUT_RAW),
													(($showWork == '1') ? $AppUI->_('Work', UI_OUTPUT_RAW) : $AppUI->_('Dur.', UI_OUTPUT_RAW)),
													$AppUI->_('Start', UI_OUTPUT_RAW),
													$AppUI->_('Finish', UI_OUTPUT_RAW)),
											array(180, 50, 60, 60, 60));
	} else {
		$graph->scale->actinfo->SetColTitles(array(	$AppUI->_('Task name', UI_OUTPUT_RAW),
													(($showWork == '1') ? $AppUI->_('Work', UI_OUTPUT_RAW) : $AppUI->_('Dur.', UI_OUTPUT_RAW)),
													$AppUI->_('Start', UI_OUTPUT_RAW),
													$AppUI->_('Finish', UI_OUTPUT_RAW)),
											array(230, 60, 60, 60));
	}
}
//$graph->scale->tableTitle->Set($projects[$project_id]['project_name']);

// Use TTF font if it exists
// try commenting out the following two lines if gantt charts do not display
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// when showing task names only, show project name as title of the Gantt chart ////////////////////
if ($showTaskNameOnly == '1') {
//	if ($monospacefont) {
//		$graph->title->SetFont(FF_DEJAVUMONO, FS_BOLD, 12); //specify the use of VeraMono
//	} else {
		$graph->title->SetFont(FF_DEJAVU, FS_BOLD, 12);
//	}
} else {
//	if ($monospacefont) {
//		$graph->scale->tableTitle->SetFont(FF_DEJAVUMONO, FS_BOLD, 12); //specify the use of VeraMono
//	} else {
		$graph->scale->tableTitle->SetFont(FF_DEJAVU, FS_BOLD, 12);
//	}
	$graph->scale->SetTableTitleBackground('#'.$projects[$project_id]['project_color_identifier']);
	$graph->scale->tableTitle->Show(true);
}

// fix for issue 2513813 reported in dotmods patch section
// utf8_decode title in the same way as task names.
/*
	if (function_exists('utf8_decode')) {
		$gtitle = utf8_decode($projects[$project_id]['project_name']);
	} else {
		$gtitle = $projects[$project_id]['project_name'];
	}
*/
	$gtitle = strUTF8Decode($projects[$project_id]['project_name']);
	$graph->title->Set($gtitle);
//-----------------------------------------
// nice Gantt image
// if diff(end_date,start_date) > 90 days it shows only
//week number
// if diff(end_date,start_date) > 240 days it shows only
//month number
//-----------------------------------------
if ($start_date && $end_date){
	$min_d_start = new CDate($start_date);
	$max_d_end = new CDate($end_date);
	$graph->SetDateRange($start_date, $end_date);
} else {
	// find out DateRange from gant_arr
	$d_start = new CDate();
	$d_end = new CDate();
	for($i = 0; $i < count(@$gantt_arr); $i++){
		$a = $gantt_arr[$i][0];
		$start = substr($a['task_start_date'], 0, 10);
		$end = substr($a['task_end_date'], 0, 10);

		$d_start->Date($start);
		$d_end->Date($end);

		if ($i == 0){
			$min_d_start = $d_start;
			$max_d_end = $d_end;
		} else {
			if (CDate::compare($min_d_start,$d_start) > 0) {
				$min_d_start = $d_start;
			}
			if (CDate::compare($max_d_end,$d_end) < 0){
				$max_d_end = $d_end;
			}
		}
	}
}

// check day_diff and modify Headers
$day_diff = $max_d_end->dateDiff($min_d_start);
// new scale display for gantt ////////////////////////////////////////////////////////////////////
if ($day_diff > 1096) {
	//more than 3 years, show only the year scale
	$graph->ShowHeaders(GANTT_HYEAR);
	$graph->scale->year->grid->Show ();
	$graph->scale->year->grid->SetStyle (longdashed);
	$graph->scale->year->grid->SetColor ('lightgray');
} else if ($day_diff > 480) {
	//more than 480 days show only the firstletter of the month
	$graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH);
	$graph->scale->month->SetStyle(MONTHSTYLE_FIRSTLETTER);
	$graph->scale->month->grid->Show ();
	$graph->scale->month->grid->SetStyle (longdashed);
	$graph->scale->month->grid->SetColor ('lightgray');
} else if($day_diff > 240) {
	//more than 240 days and less than 481 show the month short name eg: Jan
	$graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH);
	$graph->scale->month->SetStyle(MONTHSTYLE_SHORTNAME);
	$graph->scale->month->grid->Show ();
	$graph->scale->month->grid->SetStyle (longdashed);
	$graph->scale->month->grid->SetColor ('lightgray');
} else if ($day_diff > 90) {
	//more than 90 days and less of 241
	$graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH | GANTT_HWEEK);
	$graph->scale->week->SetStyle(WEEKSTYLE_WNBR);
}


$parents = array();
//This kludgy function echos children tasks as threads

function showgtask(&$a, $level=0) {
	/* Add tasks to gantt chart */
	global $gantt_arr, $parents;
	$gantt_arr[] = array($a, $level);
	$parents[$a['task_parent']] = true;
}

function findgchild(&$tarr, $parent, $level=0) {
//	GLOBAL $projects;
	$level = $level + 1;
	$n = count($tarr);
	for ($x=0; $x < $n; $x++) {
		if ($tarr[$x]['task_parent'] == $parent
		    && $tarr[$x]['task_parent'] != $tarr[$x]['task_id']){
			showgtask($tarr[$x], $level);
			findgchild($tarr, $tarr[$x]['task_id'], $level);
		}
	}
}


reset($projects);
//$p = &$projects[$project_id];
foreach ($projects as $p) {
	global $parents;
	$parents = array();
	$tnums = 0;
	if (isset($p['tasks'])) {
		$tnums = count($p['tasks']);
	}
	$task_id = $t['task_id'];

	for ($i=0; $i < $tnums; $i++) {
		$t = $p['tasks'][$i];
		if (!(isset($parents[$t['task_parent']]))) {
			$parents[$t['task_parent']] = false;
		}
		if ($t['task_parent'] == $t['task_id']) {
			showgtask($t);
			findgchild($p['tasks'], $t['task_id']);
		}
	}
	// Check for ophans.

//***************************** added thes bit to remove the bug where tasks would repeat when show dyn tasks + not using dort by name
//	array_multisort($parents, SORT_NUMERIC, SORT_ASC);
//***********************
	foreach ($parents as $id => $ok) {
		if (!($ok)) {
			findgchild($p['tasks'], $id);
		}
	}
}

$hide_task_groups = false;

// removed hide groups function


$row = 0;
for($i = 0; $i < count(@$gantt_arr); $i ++) {
	$a = $gantt_arr[$i][0];
	$level = $gantt_arr[$i][1];
	if ($hide_task_groups) {
		$level = 0;
	}
	$name = $a['task_name'];
//	if ($locale_char_set=='utf-8' && function_exists('utf8_decode')) {
//		$name = utf8_decode($name);
//	}
// check again for show task names only and make the task name string longer //////////////////////
	$name = (str_repeat('  ', $level) . $name);

	if ($showTaskNameOnly == '1') {
			$name = ((strlen($name) > 60) ? (substr($name, 0, 57) . '...') : $name);
		} else {
			$name = ((strlen($name) > 37) ? (substr($name, 0, 34) . '...') : $name);
		}
	// do UTF8 decoding in an alternative way to make sure that characters are displayed properly
	// not sure about the performace implecations using this type of character decoding.
	if (function_exists('iconv') && function_exists('mb_detect_encoding')) {
		$name = iconv(mb_detect_encoding($name." "), 'UTF-8', $name);
	}
	if ($caller == 'todo') {
/*		$pname = $a['project_name'];
		if (function_exists('iconv') && function_exists('mb_detect_encoding')) {
			$pname = iconv(mb_detect_encoding($pname." "), 'UTF-8', $pname);
		}
*/
		$pname = strUTF8Decode($a['project_name']);
		$pname = ((strlen($pname) > 14) ? (substr($pname, 0, 5) . '...' . substr($pname, -5, 5)) : $pname);

//		if ($locale_char_set=='utf-8') {
//			if (function_exists('mb_substr')) {
//				$pname = ((mb_strlen($pname) > 14
//				          ? (mb_substr($pname, 0, 5) . '...' . mb_substr($pname, -5, 5)) : $pname));
//			}  else if (function_exists('utf8_decode')) {
//				$pname = utf8_decode($pname);
//			}
//		} else {
//			$pname = ((strlen($pname) > 14)
//			          ? (substr($pname, 0, 5) . '...' . substr($pname, -5, 5)) : $pname);
//		}
	}
	//using new jpGraph determines using Date object instead of string
	$start_date = new CDate($a['task_start_date']);
	$end_date = new CDate($a['task_end_date']);

	$start = $start_date->getDate();
	$end = $end_date->getDate();

	$progress = $a['task_percent_complete'] + 0;

	if ($progress > 100) {
		$progress = 100;
	} else if ($progress < 0) {
		$progress = 0;
	}

	$flags	= (($a['task_milestone']) ? 'm' : '');

	$cap = '';
	if (!$start || $start == '0000-00-00'){
		$start = ((!$end) ? date('Y-m-d') : $end);
		$cap .= '(no start date)';
	}
	if (!$end) {
		$end = $start;
		$cap .= ' (no end date)';
	}

	$caption = '';
	if ($showLabels == '1') {
		$q->addTable('user_tasks', 'ut');
		$q->innerJoin('users', 'u', 'u.user_id = ut.user_id');
		$q->addQuery('ut.task_id, u.user_username, ut.perc_assignment');
		$q->addWhere('ut.task_id = ' . $a['task_id']);
		$res = $q->loadList();
		foreach ($res as $rw) {
			switch ($rw['perc_assignment']) {
				case 100:
					$caption .= ($rw['user_username'] . ';');
					break;
				default:
					$caption .= ($rw['user_username'] . '[' . $rw['perc_assignment'] . '%];');
					break;
			}
		}
		$q->clear();
		$caption = substr($caption, 0, (strlen($caption) - 1));
/*		if (function_exists('iconv') && function_exists('mb_detect_encoding')) {
			$caption = iconv(mb_detect_encoding($caption." "), 'UTF-8', $caption);
		}
*/
		$caption = strUTF8Decode($caption);

//		if (function_exists('utf8_decode')) {
//			$caption = utf8_decode($caption);
//		}

	}

	if ($flags == 'm') {
	// if hide milestones is ticked this bit is not processed//////////////////////////////////////////
		if ($showNoMilestones != '1') {
			$start_date_mile = new CDate($start_date);
			$start_date_mile->setTime(0);
			$start_mile = $start_date_mile->getDate();
			$s = $start_date->format($df);
			$today_date = date('m/d/Y');
			$today_date_stamp = strtotime($today_date);
			$mile_date = $start_date->format("%m/%d/%Y");
			$mile_date_stamp = strtotime($mile_date);
	// honour the choice to show task names only///////////////////////////////////////////////////
			if ($showTaskNameOnly == '1') {
				if ($caller == 'todo'){
					$milestone_label_array = array($name);
				} else {
						$milestone_label_array = array($name);
				}
				$bar = new MileStone($row++, $milestone_label_array, $start_mile, $s);
				if ($monospacefont) {
					$bar->title->SetFont(FF_DEJAVUMONO, FS_NORMAL, 10); //specify the use of VeraMono
				} else {
					$bar->title->SetFont(FF_DEJAVU, FS_NORMAL, 10);
				}
			} else {
				if ($caller == 'todo') {
					$milestone_label_array = array($name, $pname, '', $s, $s);
				} else {
					$milestone_label_array = array($name, '', $s, $s);
				}
				$bar = new MileStone($row++, $milestone_label_array, $start_mile, $s);
				if ($monospacefont) {
					$bar->title->SetFont(FF_DEJAVUMONO, FS_NORMAL, 8); //specify the use of VeraMono
				} else {
					$bar->title->SetFont(FF_DEJAVU, FS_NORMAL, 8);
				}
			}
			//caption of milestone should be date
			if ($showLabels == '1') {
				//$caption = $start->format($df);
				$caption .= ( $caption != "" ? "\n" : "" ) . $start_date->format($df);
			}
			///////////////////////////////////////////////////////////////////////////////////////
			//set color for milestone according to progress
			//red for 'not started' #990000
			//yellow for 'in progress' #FF9900
			//green for 'achieved' #006600
			// blue for 'planned' #0000FF
			$bar->mark->SetType(MARK_DIAMOND);
			$bar->mark->SetWidth(10);
			if ($a['task_percent_complete'] == 100)  {
				$bar->title->SetColor('#006600');
				$bar->mark->SetColor('#006600');
				$bar->mark->SetFillColor('#006600');
			} else {
				if (strtotime($mile_date) < strtotime($today_date)) {
					$bar->title->SetColor('#990000');
					$bar->mark->SetColor('#990000');
					$bar->mark->SetFillColor('#990000');
				} else{
					if ($a['task_percent_complete'] == 0)  {
						$bar->title->SetColor('#0000FF');
						$bar->mark->SetColor('#0000FF');
						$bar->mark->SetFillColor('#0000FF');
					} else {
						$bar->title->SetColor('#FF9900');
						$bar->mark->SetColor('#FF9900');
						$bar->mark->SetFillColor('#FF9900');
					}
				}
			}
		}	//this closes the code that is not processed if hide milestones is checked ///////////////
	} else {
		$type = $a['task_duration_type'];
		$dur = $a['task_duration'];
		if ($type == 24) {
			$dur *= $dPconfig['daily_working_hours'];
		}
		if ($showWork=='1') {
			$work_hours = 0;
			$q->addTable('tasks', 't');
			$q->addJoin('user_tasks', 'u', 't.task_id = u.task_id');
			$q->addQuery('ROUND(SUM(t.task_duration*u.perc_assignment/100),2) AS wh');
			$q->addWhere('t.task_duration_type = 24');
			$q->addWhere('t.task_id = '.$a['task_id']);

			$wh = $q->loadResult();
			$work_hours = $wh * $dPconfig['daily_working_hours'];
			$q->clear();

			$q->addTable('tasks', 't');
			$q->addJoin('user_tasks', 'u', 't.task_id = u.task_id');
			$q->addQuery('ROUND(SUM(t.task_duration*u.perc_assignment/100),2) AS wh');
			$q->addWhere('t.task_duration_type = 1');
			$q->addWhere('t.task_id = '.$a['task_id']);

			$wh2 = $q->loadResult();
			$work_hours += $wh2;
			$q->clear();
			//due to the round above, we don't want to print decimals unless they really exist
			$dur = $work_hours;
		}

		$dur .= ' h';
		$enddate = new CDate($end);
		$startdate = new CDate($start);
		// here will check again if showNameOnly is checked and only add task name to the array ///
		if ($showTaskNameOnly == '1') {
			if ($caller == 'todo') {
				$bar_label_array = array($name);
			} else {
				$bar_label_array = array($name);
			}
			$bar = new GanttBar($row++, $bar_label_array, $start, $end, $cap, ($a['task_dynamic'] == 1 ? 0.1 : 0.6));
			$bar->progress->Set(min(($progress/100),1));
			// make the font a little bigger if showing task names only
			if ($monospacefont) {
				$bar->title->SetFont(FF_DEJAVUMONO, FS_NORMAL, 10); //specify the use of VeraMono
			} else {
				$bar->title->SetFont(FF_DEJAVU, FS_NORMAL, 10);
			}
		} else {
			if ($caller == 'todo') {
				$bar_label_array = array($name, $pname, $dur, $startdate->format($df), $enddate->format($df));
			} else {
				$bar_label_array = array($name, $dur, $startdate->format($df), $enddate->format($df));
			}
			$bar = new GanttBar($row++, $bar_label_array, $start, $end, $cap, ($a['task_dynamic'] == 1 ? 0.1 : 0.6));
			$bar->progress->Set(min(($progress/100),1));
			if ($monospacefont) {
				$bar->title->SetFont(FF_DEJAVUMONO, FS_NORMAL, 8); //specify the use of VeraMono
			} else {
				$bar->title->SetFont(FF_DEJAVU, FS_NORMAL, 8);
			}
		}
		// make the font a little bigger if showing task names only////////////////////////////////
		if ($a['task_dynamic'] == 1){
			if ($showTaskNameOnly == '1'){
				if ($monospacefont) {
					$bar->title->SetFont(FF_DEJAVUMONO,FS_BOLD, 10); //specify the use of VeraMono
				} else {
					$bar->title->SetFont(FF_DEJAVU,FS_BOLD, 10);
				}
			} else {
				if ($monospacefont) {
					$bar->title->SetFont(FF_DEJAVUMONO,FS_BOLD, 8); //specify the use of VeraMono
				} else {
					$bar->title->SetFont(FF_DEJAVU,FS_BOLD, 8);
				}
			}
			$bar->rightMark->Show();
			$bar->rightMark->SetType(MARK_RIGHTTRIANGLE);
			$bar->rightMark->SetWidth(3);
			$bar->rightMark->SetColor('black');
			$bar->rightMark->SetFillColor('black');

			$bar->leftMark->Show();
			$bar->leftMark->SetType(MARK_LEFTTRIANGLE);
			$bar->leftMark->SetWidth(3);
			$bar->leftMark->SetColor('black');
			$bar->leftMark->SetFillColor('black');

			$bar->SetPattern(BAND_SOLID,'black');
		}
	}
	//add hyperlinks to the tasks and bars
	if ($addLinksToGantt == 1){
		$bar->title->SetCSIMTarget ('./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" target="_blank"', 'Click here to see details');
		$bar->SetCSIMTarget ('./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" target="_blank"' , 'Click here to see details');
	}
	//adding captions
	$bar->caption = new TextProperty($caption);
	$bar->caption->Align('left','center');
	if ($monospacefont) {
		$bar->caption->SetFont(FF_DEJAVUMONO, FS_NORMAL, 8); //specify the use of VeraMono
	} else {
		$bar->caption->SetFont(FF_DEJAVU, FS_NORMAL, 8);
	}

	// show tasks which are both finished and past in (dark)gray
	if ($progress >= 100 && $end_date->isPast() && get_class($bar) == 'ganttbar') {
		$bar->caption->SetColor('gray');
		$bar->title->SetColor('gray');
		$bar->setColor('gray');
		$bar->SetFillColor('gray');
		$bar->SetPattern(BAND_SOLID,'gray');
		$bar->progress->SetFillColor('gray');
		$bar->progress->SetPattern(BAND_SOLID,'gray',98);
	}

	$q->addTable('task_dependencies');
	$q->addQuery('dependencies_task_id');
	$q->addWhere('dependencies_req_task_id=' . $a['task_id']);
	$query = $q->loadList();

	foreach ($query as $dep) {
		// find row num of dependencies
		for ($d = 0; $d < count($gantt_arr); $d++) {
			if ($gantt_arr[$d][0]['task_id'] == $dep['dependencies_task_id']) {
				$bar->SetConstrain($d, CONSTRAIN_ENDSTART);
			}
		}
	}
	unset($query);
	$q->clear();
	$graph->Add($bar);
}
unset($gantt_arr);
$today = date('y-m-d');
$vline = new GanttVLine($today, $AppUI->_('Today', UI_OUTPUT_RAW));
//if ($monospacefont) {
//	$vline->title->SetFont(FF_DEJAVUMONO, FS_BOLD, 10); //specify the use of VeraMono
//} else {
	$vline->title->SetFont(FF_DEJAVU, FS_BOLD, 10);
//}
$graph->Add($vline);

if ($addLinksToGantt == 1){
	$graph->SetMargin(5, 5, 15, 20);
	$graph->Stroke( (dPgetConfig('overlay_dir') == '' ? DP_BASE_DIR : dPgetConfig('overlay_dir')) . "/files/temp/gantt" . $filedate . ".png" );
	$ganttMap = $graph ->GetHTMLImageMap ("csimap" );
	$ganttSource = "<img src=\"./files/temp/gantt". $filedate . ".png\" ISMAP USEMAP=\"#csimap\" border=0>";
	$ganttMap = dPgetParam($_POST, 'ganttMap');
	$ganttSource = dPgetParam($_POST, 'ganttSource');
	echo $graph ->GetHTMLImageMap ("csimap" );
	echo  "<img src=\"./files/temp/gantt" . $filedate . ".png\" ISMAP USEMAP=\"#csimap\" border=0>" ;
/*
	$gantt_file = '/files/temp/gantt2.png';
//	$bar = new MileStone($row++, $gantt_file, $today, $s);
	$graph->StrokeCSIM(basename(__FILE__));
	//$graph->StrokeCSIM(auto);
	//$graph->StrokeCSIM('gant.php', 'gantt_map', 0);
	//$gantt_map = $graph->GetHTMLImageMap ('gantt_map');
	//echo "<img src=\"./files/temp/gantt.png\" ISMAP USEMAP=\"#gantt_map\" border=0>";
		// Map Names
	//$gantt_map = $graph->GetHTMLImageMap("gantt_map");
		// HTML <img src="..."
	//$currentGanttImgSource = "<center><img src=\"" . $gantt_file . "\" ISMAP USEMAP=\"#" . $gantt_map . "\" border=0><br /> \n";
/*
<html>
 <head>
    <title> Graph with Image Map </title>
    <br />
		<div style='text-align: justify'>
            <div class='graphContent'> <!-- content DIV -->
				<?php
                   echo $currentGanttImgSource;   // Graph Image
                   echo $currentImageMap; 		   // Image Map Coordinates
	            ?>
				<br /> <br />
            </div> <!-- END content DIV -->
        </div> <!-- END style='text-align: justify' DIV -->
      </body>
</html>

*/
} else {

	$graph->Stroke(); //normal in-line output
}
?>
