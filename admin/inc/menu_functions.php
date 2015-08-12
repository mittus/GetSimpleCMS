<?php if(!defined('IN_GS')){ die('you cannot load this page directly.'); }

/**
 * GetSimple Menu and Hierarchy Functions
 * @package GetSimple
 * @subpackage menus_functions.php
 */

define('GSMENUNESTINDEX','nested');
define('GSMENUFLATINDEX','flat');
define('GSMENUINDEXINDEX','indices');

define('GSMENUFILTERSKIP',1);     // skip all children
define('GSMENUFILTERCONTINUE',2); // skip parent, continue with children inheriting closest parent
define('GSMENUFILTERSHIFT',3);    // skip parent, shift all children to root

define('GSMENUPAGESMENUID','corepages'); // default id for the page menu cache

define('GSMENULEGACY',true);
// define('GSMENUFILTERDEBUG',true);


/**
 * initialize upgrade, menu imports
 * @since  3.4
 * @return bool status of menu generation upgrade
 */
function initUpgradeMenus(){
    $menu   = importLegacyMenuFlat();
    $status = menuSave('legacy',$menu);
    debugLog(__FUNCTION__ . ": legacy menu save status " . ($status ? 'success' : 'fail'));

    $menu   = importLegacyMenuTree();
    $status &= menuSave(GSMENUPAGESMENUID,$menu);
    debugLog(__FUNCTION__ . ": default menu save status " . ($status ? 'success' : 'fail'));

    return $status;
}

/**
 * imports menu from pages flat legacy style, menuOrder sorted only
 * @since  3.4
 * @return array new menu sorted by menuOrder
 */
function importLegacyMenuFlat(){
    $pages = getPagesSortedByMenu();
    $pages = filterKeyValueMatch($pages,'menuStatus','Y');
    $menu  = importMenuFromPages($pages,true);
    return $menu; 
}

/**
 * imports menu from pages nested tree style, parent, title, menuorder sorted
 * @since  3.4
 * @return array new menu nested tree sorted by hierarchy and menuOrder
 */
function importLegacyMenuTree(){
    $pages = getPagesSortedByMenuTitle();
    // $pages = filterKeyValueMatch($pages,'menuStatus','Y');
    // @todo when menu filtered, does not retain pages with broken paths, problem for menus that should probably still show them
    $menu  = importMenuFromPages($pages);
    return $menu;
}

/**
 * build a nested menu array from pages array parent/menuorder
 * ( optionally filter by menustatus )
 * create a parent hash table with references
 * builds nested array tree
 * recurses over tree and add depth, order, numchildren, (path and url)
 * @since  3.4
 * @param  array $pages pages collection to convert to menu
 * @param  bool  $flatten if true return menu as flat not nested ( legacy style )
 */
function importMenuFromPages($pages = null, $flatten = false){
    
    // if importing from 3.3.x do not generate tree, generate flat menu instead for legacy menuordering by menuOrder
    if($flatten) $parents = array(''=>$pages);
    else $parents = getParentsHashTable($pages, true , true); // get parent hash table of pages, useref, fixoprphans

    $tree    = buildTreewHash($parents,'',false,true,'url'); // get a nested array from hashtable, from root, norefs, assoc array
    // debugLog($tree);
    $flatary = recurseUpgradeTree($tree); // recurse the tree and add stuff to $tree, return flat reference array
    // debugLog($tree);
    // debugLog($flatary);

    $nesttree[GSMENUNESTINDEX]  = &$tree; //add tree array to menu
    $nesttree[GSMENUFLATINDEX]  = &$flatary[GSMENUFLATINDEX]; // add flat array to menu
    $nesttree[GSMENUINDEXINDEX] = $flatary[GSMENUINDEXINDEX]; // add index array to menu
    // debugLog($nesttree);
    
    return $nesttree;
}

/**
 * builds a nested array from a parent hash table array
 *
 * input [parent]=>array(children) hash table
 * ['parentid'] = array('child1'=>(data),'child2'=>(data))
 *
 * output (nested)
 * ['parentid']['children']['child1'][data,childen]
 *
 * @since 3.4
 * @param  array   $elements    source array, parent hash table with or without child data
 * @param  string  $parentId    starting parent, root ''
 * @param  boolean $preserve    true, preserve all fields, else only id and children are kept
 * @param  boolean $assoc       return namedarray using $idKey for keys ( breaks dups )
 * @param  string  $idKey       key for id field
 * @param  string  $id          key for output id
 * @param  string  $childrenKey key for children sub array
 * @return array                new array
 */
function buildTreewHash($elements, $parentId = '', $preserve = false, $assoc = true, $idKey = 'id', $id = 'id',$childrenKey = 'children') {
    $branch = array();
    $args   = func_get_args();

    foreach ($elements[$parentId] as $element) {
        
        // if missing index field skip, bad record
        if(!isset($element[$idKey])) continue;
        
        $elementKey = $element[$idKey];
        // use index as keys else int
        $branchKey = $assoc ? $elementKey : count($branch);

        // if element is a parent recurse its children
        if (isset($elements[$elementKey])) {
            $args[1]  = $elementKey;
            // recurse elements children
            $children = call_user_func_array(__FUNCTION__,$args);

            // if element has children, add children to branches
            if ($children) {
                // if preserving all fields
                if($preserve){
                    $element[$childrenKey] = $children;
                    $branch[$branchKey] = $element;
                }   
                else $branch[$branchKey] = array($id => $elementKey, $childrenKey => $children);
            }
        } 
        else{
            // else only add element
            if($preserve) $branch[$branchKey] = $element;
            else $branch[$branchKey] = array($id => $elementKey);
        }   
    }

    return $branch;
}

/**
 * recurse a nested parent=>child array and add heirachy information to it
 * 
 * modifies passed nested array by reference, returns flat array with references
 * 
 * handles non assoc arrays with id field
 * 
 * add adjacency info, depth, index, order nesting information
 * adds pathing info, parent, numchildren, and children subarray
 * 
 * reindexes as assoc array using 'id'
 * 
 * children subarray has same structure as roots
 *
 * input array (&REF)
 *
 *  [0] = array(
 *    'id' => 'index',
 *    'children' => array()
 *  );
 * 
 * output array (&REF)
 * 
 * ['index'] = array(
 *  'id' => 'index',
 *  'data' =>  array(
 *    'url' => '/dev/getsimple/master/',
 *    'path' => 'index',
 *    'depth' => 1,
 *    'index' => 9,
 *    'order' => 7
 *  ),
 *  'parent' => '',
 *  'numchildren' => 1,
 *  'children' => array()
 * );
 *
 * returns a flat array containing flat references
 * and an indices array containing indexes and nested array paths
 * 
 * @param  array  &$array    reference to array, so values can be refs
 * @param  str     $parent   parent for recursion
 * @param  integer $depth    depth for recursion
 * @param  integer $index    index ofr recursion
 * @param  array   &$indexAry indexarray reference for recursion
 * @return array             new array with added data
 */
function recurseUpgradeTree(&$array,$parent = null,$depth = 0,$index = 0,&$indexAry = array()){
    // debugLog(__FUNCTION__ . ' ' . count($array));
    
    // use temporary index to store currentpath
    if(!isset($indexAry['currpath'])) $indexAry['currpath'] = array();
    
    // init static $index primed from param
    if($index !== null){
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    }
    else static $index;

    $order = 0;
    $depth++;
    
    array_push($indexAry['currpath'],$parent);

    foreach($array as $key=>&$value){
        if(isset($value['id'])){
            $id = $value['id'];

            // rekey array if not using id keys, needed for non assoc arrays, such as mm submit etc
            // this is not preffered but provided as a failsafe, reindex beforehand reindexMenuArray() to avoid modify ref in loop errors
            // skip rekeyed copies, we need a flag since array is reference, or else it will process the rekeyed elements twice
            if(isset($value['rekeyed'])) continue;
            if($key !== $id){
                $array[$id] = $value; // this modifies &$array and may cause problems
				$array[$id]['rekeyed'] = true;
                unset($array[$key]); // remove old key
                $value = &$array[$id];
            }

            $order++;
            $index++;            

			$value['parent']    = isset($parent) ? $parent : '';
			$value['depth']     = $depth;
			$value['index']     = $index;
			$value['order']     = $order;

            recurseUpgradeTreeCallout($value,$id,$parent); // pass $value by ref for modification

            // add to flat array
            // flat cannot be saved to json because of references soooo we also create an index array
            $indexAry[GSMENUFLATINDEX][$id] = &$value; 
            // create an indices to paths map, so we can rebuild flat array references on json load, if serializing this is not needed
            $indexAry[GSMENUINDEXINDEX][$id] = implode('.',$indexAry['currpath']).'.'.$id;

            if(isset($value['children'])){
                $value['numchildren'] = count($value['children']);
                $children = &$value['children'];
                recurseUpgradeTree($children,$id,$depth,null,$indexAry); // @todo replace with __FUNCTION__
            } else $value['numchildren'] = 0;
        }

    }

    array_pop($indexAry['currpath']); // remove last path, closing out level
    if(!$indexAry['currpath']) unset($indexAry['currpath']); // if empty (exiting root level) remove it
    return $indexAry;
}

/**
 * callout for recurse, to add custom data
 * @since  3.4
 * @param  array &$value  ref array to manipulate
 * @param  string $id     $id of value
 * @param  string $parent parent of value
 * @return array          unused copy of array
 */
function recurseUpgradeTreeCallout(&$value,$id = '',$parent = ''){
    $value['url']   = generate_url($id);
    $value['path']  = generate_permalink($id,'%path%/%slug%');
    // debugLog($value);
    return $value; // non ref
}


/**
 * OUTPUT BUILDER FUNCTIONS
 */

/**
 * RECURSIVE TREE ITERATOR PARENT HASH TABLE
 * tree output from parent hashtable array
 * get tree from parent->child parenthashtable, where child is a pagesArray ref or copy of pages, heirachy info is ignored
 * passes child array to callouts, can be used on native parenthash arrays like parenthashtable, 
 * where children are values of page references or array, keys are parents
 * 
 * generates $level, $index, $order for you
 * 
 * array(
 *     'parent' => array(
 *         &$pagesArray['url'=>'child1'],
 *      ),
 * )
 *
 * itemcallout($id,$level,$index,$order,$open)
 *
 * @note CANNOT SHOW PARENT NODE IN SUBMENU TREE $key, since we can only find children not parents
 * @note not particulary used, but saving in case
 * @param  array   $parents array of parents
 * @param  string  $key     starting parent key
 * @param  string  $str     str for recursion append
 * @param  integer $level   level for recursion incr
 * @param  integer $index   index for recursion incr
 * @param  string  $callout   inner element callout functionname
 * @param  str     $filter  filter callout functionname
 * @return str              output string
 */
function getTreeFromParentHashTable($parents,$key = '',$level = 0,$index = 0, $callout = 'treeCalloutInner', $filter = null){
    if(!$parents) return;

    // init static $index primed from param
    if($index !== null){
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    }
    else static $index;

    $order = 0;
    $str   = '';
    $str  .= callIfCallable($callout,null,true,true,$level,$index,$order);

    foreach($parents[$key] as $parent=>$child){
        if(!is_array($child)) continue;
        if(callIfCallable($filter) === true) continue;

        $level = $level+1;
        $index = $index+1;
        $order = $order+1;

        $str .= callIfCallable($callout,$child,false,true,$level,$index,$order);

        if(isset($parents[$parent])) {
            $str.= getTreeFromParentHashTable($parents,$parent,$level,null,$callout,$filter);
        }
        $level--;
        $str .= callIfCallable($callout,$child,false,false,$level,$index,$order);
    }
    $str .= callIfCallable($callout,null,true,false,$level,$index,$order);
    return $str;
}


/**
 * wrapper for getting menu tree
 * @since  3.4
 * @param  array   $parents array of parents
 * @param  bool    $wrap    generate outer wrap if true
 * @param  string  $callout inner element callout functionname
 * @param  str     $filter  filter callout functionname
 * @param  array   $args    arguments to pass to all callouts
 * @return str              output string
 */
function getMenuTree($menu, $wrap = false, $callback = 'treeCallout', $filter = null, $args = array()){
    $str = '';
    if($wrap) $str .= callIfCallable($callback,null,true);
    $str .= getMenuTreeRecurse($menu,$callback,$filter,0,0,$args);
    if($wrap) $str  .= callIfCallable($callback,null,true,false);
    return $str;
}

/**
 * RECURSIVE TREE ITERATOR NESTED ARRAY
 *
 * Does not generate main wrapper tags! 
 * tree output nested from children array
 * get tree from nested tree array with or without hierarchy info
 * passes menu `child` item to callouts, can be used on menuarrays where children are subarrays with 'id' and 'children' fields
 * 
 * supports adjacency info, but will calculate $level, $index, $order for you if it does not exist, useful when filtering stuff
 * 
 * 'children' subkey
 * 
 * array(
 *   'id' => 'parent',
 *   'children' => array(
 *      'id' => 'child1',
 *      'children' => array()
 *      'depth' => (optional)
 *    ),
 * )
 *
 * itemcallout($child,$level,$index,$order,$open);
 * 
 * filter callout accepts true to skip or GS definitions GSMENUFILTERSKIP, GSMENUFILTERCONTINUE, GSMENUFILTERSHIFT
 * filterresult = filtercallout($item)
 * 
 * @since  3.4
 * @param  array   $parents array of parents
 * @param  string  $callout   item callout functionname
 * @param  str     $filter  filter callout functionname
 * @param  integer $level   level for recursion incr
 * @param  integer $index   index for recursion incr, static reset if empty
 * @param  array   $args    arguments to pass to all callouts 
 * @return str              output string
 */
function getMenuTreeRecurse($parents, $callout= 'treeCallout', $filter = null, $level = 0, $index = 0, $args = array()){
    if(!$parents) return;
    $thisfunc = __FUNCTION__;

    // init static $index
    if($index !== null){
    	// primed from null param
        $indexstart = $index;
        static $index;
        $index = $indexstart;
    } else static $index;

    // init order
    $order = 0;
    $str   = '';

    // detect if a page subarray was directly passed, auto negotiate children
    if(isset($parents['id']) && isset($parents['children'])) $parents = $parents['children'];
    
    // test sorting, using sort index is the fastest to prevent resorting subarrays
    $sort = false;

    GLOBAL $sortkeys;
    if($sortkeys && $sort){
        // @todo since the recurssive function only operates on parent subarray, we do not have acces to menu itself, we could always sort my indices , and allow presorting the menu itself by sorting indices or $menu[GSMENUSORTINDEX]
    	$parents = arrayMergeSort($parents,$sortkeys,false);
    	// debugLog($parents);
    	// debugLog(array_keys($parents));
    }

    foreach($parents as $key=>$child){
        if(!isset($child['id'])) continue;

        // do filtering
        $filterRes = callIfCallable($filter,$child,$level,$index,$order,$args); // get filter result
        if($filterRes){
            debugLog(__FUNCTION__ . ' filtered: (' . $filterRes . ') ' . $child['id'] . ' + ' . $child['numchildren']);
            
            // filter skip, children skipped also, default
            if($filterRes === true || $filterRes === GSMENUFILTERSKIP){
                $str .= debugFilteredItem($child,$filterRes);
                continue;
            }

            // filter continue,  children inherit previous parent
            // @todo breaks $order
            if($filterRes === GSMENUFILTERCONTINUE) {

                $str .= debugFilteredItem($child,$filterRes);

                if(isset($child['children'])) {
                    $str.= $thisfunc($child['children'],$callout,$filter,$level,null,$args); # <li>....
                }
                continue;
            }

            // filter shift. children shifted to root, kludge, move skipped children to root via trick
            // move child to last sibling, then close depth level, shim depth afterwards with hidden elements
            if($filterRes === GSMENUFILTERSHIFT) {

                // no children just continue
                if(!isset($child['children'])) {
                	$str .= debugFilteredItem($child,$filterRes);                	
                    continue;
                }

                // if siblings exist, loop and perform recursion on all other siblings after this one being skipped
                // this is to alleviate the escaping
              	// @todo this breaks $order values, as they are reset on each call
              	// if we are already on level 1, we can probably skip this and switch to GSMENUFILTERCONTINUE instead
              	// and insert at root inline...
                if(count($parents) > 1){
                    $start = false; // use to init postiion of current sibling in parent array
                    foreach($parents as $keyb=>$childb){
                    	if(!isset($childb['id'])) continue;
                        if($childb['id'] == $child['id']){
                            $start = true; 
                            continue;
                        }
                        if(!$start) continue;
                        $str .= $thisfunc(array($childb),$callout,$filter,$level,null,$args);  # <li>...   
                    }
                }

                // close open depths
                for($i=0; $i<$level-1; $i++){
                    $str .= callIfCallable($callout,$child,false,false,$level,$index,$order,$args); # </li>
                    $str .= callIfCallable($callout,$child,true,false,$level,$index,$order,$args); # </ol>
                }

                $str .= debugFilteredItem($child,$filterRes);

                // output skipped children
                $str .= $thisfunc($child['children'],$callout,$filter,$level,null,$args);  # <li>...   

                // reopen open depths as hidden to clean up now extraneous lists
                // call callbacks with null child
                for($i=0; $i<$level-1; $i++){
                    $str .= callIfCallable($callout,null,false,true,$level,$index,$order,$args); # <li>
                    // $str .= '<li style="display:none">';
                    $str .= callIfCallable($callout,null,true,true,$level,$index,$order,$args); # <ol>                    
                    // $str .= '<ul style="display:none">';
                }
                return $str;
            }

        } // end filtering

        $index = $index+1;
        $level = $level+1;
        $order = $order+1;

        // call inner open
        $str .= callIfCallable($callout,$child,false,true,$level,$index,$order,$args); # <li>
        // has children 
        if(isset($child['children'])) {
            
            // recurse children
            $newstr = $thisfunc($child['children'],$callout,$filter,$level,null,$args);  # <li>...   
            
            // call outer open
            // only call outers if recurse return is not empty, in case all children were filtered, otherwise you get an empty outer
            if(!empty($newstr)){
            	$str .= callIfCallable($callout,$child,true,true,$level,$index,$order,$args); # <ol>
            	$str .= $newstr;
            	// call outer close
           		$str .= callIfCallable($callout,$child,true,false,$level,$index,$order,$args); # </ol>
        	}
        }
        
        // call inner close
        $str .= callIfCallable($callout,$child,false,false,$level,$index,$order,$args); # </li>
        $level--;
    }

    return $str;
}

/**
 * debug filtered items, by returning visible elements for them
 * @param  array $item      item processing
 * @param  int $filtertype GSMENUFILTER type
 * @return str             string to be inserted into menu
 */
function debugFilteredItem($item,$filtertype){
    if(!getDef('GSMENUFILTERDEBUG',true)) return '';
    $str = '<strong>#'.$item['index'].'</strong><div class="label label-error">removed</div> ' . $item['id']."<br/>";
    if(isset($item['children']) && $filtertype == GSMENUFILTERSKIP) $str .= '<br> ------ <div class="label label-error">removed</div><strong>'.$item['numchildren'].' children</strong>';
    return $str;
}

/**
 * RECURSIVE TREE ITERATOR NESTED ARRAY
 * minimal tree output from nested children array
 * assumes `id` and `children` subkey
 * passes menu child array to callouts, assumes everything you need is in the array, to be used with menu/ index/ or ref arrays
 * does not calculate heirachy data or use it
 *
 * array(
 *   'id' => 'parent',
 *   'children' => array(
 *      'id' => 'child1',
 *      'children' => array()
 *      'depth' => 1
 *    ),
 * )
 * 
 * itemcallout($page,$open);
 * 
 * @param array   $parents parents array
 * @param str     $str     recursive str for append
 * @param string  $callout item callout functionname
 * @return str             output string
 */
function getMenuTreeMin($parents,$callout = 'treeCallout',$filter = null){
    if(!$parents) return;
    $str  = '';
  
    // if a page subarray was directly passed, auto negotiate children
    if(isset($parents['id']) && isset($parents['children'])) $parents = $parents['children'];

    foreach($parents as $key=>$child){
        if(!isset($child['id'])) continue;
        
        // call inner open
        $str .= callIfCallable($callout,$child,false); # <li>
        // has children 
        if(isset($child['children'])) {
            // call outer open
            $str .= callIfCallable($callout,$child,true); # <ol>
            // recurse
            $str .= getMenuTreeMin($child['children'],$callout,$filter);  # <li>...   
            // call outer close
            $str .= callIfCallable($callout,$child,true,false); # </ol>
        }
        
        // call inner close
        $str .= callIfCallable($callout,$child,false,false); # </li>
    }
    
    return $str;
}

/**
 * generic tree outer callout function for recursive tree functions
 * outputs a basic list
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function treeCalloutOuter($item = null,$open = true){
    // return $open ? "\n<ul>" : "\n</ul>";
}

/**
 * generic tree inner callout function for recursive tree functions
 * outputs a basic list
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function treeCallout($item, $outer = false, $open = true, $level = '', $index = '', $order = ''){
    
    if($outer) return $open ? "\n<ul>" : "\n</ul>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : "</li>";
    // handle pages instead of menu items, pages do not have an id field
    if(!isset($item['id'])){
        if(!isset($item['url'])) return; // fail
        else $item['id'] = $item['url'];
    }

    $title =  $item['id'];
    // $title = debugTreeCallout(func_get_args());
    return $open ? "<li data-depth=".$level.'>'.$title : "</li>";
}

function treeCalloutFilter(){
	if(!getPage($item['id'])) return GSMENUFILTERCONTINUE;
}

/**
 * menu manager tree outer callout function
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function mmCalloutOuter($page = null,$open = true){
    // return $open ? '<ol id="" class="dd-list">' : "</ol>";
}

/**
 * menu manager tree callout function
 * @since  3.4
 * @param  array  $item item to feed this recursive iteration
 * @param  boolean $open is this nest open or closing
 * @return str     string to return to recursive callee
 */
function mmCallout($item, $outer = false, $open = true){

    if($outer) return $open ? '<ol id="" class="dd-list">' : "</ol>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : '</li>';

    $page      = is_array($item) && isset($item['id']) ? getPage($item['id']) : getPage($item);
    $menuTitle = getPageMenuTitle($page['url']);
    // $pageTitle = '<strong>'.$page['title'].'.'.$level.'.'.$order.'</strong>';
    // $pageTitle = $pageTitle.'.'.$page['menuOrder'] .'.'.$page['menuStatus'];
    $pageTitle = $item['id'].'.'.$item['index'] .'.'.$page['menuStatus'];
    // _debugLog($page['url'],$page['menuStatus']);
    if(empty($menuTitle)) $menuTitle = $item['id'];
    $pageTitle = truncate($pageTitle,30);
    $class     = $page['menuStatus'] === 'Y' ? ' menu' : ' nomenu';

    $str = $open ? '<li class="dd-item clearfix" data-id="'.$page['url'].'">'."\n".'<div class="dd-itemwrap '.$class.'"><div class="dd-handle"> '.$menuTitle.'<div class="itemtitle"><em>'.$pageTitle."</em></div></div></div>\n" : "</li>\n";
    return $str;
}


/**
 * tree filter filters pages not exist
 * @param  array $item menu child
 * @return mixed       filter result
 */
function  mmCalloutFilter($item){
	// if(!getPage($item['id'])) return GSMENUFILTERCONTINUE;
}

/**
 * menu inner item callout
 */
function menuCallout($item, $outer = false, $open = true, $level = '', $index = '', $order = '',$args = array()){
    
    if($outer) return $open ? "\n<ul>" : "\n</ul>";

    // if item is null return hidden list , ( this is for GSMENUFILTERSHIFT handling )
    if($item === null) return $open ? '<li style="display:none">' : '</li>';	
	if(!$open) return '</li>';
	
	$page        = getPage($item['id']);
	$classPrefix = $currentpage = '';
	extract($args); // extract arguments into cope
	
	$classes = $menu = '';
	$class = array();

	if(!empty($item['parent'])) $class['parent'] = $item['parent'];
	$class['url']    = $item['id'];
	$classes         = trim($classPrefix.implode(' '.$classPrefix,$class));
	// class="prefix.parent prefix.slug current active"
	if ($currentpage == $page['url']) $classes .= " current active";
	$title = getPageMenuTitle($item['id']); // @todo check in menu for title then fallback to this
	$menu .= '<li class="'. $classes .'"><a href="'. find_url($page['url'],$page['parent']) . '" title="'. encode_quotes(cl($title)) .'">'.var_out(strip_decode($title)).'</a>'."\n";
	
	return $menu;
}

/**
 * menu filter 
 * filters pages not exist, not in menu, and handles maxdepth limiter
 * @param  array $item menu child
 * @return mixed       filter result
 */
function menuCalloutFilter($item,$level,$index,$order,$args){
	$skip = GSMENUFILTERSKIP;
	// page not exist
	if(!getPage($item['id'])) return $skip;
	// page not in menu
    if(getPageFieldValue($item['id'],'menuStatus') !== 'Y') return $skip;
    // max depth limiter
    // _debugLog(func_get_args());
    if(isset($args['maxdepth']) && ($level > ($args['maxdepth']))) return $skip;

	//tests
    // if($item['id'] == 'parent-1b') return $skip;
    // if($item['id'] == 'child-1c') return GSMENUFILTERSHIFT;
}

/**
 * HELPERS
 */

/**
 * shortcut to get pages menu title
 * if menu title not explicitly set fallback to page title
 * @since  3.4
 * @param  str $slug page id
 * @return str page title
 */
function getPageMenuTitle($slug){
    $page = getPage($slug);
    return (trim($page['menu']) == '' ? $page['title'] : $page['menu']);
}

/**
 * reindex a nested menu array recursively
 * array[0] => array('id' => 'index')
 * array['index'] => array('id' => 'index'), and same for all children indexes
 * @since  3.4
 * @param  array $menu menu array
 * @return array       array reindexed
 */
function reindexMenuArray($menu, $force = false){
	foreach($menu as $key=>$item){
        if(!isset($item['id'])) continue;
		$id = $item['id'];
		if($id !== $key || $force){
			$menu[$id]  = $item;
			$menu[$key] = null;
			unset($menu[$key]);
			if(isset($menu[$id]['children'])) $menu[$id]['children'] = reindexMenuArray($menu[$id]['children']);
		}
		// else if(isset($item['children'])) $item['children'] = reindexMenuArray($item['children']);
		else if(isset($menu[$id]['children'])) $menu[$id]['children'] = reindexMenuArray($menu[$id]['children']);
	}

	return $menu;
}

/**
 * MENU IO FUNCTIONS
 */


/**
 * save basic json menu
 * convert basic menu string to gs menu array
 * @since  3.4
 * @param  str $jsonmenu json string of menu data
 * @return array gs menu data array
 */
function newMenuSave($menuid,$menu){
    $menu     = json_decode($menu,true);   // convert to array
	$menu     = reindexMenuArray($menu);   // add id as keys
    $menudata = recurseUpgradeTree($menu); // build full menu data
    $menudata[GSMENUNESTINDEX] = $menu;
    // _debugLog(__FUNCTION__,$menu);
    // _debugLog(__FUNCTION__,$menudata);
    if(getDef('GSMENULEGACY',true)) exportMenuToPages($menudata); // legacy page support
    return menuSave($menuid,$menudata);
}

/**
 * save menu file
 * remove GSMENUFLATINDEX if it exists ( cannot save refs in json )
 * @since  3.4
 * @param  str $menuid menu id
 * @param  array $data array of menu data
 * @return bool        success
 */
function menuSave($menuid,$data){
	GLOBAL $SITEMENU;
	$SITEMENU[$menuid] = $data;

    if(!$data || !$data[GSMENUNESTINDEX]){
        debugLog('menusave: menu is empty - ' .$menuid);
        return false;
    }

    $menufileext = '.json';
    if(isset($data[GSMENUFLATINDEX])){
    	$data[GSMENUFLATINDEX] = null;
    	unset($data[GSMENUFLATINDEX]);
    }
    $status = save_file(GSDATAOTHERPATH.'menu_'.$menuid.$menufileext,json_encode($data));
    return $status;
}

function menuReadFile($menuid){
    $menufileext = '.json';
    $menu = read_file(GSDATAOTHERPATH.'menu_'.$menuid.$menufileext);
    return $menu;
}

/**
 * read menu file
 * rebuild flat reference array
 * @since  3.4
 * @param  str $menuid menu id
 * @return array menudata
 * @return array menudata
 */
function menuRead($menuid){

	$menu = menuReadFile($menuid);

    if(!$menu){
        debugLog('menuRead: failed to load menu - ' . $menuid);
        return;
    }

    $menu = json_decode($menu,true);
    if($menu[GSMENUNESTINDEX]) buildRefArray($menu); // rebuild flat array refs from index map
    else debugLog('menuread: menu is empty - ' .$menuid);
    return $menu;
}

/**
 * GETTERS
 */

/**
 * get a menu object from cache or file
 * lazyload into SITEMENU global
 * 
 * @since  3.4
 * @uses  $SITEMENU
 * @param  string $menuid menuid to retreive
 * @return array         menu array
 */
function getMenuDataArray($menuid = GSMENUPAGESMENUID,$force = false){
    GLOBAL $SITEMENU;
    // return cached local
    if(isset($SITEMENU[$menuid]) && !$force) return $SITEMENU[$menuid];
    
    // load from file
    $menu = menuRead($menuid);
    if($menu) $SITEMENU[$menuid] = $menu;
    return $SITEMENU[$menuid];
}

/**
 * get menu data array
 * 
 * @since 3.4
 * @param  string $page   slug of page
 * @param  string $menuid menu id to fetch
 * @return array  menu sub array of page
 */
function getMenuDataNested($menuid = GSMENUPAGESMENUID){
    $menu = getMenuDataArray($menuid);
    if(!isset($menu)) return;
    // if(!isset($menu[GSMENUNESTINDEX])) buildRefArray(); should not be necessary
    if(isset($menu[GSMENUNESTINDEX])) return $menu[GSMENUNESTINDEX];
}

/**
 * get menu data sub array 
 * 
 * with or without parent item included
 * uses menu flat reference to nested array to resolve
 * @since  3.4
 * @param  string $page   slug of page
 * @param  bool   $parent include parent in sub menu if true
 * @param  string $menuid menu id to fetch
 * @return array  menu sub array of page
 */
function getMenuData($page = '', $parent = false, $menuid = GSMENUPAGESMENUID){
    if(empty($page)) return getMenuDataNested($menuid);
    else $menudata = getMenuDataArray($menuid);

    if(!isset($menudata)) return;
    // if(!isset($menudata[GSMENUFLATINDEX])) buildRefArray(); // should not be necessary
    // debugLog($menudata);   
    if(isset($menudata[GSMENUFLATINDEX][$page])) return $parent ? array($menudata[GSMENUFLATINDEX][$page]) : $menudata[GSMENUFLATINDEX][$page];
    debugLog(__FUNCTION__ .': slug not found in menu('.$menuid.') - ' . $page);
}

/**
 * Build flat reference array onto nested tree
 * using an index array of index keys and path values
 * adds FLAT subarray with references to the nested array onto the $menu array
 * array['flat']['childpage']=>&array['nested']['parent']['children']['childpage']
 * @since  3.4
 * @param  array &$menu  nested tree array
 * @return array         nested tree with flat reference array added
 */
function buildRefArray(&$menu){
	// debugLog(array_keys($menu[GSMENUFLATINDEX]));
    foreach($menu[GSMENUINDEXINDEX] as $key=>$index){
        $index = trim($index,'.');
        $index = str_replace('.','.children.',$index);
        // _debugLog($key,$index);
        $path = explode('.',$index);
        // if the root item no longer exists skip so we do not recreate it via resolvetree
        if(!isset($menu[GSMENUNESTINDEX][$path[0]]) || $menu[GSMENUNESTINDEX][$path[0]] === null){
            // @todo does not work on nested move to root, might not be needed now
            continue;
        }
        $ref = &resolve_tree($menu[GSMENUNESTINDEX],$path);
        // debugLog($key . ' ' . gettype($ref) . ' ' . count($ref) . ' ' . $index . ' ' . $ref['id']);
        // _debugLog($index,$ref);
        if(isset($ref) && (count($ref) > 0) && $ref !== null) $menu[GSMENUFLATINDEX][$key] = &$ref;
        unset($index);
    }
    return $menu;
}

/**
 * recursivly resolves a tree path to nested array sub array
 * 
 * $tree = array('path'=>array('to'=> array('branch'=>item)))
 * $path = array('path','to','branch')
 * @todo  fix this so that is does not return null refs when given a path that does not exist in tree
 * function returns references, $tree param was reference, creating cyclical refs, duh
 * @since 3.4
 * @param  array $tree array reference to tree
 * @param  array $path  array of path to desired branch/leaf
 * @return array        subarray from tree matching path
 */
function &resolve_tree($tree, $path) {
    if(empty($path)) return $tree;
    if(!empty($tree) && isset($tree)){
    	return resolve_tree($tree[$path[0]], array_slice($path, 1));
    }
    // @todo curious as to why does this not work the same as above, must be some odd reference passing issue
    // return empty($path) ? $tree : resolve_tree($tree[$path[0]], array_slice($path, 1));
    return $tree;
}

function getMenuItem($menu,$id = ''){
    if(isset($menu[GSMENUFLATINDEX]) && isset($menu[GSMENUFLATINDEX][$id])) return $menu[GSMENUFLATINDEX][$id];
}

function getMenuItemParent($menu,$slug = ''){
	$item = getMenuItem($menu,$slug);
	if(!$item) return;

	if(!empty($item['parent'])){
    	return getMenuItem($menu,$item['parent']);
    }
}

/**
 * EXPORT / LEGACY
 */


/**
 * save menu data to page files, refresh page cache
 * @since  3.4
 * @uses  saveMenuDataToPage
 * @param  array $menu menu array
 */
function exportMenuToPages($menu){
    $pages = getPages();
    if(!$menu){ 
        debugLog('no menu to save');
        return;
    }
    foreach($pages as $page){
        $id = $page['url'];
        // if not in menu wipe page data
        if(!isset($menu[GSMENUFLATINDEX][$id])){
            saveMenuDataToPage($id);
            continue;
        }
        // debugLog($menu[GSMENUFLATINDEX][$id]);
        $parent = $menu[GSMENUFLATINDEX][$id]['parent'];
        $order  = $menu[GSMENUFLATINDEX][$id]['index'];
        saveMenuDataToPage($id,$parent,$order);
    }

    // regen page cache
    create_pagesxml('true');
}

/**
 * set page data menu information
 * update page with parent and order, only if it differs
 * @since  3.4
 * @param  str $pageid page id to save
 * @param  str $parent page parent
 * @param  int $order page order
 * @return  bool success
 */
function saveMenuDataToPage($pageid,$parent = '',$order =''){
    // do not save page if nothing changed
    if((string)returnPageField($pageid,'parent') == $parent && (int)returnPageField($pageid,'menuOrder') == $order) return;

    $file = GSDATAPAGESPATH . $pageid . '.xml';
    if (file_exists($file)) {
        $data = getPageXML($pageid);
        $data->parent->updateCData($parent);
        $data->menuOrder->updateCData($order);
        return XMLsave($data,$file);
    }
}



/**
 * GARBAGE
 */

/**
 * 3.3.x menu save directly to pages
 */
function MenuOrderSave_OLD(){
    $menuOrder = explode(',',$_POST['menuOrder']);
    $priority = 0;
    foreach ($menuOrder as $slug) {
        $file = GSDATAPAGESPATH . $slug . '.xml';
        if (file_exists($file)) {
            $data = getPageXML($slug);
            if ($priority != (int) $data->menuOrder) {
                unset($data->menuOrder);
                $data->addChild('menuOrder')->addCData($priority);
                XMLsave($data,$file);
            }
        }
        $priority++;
    }
    create_pagesxml('true');
    $success = i18n_r('MENU_MANAGER_SUCCESS');
    return $success;
}


function pagesToMenuOLD($pages,$parent = ''){
    static $array;
    if($parent == '') $array = array();
    foreach($pages[$parent] as $key=>$page){
        $newparent = $page['title'];
        _debugLog($key,$page);
        // _debugLog($key,$newparent);
        if($newparent != '' && isset($pages[$newparent])) $array[] = array('id' => $page['url'],'children' => pagesToMenu($pages,$newparent));
        else $array[]['id'] = $page['url'];
    }
    
    return $array;
}

function pagesToMenu($pages,$parent = 'index'){
    static $array;
    if($parent == '') $array = array();
    debugLog($parent);

    debugLog($pages[$parent]);
    foreach($pages[$parent] as $key=>$page){
        $newparent = $page['url'];
        // debugLog($key,$newparent);
        // _debugLog($key,$newparent);
        if(isset($pages[$newparent])){
            _debugLog($newparent,count($pages[$newparent]));
            $newarray = pagesToMenu($pages,$newparent);
            // $array[$parent]['children'][] = $newarray;
            // 
            $array[$parent][] = array('id' => $parent,'children' => $newarray);
        } 
        else $array[$parent][] = array('id' => $parent);
    }
    
    return $array;
}

// @todo build a nested tree array from flat array with `parent` and children array, children key is `children`
function buildTree(array $elements, $parentId = '') {
    $branch = array();

    foreach ($elements as $element) {
        if ($element['parent'] == $parentId) {
            $children = buildTree($elements, $element['url']);
            if ($children) {
                $element['children'] = $children;
            }
            $branch[] = $element;
        }
    }

    return $branch;
}


/**
 * builds a flat reference map from a nested tree
 * @unused
 * @param  array $menu     nested array
 * @param  array $flattree destination array
 * @return array           flat indexed array
 */
function buildRefArrayRecursive($menu,$flattree = array()){
    foreach($menu as $item){
        $flatree['item']['id'] = &$item;
        if(isset($item['children'])) buildRefArrayRecursive($item['children'],$flattree);
    }

    return $flattree;
}

/**
 * dynamically get nested array reference from flat index 
 * caches it from flat array, and dynamically add if it doesnt exist already
 * using index to resolve to nested
 * uses resolve_tree() to resolve flat path to nested
 * @param  array &$menu  nested array
 * @param  str $id       flat index
 * @return array         reference to nested subarray
 */
function &getRefArray(&$menu,$id){
    if(isset($menu[GSMENUFLATINDEX]) && isset($menu[GSMENUFLATINDEX][$id])) return $menu[GSMENUFLATINDEX][$id];
    $index = $menu[GSMENUINDEXINDEX][$id];
    $index = trim($index,'.');
    $index = str_replace('.','.children.',$index);
    $ref = resolve_tree($menu[GSMENUNESTINDEX],explode('.',$index));
    return $ref;
}


function menuCalloutInnerTest($page,$open = true){
    if(!$open) return '</li>';

    $depth = $page['depth'];
    $page = getPage($page['id']);
    
    $menutext = $page['menu'] == '' ? $page['title'] : $page['menu'];
    $menutitle = $page['title'] == '' ? $page['menu'] : $page['title'];
    $class = $page['parent'] . ' D' . $depth; 

    $str = '<li data-id="'.$page['url'].'" class="'.$class.'">';
    $str .= '<a href="'. find_url($page['url']) . '" title="'. encode_quotes(cl($menutitle)) .'">'.strip_decode($menutext).'</a>'."\n";

    return $str;
}

function menuCalloutOuterTest($page = null,$open = true){
    return $open ? '<ul id="">' : '</ul>';
}

function selectCalloutInner($id, $level, $index, $order, $open = true){
    if(!$open) return;
    $page = getPage($id);
    $disabled = $page['menuStatus'] == 'Y' ? 'disabled' : '';
    return '<option id="'.$id.'" '.$disabled. '>' .str_repeat('-',$level-1) . $page['title']. '</option>';
}

function selectCalloutOuter(){

}

function treeFilterCallout($id,$level,$index,$order){
    $child = getPage($id);
    return $child['menuStatus'] !== 'Y';
}

// debugging
function debugTreeCallout($args){
    $item = $args[0];
    // use internal
    // if(is_array($args) && !isset($item['data'])){
    //     $item['data']['depth'] = $args[2];
    //     $item['data']['index'] = $args[3];
    //     $item['data']['order'] = $args[4];
    //     // $debug .= ' [' . $args[2] . ']';
    // }
    $debug = '<strong>#'.(isset($args[3]) ? $args[3] :$item['index']).'</strong> '.$item['id'];
    $debug .= ' [ ' . $item['index'].' - '.$item['depth'].'.'.$item['order'] . ' ]';
    $debug .= ' [ ' . $args[3].' - '.$args[2].'.'.$args[4]. ' ]';
    return $debug;
}

function legacyMenuManagerOutput($pages){

	if (count($pages) != 0) {
		echo '<form method="post" action="menu-manager.php">';
		echo '<ul id="menu-order" >';
		foreach ($pages as $page) {
			$sel = '';
			if ($page['menuStatus'] != '') {

				if ($page['menuOrder'] == '') {
					$page['menuOrder'] = "N/A";
				}
				if ($page['menu'] == '') {
					$page['menu'] = $page['title'];
				}
				echo '<li class="clearfix" rel="' . $page['slug'] . '">
												<strong>#' . $page['menuOrder'] . '</strong>&nbsp;&nbsp;
												' . $page['menu'] . ' <em>' . $page['title'] . '</em>
											</li>';
			}
		}
		echo '</ul>';
		echo '<div id="submit_line"><span>';
		echo '<input type="hidden" name="menuOrder" value=""><input class="submit" type="submit" value="' . i18n_r("SAVE_MENU_ORDER") . '" />';
		echo '</span></div>';
		echo '</form>';
	} else {
		echo '<p>'.i18n_r('NO_MENU_PAGES').'.</p>';
	}
}
/* ?> */