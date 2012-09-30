<?php
/** 
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * @filesource	cfield_mgr.class.php
 * @package 	TestLink
 * @author 		franciscom
 * @copyright 	2005-2011, TestLink community
 * @copyright 	Mantis BT team (some parts of code was reuse from the Mantis project) 
 * @link 		http://www.teamst.org/index.php
 *
 * @internal revisions
 *
**/

/** load conversion functions */
require_once(dirname(__FILE__) . '/date_api.php');
require_once(dirname(__FILE__) . '/string_api.php');

// Copied from mantis, allow load of user custom implementations
// some sort of poor's man plugin
$cf_files=glob( TL_ABS_PATH . "custom/cf_*.php");
if( count($cf_files) > 0 )
{
    foreach($cf_files as $inc)
    {
        require_once($inc);  
    }   
}


/**
 * class is responsible for logic and store Custom Fields functionality 
 * @package 	TestLink
 */
class cfield_mgr extends tlObject
{

    /** 
     * Will be used ONLY if config options:
     * config_get('custom_fields')->sizes
     * is not defined
     *
     */
    const DEFAULT_INPUT_SIZE = 50;
    const MULTISELECTIONLIST_WINDOW_SIZE = 5;
    const LISTBOX_WINDOW_SIZE = 5;

    /** 
     * for text area custom field  40 x 6 -> 240 chars <= 255 chars table field size 
     * Will be used ONLY if config options:
     * 
     * (config_get('custom_fields')->sizes)['text area']['cols']
     * (config_get('custom_fields')->sizes)['text area']['rows']
     * 
     * are not defined
     *
     */
    const TEXTAREA_DEFAULT_COLS = 70;
    const TEXTAREA_DEFAULT_ROWS = 4;

    const CF_ENABLED = 1;
    const ENABLED = 1;
    const DISABLED = 0;
    
	/** @var resource the database handler */
	var $db;

	/** @var object tree class */
	var $tree_manager;

    /**
     *  @var array $application_areas
     * Holds string keys used on this object and pages that manages CF,
     * identifying in what areas/features something will be done
     * 'execution' => mainly on test execution pages,
     *                identifies TL features/pages to record test results
     * 'design'    => test suites, test cases creation
     *                identifies TL features/pages to create test specification
     * 'testplan_design' => link test cases to test plan (assign testcase option)
     * 
     * IMPORTANT: this values are used as access keys in several properties of this object.
     *            then if you add one here, remember to update other properties.
     */
    var $application_areas = array('execution','design','testplan_design');

	/**
	 * @var array Define type of custom fields managed.
	 * Values will be displayed in "Custom Field Type" combo box when
	 * users create custom fields. No localization is applied
	 */ 
    // Added specific type for test automation related custom fields.
    // Start at code 500
    var $custom_field_types = array(0=>'string',
                                    1=>'numeric',
                                    2=>'float',
                                    4=>'email',
                                    5=>'checkbox',
                                    6=>'list',
                                    7=>'multiselection list',
                                    8=>'date',
                                    9=>'radio',
                                    10=>'datetime',
		  					        20=>'text area',
							        500=>'script',
							        501=>'server');

    /** 
     * @var array Configures for what type of CF "POSSIBLE_VALUES" field need to be manage at GUI level
     * Keys of this map must be the values present in:
     * <code>$this->custom_field_types</code>
     */ 
    var $possible_values_cfg = array('string' => 0,
                                     'numeric'=> 0,
                                     'float'=> 0,
                                     'email'=> 0,
                                     'checkbox' => 1,
                                     'list' => 1,
                                     'multiselection list' => 1,
                                     'date' => 0,
                                     'radio' => 1,
                                     'datetime' =>0,
                                     'text area' => 0,
    							     'script'=> 0,
    							     'server' => 0);
    
    /**  @var array only the types listed here can have custom fields */
    var $node_types = array('testsuite','testplan','testcase','requirement_spec','requirement');

   /**
     *  @var map of maps $locations
     *  
     *  Location is place on page where to display custom field.
     *  This concept has been created to implement a user contribution, that allows for
     *  test cases, display custom fields in a different location (standard location is after
     *  all test case definition), to implemente Prerequisites using CF.
     *
     *  First map key: node type: 'testcase','testsuite', etc.
     *  Each element will be a map with following structure:
     *  key:Holds string keys used on this object and pages that manages CF.
     *      current options: 1 -> standard location, i.e. work as done before this implementation.
     *                       2 -> before steps and results, => between summary and steps/results.
     *
     *  value: used to get translated label to use on User Interface.
     * 
     * IMPORTANT: if you add a new key, this values are used as access keys in several properties of this object.
     *            then if you add one here, remember to update other properties.
     * @see getLocations();
     */
    var $locations;

    // Needed to manage user interface, when creating Custom Fields.
    // When user choose a item type (test case, etc), a javascript logic
    // uses this information to hide/show enable_on, and show_on combos.
    //
    // 0 => combo will not displayed
    //
    // May be need a review, because after the changes, seems a little bit silly.
    var $enable_on_cfg = array(	'execution' => array('testsuite' => 0,
                                                    'testplan'  => 0,
                                                    'testcase'  => 1,
                                                    'requirement_spec' => 0,
                                                    'requirement' => 0),
								'design' => array('testsuite' => 0,
                                                  'testplan'  => 0,
                                                  'testcase'  => 1,
                                                  'requirement_spec' => 0,
                                                  'requirement' => 0),
                             	'testplan_design' => array('testsuite' => 0,
                                                          'testplan'  => 0,
                                                          'testcase'  => 1,
                                                          'requirement_spec' => 0,
                                                          'requirement' => 0));

  // 0 => combo will not displayed
  var $show_on_cfg=array('execution'=>array('testsuite' => 1,
	                                          'testplan'  => 1,
	                                          'testcase'  => 1,
	                                          'requirement_spec' => 0,
	                                          'requirement' => 0 ),
                         'design' => array('testsuite' => 1,
	                                         'testplan'  => 1,
	                                         'testcase'  => 1,
	                                         'requirement_spec' => 0,
	                                         'requirement' => 0 ),
                         'testplan_design' => array('testsuite' => 1,
	                                         'testplan'  => 1,
	                                         'testcase'  => 1,
	                                         'requirement_spec' => 0,
	                                         'requirement' => 0 )
	                                         );

    // the name of html input will have the following format
    // <name_prefix>_<custom_field_type_id>_<progressive>
    //
    var $name_prefix='custom_field_';
    var $sizes = null;
    
    // must be equal to the lenght of:
    // value 		 column on cfield_*_values tables
    // default_value column on custom_fields table
    // 0 -> no limit
    // is used on text area types
    // Used on JS logic to provide feedback to user (how many chars still can be entered)
    // when typing on a Text Area Custom Field
    var $max_length_value;
    
    // must be equal to the lenght of:
    // possible_values column on custom_fields table
    // 0 -> no limit
    var $max_length_possible_values;
    
    
	/**
	 * Class constructor
	 * 
	 * @param resource &$db reference to the database handler
	 */
	function __construct(&$db)
	{
	  parent::__construct();

		$this->db = &$db;
		$this->tree_manager = new tree($this->db);

		$cfConfig = config_get('custom_fields');
		$this->sizes = $cfConfig->sizes;
		
    if( property_exists($cfConfig,'types') && !is_null($cfConfig->types) )
		{
		    $this->custom_field_types +=$cfConfig->types;
		    ksort($this->custom_field_types);
		}
    
    if( property_exists($cfConfig,'possible_values_cfg') && !is_null($cfConfig->possible_values_cfg) )
		{
		    $this->possible_values_cfg +=$cfConfig->possible_values_cfg;
		}
    
    $this->object_table=$this->tables["custom_fields"];
    $this->max_length_value = $cfConfig->max_length;
    $this->max_length_possible_values = $this->max_length_value;
    
    $this->locations = $this->getLocations();
	}

  function getSizeLimit()
  {
        return $this->max_length_value;    
  }

	function get_application_areas()
	{
    return($this->application_areas);
  }

  /**
	 * @return hash with available locations
	 * 
   */
	static function getLocations()
	{
    return array( 'testcase' => array( 1 => 'standard_location', 2 => 'before_steps_results'));
  }


	/**
	 * @return hash with custom field available types
	 *       key: numeric id
	 *       value: short description
	 */
	function get_available_types()
	{
		return($this->custom_field_types);
	}

	/** 
	 * @return string 
	 */
	function get_name_prefix()
	{
		return $this->name_prefix ;
	}

	/**
	 * @return hash with node types id, that can have custom fields.
	 *       key:   short description (node_types.description)
	 *       value: node_type_id      (node_types.id)
	 */ 
	function get_allowed_nodes()
	{
		$allowed_nodes=array();
		$tl_node_types=$this->tree_manager->get_available_node_types();
		foreach($this->node_types as $verbose_type )
		{
			$allowed_nodes[$verbose_type]=$tl_node_types[$verbose_type];
		}
		return($allowed_nodes);
	}

	/**
	 * @return hash with node types id, that can have custom fields with enabled_on_$ui_mode.
	 *       key  : node_type_id      (node_types.id)
	 *       value: 1 -> enable on exec can be configured by user
	 */
	function get_enable_on_cfg($ui_mode)
	{
		$mgmt_cfg=array();
		$mgmt_cfg=$this->_get_ui_mgtm_cfg_for_node_type($this->enable_on_cfg[$ui_mode]);
		return($mgmt_cfg);
	}


	function get_show_on_cfg($ui_mode)
	{
		$mgmt_cfg=array();
		$mgmt_cfg=$this->_get_ui_mgtm_cfg_for_node_type($this->show_on_cfg[$ui_mode]);
		return($mgmt_cfg);
	}


  /*
    function: _get_ui_mgtm_cfg_for_node_type
              utility method

    returns: hash with node types id.
             key  : node_type_id      (node_types.id)
             value: 1 -> enable on exec can be configured by user


  */
function _get_ui_mgtm_cfg_for_node_type($map_node_id_cfg)
{
    $enabled_mgmt=array();
    $tl_node_types=$this->tree_manager->get_available_node_types();
    foreach($this->node_types as $verbose_type)
    {
        $type_id=$tl_node_types[$verbose_type];
        if( isset($map_node_id_cfg[$verbose_type]) )
        {
          $enabled_mgmt[$type_id]=$map_node_id_cfg[$verbose_type];
        }
    }
    return($enabled_mgmt);
}



  /*
    function: get_possible_values_cfg

    returns: hash
             key  : cf_type_id      (see $custom_field_types)
             value: 1 -> possible values can be managed on UI.


  */
  function get_possible_values_cfg()
	{
    $pv_cfg=array();
    $custom_field_types_id=array_flip($this->custom_field_types);

    foreach($this->possible_values_cfg as $verbose_cf_type => $use_on_ui)
    {
      $cf_type_id=$custom_field_types_id[$verbose_cf_type];
      $pv_cfg[$cf_type_id]=$use_on_ui;
    }
    return($pv_cfg);
  }




  /*
    function: get_linked_cfields_at_design
              returns information about custom fields that can be used
              at least at design time, with the value assigned (is any has been assigned).


    $tproject_id: needed because is possible to associate/link
                  a different set of custom field for every test project

    $enabled    : 1 -> get custom fields that are has been configured
                       to be shown during specification design AND are enabled.

                       Remember that also exist custom fields
                       that can be only used during TEST CASE EXECUTION.

    [$filters]:default: null
               map with keys:
               [show_on_execution]: 1 -> filter on field show_on_execution=1
                                     0 or null or not exists -> don't filter

               [show_on_testplan_design]: 1 -> filter on field show_on_execution=1
                                           0 or null or not exists -> don't filter

			   [cfield_id]: if exists use it's value to filter on custom field id
                              null or not exists -> don't filter

			   [location]: new concept used to define on what location on screen
			                 custom field will be designed.
			                 Initally used with CF available for Test cases, to
			                 implement pre-requisites.
                             null => no filtering

    [$node_type]: default: null
                  verbose id ('testcase', 'testsuite', etc) of a node type.
                  custom fields are linked also to different node types.
                  Example:
                  I can define a custom field "Aspect" with values
                  Performace, Usability and wnat use it only for test suites.

    [$node_id]: default: null
                identification of a node/element on node hierarchy.
                Needed when I want to get the value of custom fields
                linked to a node.
                Example:
                Have two test cases (ID:9999, ID:89800), and want to get
                the value assigned to custom field "Operating System".
                I will do two calls to this method.

    [$access_key]: default id, field name to use as access key in returned hash
    
    returns: hash
             key: custom field id


    rev :

		  20090420 - franciscom
          added new key cfield_id on filters

          20080811 - franciscom
          interface changes $show_on_execution -> $filters
         
          
          20070526 - franciscom
          changed order by clause

          20070101 - franciscom
          1. added filter on cfield_testprojects.active=1
          2. added new argument $show_on_execution


  */
  function get_linked_cfields_at_design($tproject_id,$enabled,$filters=null,
                                        $node_type=null,$node_id=null,$access_key='id')
  {
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
  	
    $additional_join="";
    $additional_values="";
    $additional_filter="";

    if( !is_null($node_type) )
    {
        $hash_descr_id = $this->tree_manager->get_available_node_types();
        $node_type_id=$hash_descr_id[$node_type];

        $additional_join  .= " JOIN {$this->tables['cfield_node_types']} CFNT ON CFNT.field_id=CF.id " .
                             " AND CFNT.node_type_id={$node_type_id} ";
    }
    if( !is_null($node_id) )
    {
      $additional_values .= ",CFDV.value AS value,CFDV.node_id AS node_id";
      $additional_join .= " LEFT OUTER JOIN {$this->tables['cfield_design_values']} CFDV ON CFDV.field_id=CF.id " .
                          " AND CFDV.node_id={$node_id} ";
    }

    // 20080811 - franciscom - refactoring for BUGID 1650 (REQ)
    if( !is_null($filters) )
    {
        if( isset($filters['show_on_execution']) && !is_null($filters['show_on_execution']) )
        {
            $additional_filter .= " AND CF.show_on_execution=1 ";
        }   
        
        // 20090523 - franciscom
        // Probably this piece need to be changed to act on enable_on_ attribute
        // due to CF display logic refactoring
        // if( isset($filters['show_on_testplan_design']) && !is_null($filters['show_on_testplan_design']) )
        // {
        //     $additional_filter .= " AND CF.show_on_testplan_design=1 ";
        // }   
        if( isset($filters['show_on_testplan_design']) && !is_null($filters['show_on_testplan_design']) )
        {
            $additional_filter .= " AND CF.enable_on_testplan_design=1 ";
        }   
           
        if( isset($filters['cfield_id']) && !is_null($filters['cfield_id']) )
        {
            $additional_filter .= " AND CF.id={$filters['cfield_id']} ";
        }
        
        // 20090717 - franciscom
        $filterKey='location';
        if( isset($filters[$filterKey]) && !is_null($filters[$filterKey]) )
        {
            $additional_filter .= " AND CFTP.$filterKey={$filters[$filterKey]} ";
        }
    }

    $sql="/* $debugMsg */ SELECT CF.*,CFTP.display_order,CFTP.location" .
         $additional_values .
         " FROM {$this->object_table} CF " .
         " JOIN {$this->tables['cfield_testprojects']} CFTP ON CFTP.field_id=CF.id " .
         $additional_join .
         " WHERE CFTP.testproject_id={$tproject_id} " .
         " AND   CFTP.active=1     " .
         " AND   CF.show_on_design=1     " .
         " AND   CF.enable_on_design={$enabled} " .
         $additional_filter .
         " ORDER BY display_order,CF.id ";

  	// echo "<br>debug - <b><i>" . __FUNCTION__ . "</i></b><br><b>" . $sql . "</b><br>";

	
    $map = $this->db->fetchRowsIntoMap($sql,$access_key);
    return($map);
  }


	/*
	  ====================================================================
    ** Very Imporant ** 
    This code is based on Mantis code.
    Initial development was based on 1.x.x versions.
    file:custom_field_api.php - function:print_custom_field_input()
    
    20080815: some changes are done to add more flexibility, and idea
              was compared with 1.2.0a1 Mantis implementation.

         added code to manange user defined (and code developed) Custom Fields.
         Important: solution is a mix of own ideas and Mantis 1.2.0a1 approach

    ====================================================================          

    function: string_custom_field_input
              returns an string with the html needed to display the custom field.
   
              If no specific code is found to manage a custom field type,
              it will be used code that manage string type.

    args: $p_field_def: contains the definition of the custom field
                        (including it's field id)

          [$name_suffix]: if used must start with _.
                          example _TCID017

    returns: html string

	**************************************************************************************************
	Development Note
	**************************************************************************************************
	User can define a CHECKBOX and give NO possible values:
	
		name: CBNOV
		label: ACCEPT TERMS
		Possibile values: <leave it EMPTY>
	
	in this situation on GUI he/she will get: 	ACCEPT TERMS: []
	
	in this situation when user CHECKS we get EMPTY string as input AND IS OK and have to be WRITTEN to DB
	TO AVOID THIS SITUATION we have made changes here to force lang_get('Yes') as value

	
	If user define a check box this way
		name: CBWATER
		label: WATER TYPES
		Possibile values: NORMAL | SPARKLING
	
	in this situation on GUI he/she will get:	WATER TYPES: [] NORMAL [] SPARKLING
	**************************************************************************************************

    @internal revisions
    
  */
	function string_custom_field_input($p_field_def,$name_suffix='',$field_size=0,$show_on_filters=false)
	{

		$str_out='';
	  	$t_custom_field_value = $p_field_def['default_value'];
	  	if( isset($p_field_def['value']) )
		{
		  $t_custom_field_value = $p_field_def['value'];
		}

    	$verbose_type=trim($this->custom_field_types[$p_field_def['type']]);
  		$t_custom_field_value = htmlspecialchars( $t_custom_field_value );
    	$input_name = $this->buildHTMLInputName($p_field_def,$name_suffix);
    	$size = isset($this->sizes[$verbose_type]) ? intval($this->sizes[$verbose_type]) : 0;
    	$required = $p_field_def['required'] ? ' class="required" ' : ' class="" ';
		$dateOpt = array('default_disable' => false, 'allow_blank' => true, 'required' => $required,
						 'show_on_filters' => $show_on_filters);

    	if( $field_size > 0)
    	{
    	  $size=$field_size;
    	}

        
		switch ($verbose_type)
		{
  		case 'list':
  		case 'multiselection list':
   			$t_values = explode( '|', $p_field_def['possible_values']);
   			$t_values_count = count($t_values);
        	if( $verbose_type == 'list' )
        	{
        	   // get maximum allowed window size for lists
        	   $window_size = intval($size) > 1 ? $size : self::LISTBOX_WINDOW_SIZE;
        	   
        	   $t_multiple=' ';
        	   $t_name_suffix=''; 
        	}
        	else
        	{
        	   // get maximum allowed window size for mutliselection lists
        	   $window_size = intval($size) > 1 ? $size : self::MULTISELECTIONLIST_WINDOW_SIZE;
        	   $t_name_suffix='[]';
        	   $t_multiple=' multiple="multiple" ';
        	}
        	
        	// lists and multiselection lists do not use more space than necessary
        	// set the list size to the number of possible values of custom field
            // but respect the maximum window size
        	$t_list_size = $t_values_count;
		    if($t_list_size > $window_size)
        	{
        	   $t_list_size=$window_size;
        	}
        	
        	$html_identity=$input_name . $t_name_suffix;
  			$str_out .="<select {$required} name=\"{$html_identity}\" id=\"{$input_name}\" {$t_multiple}";
  			$str_out .= ' size="' . $t_list_size . '">';
        	
  			$t_selected_values = explode( '|', $t_custom_field_value );
   			foreach( $t_values as $t_option ) {
  				if( in_array( $t_option, $t_selected_values ) ) {
   					$str_out .='<option value="' . $t_option . '" selected> ' . $t_option . '</option>';
   				} else {
   					$str_out .='<option value="' . $t_option . '">' . $t_option . '</option>';
   				}
   			}
   			$str_out .='</select>';
		break;

		case 'checkbox':
			$t_values = explode( '|', $p_field_def['possible_values']);
        	$t_checked_values = explode( '|', $t_custom_field_value );

			foreach( $t_values as $t_option )
			{
				$str_out .= '<input ' . $required . ' type="checkbox" name="' . $input_name . '[]"' . 
							" id=\"{$input_name}\"";

				// need to manage situation where user has not assigned possible values
				// will force lang_get('yes')
				$t_gui_value = ($t_option == '') ? lang_get('Yes') : $t_option;
				
				// added check $t_option != '' to make check box start NOT CHECKED
				if( $t_gui_value != '' && in_array($t_gui_value, $t_checked_values) )
				{
					  $str_out .= ' value="' . $t_gui_value . '" checked="checked">&nbsp;' . $t_option . '&nbsp;&nbsp;';
				}
				else
				{
					  $str_out .= ' value="' . $t_gui_value . '">&nbsp;' . $t_option . '&nbsp;&nbsp;';
				}
				
			}
 	    break;

  		case 'string':
  		case 'email':
  		case 'float':
  		case 'numeric':
			$str_out .= $this->string_input_string($p_field_def,$input_name,$t_custom_field_value,$size);
			break ;

		case 'text area':
			$cols = intval($this->sizes['text area']['cols']);
			$rows = intval($this->sizes['text area']['rows']);
			if($cols <= 0)
			{
				$cols = self::TEXTAREA_DEFAULT_COLS;
			}
			if($rows <= 0)
			{
				$rows = self::TEXTAREA_DEFAULT_ROWS;
			}
			
			if( $this->max_length_value > 0 )
			{
				$counterId = $input_name . '_counter';
				$cf_current_size = $this->max_length_value - tlStringLen($t_custom_field_value);
            	
				// call JS function for check max. size from validate.js
				$js_function = '"textCounter(this.form.' . $input_name . 
				               ',document.getElementById(\''. $counterId.'\'),' . $this->max_length_value .');" ';
			
				$str_out .= '<textarea ' . $required . ' name="' . $input_name . '" ' . " id=\"{$input_name}\" " .
					    	'onKeyDown=' . $js_function . ' onKeyUp=' . $js_function . 'cols="' .
					        $cols . '" rows="' . $rows . '">' . "{$t_custom_field_value}</textarea>\n";

			    // show character counter
			    $str_out .= '<br><span style="vertical-align: top; padding: 5px;">' .
				    	    sprintf(lang_get('text_counter_feedback'), $this->max_length_value) .
					        ' <span id="' . $counterId .'">'.$cf_current_size.'</span>.</span><br>';
			}		
            else
            {
            	// unlimited
				$str_out .= '<textarea ' . $required . ' name="' . $input_name . '" ' . " id=\"{$input_name}\" " .
					    	'cols="' . $cols . '" rows="' . $rows . '">' . "{$t_custom_field_value}</textarea>\n";
            		
            }
		break;

		case 'date':
      		$str_out .= create_date_selection_set($input_name,config_get('date_format'),$t_custom_field_value,$dateOpt);
		break;
      
      case 'datetime':
      	$cfg = config_get('gui');
      	
      	// Important
      	// We can do this mix (get date format configuration from standard variable 
      	// and time format from an specific custom field config) because string used 
      	// for date_format on strftime() has no problem
      	// on date() calls (that are used in create_date_selection_set() ).
      	$datetime_format=config_get('date_format') . " " . $cfg->custom_fields->time_format;
      	$str_out .= create_date_selection_set($input_name,$datetime_format,$t_custom_field_value,$dateOpt);
      break;
      

      default:
      	$dynamic_call='string_input_' . str_replace(' ', '_', $verbose_type);
      	if( function_exists($dynamic_call) )
      	{
      	    $str_out .= $dynamic_call($p_field_def, $input_name, $t_custom_field_value);      
      	}
      	else if( method_exists($this, $dynamic_call) )
      	{
      	    $str_out .= $this->$dynamic_call($p_field_def, $input_name, $t_custom_field_value);
      	}
      	else
      	{
      	    // treat it as an simple string  
     		$str_out .= $this->string_input_string($p_field_def,$input_name,$t_custom_field_value,$size);
      	}
      break;


  	}
  	return ($str_out);
	} //function end


  /*
    function: design_values_to_db
              write values of custom fields that are used at design time.

    args: $hash: contains info about CF gathered at user interface.
                 (normally $_REQUEST variable)
                 key: custom_field_<field_type_id>_<cfield_id>.
                      Example custom_field_0_67 -> 0=> string field

          $node_id:
          [$cf_map]:  hash -> all the custom fields linked and enabled
                              that are applicable to the node type of $node_id.

                              For the keys not present in $hash, we will write
                              an appropriate value according to custom field
                              type.

                              This is needed because when trying to udpate
                              with hash being $_REQUEST, $_POST or $_GET
                              some kind of custom fields (checkbox, list, multiple list)
                              when has been deselected by user.


	**************************************************************************************************
	Development Note
	**************************************************************************************************
	User can define a CHECKBOX and give NO possible values:
	
		name: CBNOV
		label: ACCEPT TERMS
		Possibile values: <leave it EMPTY>
	
	in this situation on GUI he/she will get: 	ACCEPT TERMS: []
	
	in this situation when user CHECKS we get EMPTY string as input AND IS OK and have to be WRITTEN to DB
	TO AVOID THIS SITUATION we have made changes on string_custom_field_input()
	
	
	If user define a check box this way
		name: CBWATER
		label: WATER TYPES
		Possibile values: NORMAL | SPARKLING
	
	in this situation on GUI he/she will get:	WATER TYPES: [] NORMAL [] SPARKLING
	**************************************************************************************************

	
    @internal revisions
	20110612 - franciscom - che
  */
  function design_values_to_db($hash,$node_id,$cf_map=null,$hash_type=null)
  {
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    if( is_null($hash) && is_null($cf_map) )
    {
       return;
    }
    if( is_null($hash_type) )
    {
      $cfield=$this->_build_cfield($hash,$cf_map);
    }
    else
    {
      $cfield=$hash;
    }

    if( !is_null($cfield) )
    {
      foreach($cfield as $field_id => $type_and_value)
      {
        $value = $type_and_value['cf_value'];

        // do I need to update or insert this value?
        $sql = "/* $debugMsg */ SELECT value FROM {$this->tables['cfield_design_values']} " .
    		   " WHERE field_id={$field_id} AND	node_id={$node_id}";

        $result = $this->db->exec_query($sql);

        // max_length_value = 0 => no limit
        if( $this->max_length_value > 0 && tlStringLen($value) > $this->max_length_value)
        {
           $value = substr($value,0,$this->max_length_value);
        }

        $safe_value=$this->db->prepare_string($value);
        if($this->db->num_rows( $result ) > 0 && $value != "")
        {

          $sql = "/* $debugMsg */ UPDATE {$this->tables['cfield_design_values']} " .
                 " SET value='{$safe_value}' " .
    	         " WHERE field_id={$field_id} AND	node_id={$node_id}";
	      $this->db->exec_query($sql);
        }
	    else if ($this->db->num_rows( $result ) == 0 && $value != "")
        {
          	# Remark got from Mantis code:
  		    # Always store the value, even if it's the dafault value
  		    # This is important, as the definitions might change but the
  		    #  values stored with a bug must not change
  		    $sql = "/* $debugMsg */ INSERT INTO {$this->tables['cfield_design_values']} " .
  				   " ( field_id, node_id, value ) " .
  				   " VALUES	( {$field_id}, {$node_id}, '{$safe_value}' )";
		    $this->db->exec_query($sql);
        } 
        else if ($this->db->num_rows( $result ) > 0 && $value == "") {
  			$sql = "/* $debugMsg */ DELETE FROM {$this->tables['cfield_design_values']} " .
  				   " WHERE field_id={$field_id} AND	node_id={$node_id}";
		    $this->db->exec_query($sql);
  		}
        
      } //foreach($cfield
    } //if( !is_null($cfield) )

  } //function end



  /*
    function: remove_all_design_values_from_node
              remove the values of ALL custom fields linked to
              a node. (example test case 5555)

    args: $node_id: single value or array

    returns: -

    rev :
          20070102 - franciscom - $node_id can be an array

  */
  function remove_all_design_values_from_node($node_id)
  {
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	
    $sql = "/* $debugMsg */ DELETE FROM {$this->tables['cfield_design_values']} ";
    if( is_array($node_id) )
    {

      $sql .= " WHERE node_id IN(" . implode(",",$node_id) . ") ";
    }
    else
    {
      $sql .= " WHERE node_id={$node_id}";
    }

    $this->db->exec_query($sql);
  } //function end


  /*
    function: get_all
              get the definition of all custom field defined in the system,
              or all custom fields with id not included in $id2exclude.

    args: [$id2exclude]: array with custom field ids

    returns: hash:
             key: custom field id

  */
  function get_all($id2exclude=null)
  {
    $not_in_clause="";
    if( !is_null($id2exclude) )
    {
      $not_in_clause=" AND CF.id NOT IN (" .implode(',',$id2exclude) .") ";
    }
    $sql="SELECT CF.*,NT.description AS node_description,NT.id AS node_type_id " .
         " FROM {$this->object_table} CF, " .
         "     {$this->tables['cfield_node_types']} CFNT, " .
         "     {$this->tables['node_types']} NT " .
         " WHERE CF.id=CFNT.field_id " .
         " AND NT.id=CFNT.node_type_id " .
         $not_in_clause .
         " ORDER BY CF.name";
    // $map = $this->db->fetchArrayRowsIntoMap($sql,'id');
    $map = $this->db->fetchRowsIntoMap($sql,'id');
    return($map);
  }

  /*
    function: get_linked_to_testproject
              get definition of all custom fields linked to a test project.


    args: $tproject_id
          [$active]: if not null will add the following filter " AND CFTP.active={$active}"

    returns: hash:
             key: custom field id

    internal revision:
		20090717 - franciscom - added location to result recordset
  */
  function get_linked_to_testproject($tproject_id,$active=null)
  {
    $sql="SELECT CF.*,NT.description AS node_description,NT.id AS node_type_id, " .
         "       CFTP.display_order, CFTP.active, CFTP.location " .
         " FROM {$this->object_table} CF, " .
         "      {$this->tables['cfield_testprojects']} CFTP, " .
         "      {$this->tables['cfield_node_types']} CFNT, " .
         "      {$this->tables['node_types']} NT " .
         " WHERE CF.id=CFNT.field_id " .
         " AND   CF.id=CFTP.field_id " .
         " AND   NT.id=CFNT.node_type_id " .
         " AND   CFTP.testproject_id={$tproject_id} ";

    if( !is_null($active) )
    {
      $sql .= " AND CFTP.active={$active} ";
    }
    //$sql .= " ORDER BY display_order, CF.name";
    // BUGID 3555
   $sql .= " ORDER BY NT.description,CF.enable_on_design desc, " .
            "CF.enable_on_execution desc, " .
                "CF.enable_on_testplan_design desc,".
                "CFTP.display_order, CF.name";
    $map = $this->db->fetchRowsIntoMap($sql,'id');
    return($map);
  }


  /*
    function: link_to_testproject



    args: $tproject_id
          $cfields_id: array()

    returns: -
  */
	function link_to_testproject($tproject_id,$cfield_ids)
	{
		if(is_null($cfield_ids))
		{
			return;
        }

		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    	$tproject_info = $this->tree_manager->get_node_hierarchy_info($tproject_id);
		foreach($cfield_ids as $field_id)
		{
			$sql = "/* $debugMsg */ INSERT INTO {$this->tables['cfield_testprojects']} " .
			   	   " (testproject_id,field_id) " .
			   	   " VALUES({$tproject_id},{$field_id})";

			if ($this->db->exec_query($sql))
			{
				$cf = $this->get_by_id($field_id);
				if ($cf)
				{
					logAuditEvent(TLS("audit_cfield_assigned",$cf[$field_id]['name'],$tproject_info['name']),
								            "ASSIGN",$tproject_id,"testprojects");
			    }					            
			}
		}
	} //function end


  /*
    function: set_active_for_testproject
              set the value of active field


    args: $tproject_id
          $cfields_id: array()
          $active_val: 1/0

    returns: -
  */
	function set_active_for_testproject($tproject_id,$cfield_ids,$active_val)
	{
  		if(is_null($cfield_ids))
  		{
			return;
		}
		
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    	$tproject_info = $this->tree_manager->get_node_hierarchy_info($tproject_id);
		$auditMsg = $active_val ? "audit_cfield_activated" : "audit_cfield_deactivated";
		foreach($cfield_ids as $field_id)
		{
			$sql = "/* $debugMsg */ UPDATE {$this->tables['cfield_testprojects']} " .
				   " SET active={$active_val} " .
				   " WHERE testproject_id={$tproject_id} " .
				   " AND field_id={$field_id}";

			if ($this->db->exec_query($sql))
			{
				$cf = $this->get_by_id($field_id);
				if ($cf)
				{
					logAuditEvent(TLS($auditMsg,$cf[$field_id]['name'],$tproject_info['name']),
								        "SAVE",$tproject_id,"testprojects");
				}								        
			}
		}
	} //function end

 
  /**
   * unlink_from_testproject
   * remove custom field links from target test project
   * N.B.: following Mantis Bugtracking System model,
   *       this operation will NOR remove all values assigned to 
   *       these custom fields .
   *  
   * @param int $tproject_id
   * @param array $cfield_ids 
   *
   */
	function unlink_from_testproject($tproject_id,$cfield_ids)
  	{
	  	if(is_null($cfield_ids))
	  	{
			return;
        }
        
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
        // just for audit porpouses
		$tproject_info = $this->tree_manager->get_node_hierarchy_info($tproject_id);
		foreach($cfield_ids as $field_id)
		{
			// BUGID 0000677
			$sql = "/* $debugMsg */ DELETE FROM {$this->tables['cfield_testprojects']} " .
			       " WHERE field_id = {$field_id} AND testproject_id = {$tproject_id} ";
			if ($this->db->exec_query($sql))
			{
				$cf = $this->get_by_id($field_id);
				if ($cf)
				{
					logAuditEvent(TLS("audit_cfield_unassigned",$cf[$field_id]['name'],$tproject_info['name']),
		         					 "ASSIGN",$tproject_id,"testprojects");
		        } 					 
			}
		}
  	} //function end



  /*
    function: get_by_name
              get custom field definition

    args: $name: custom field name

    returns: hash
  */
	function get_by_name($name)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	  	$my_name=$this->db->prepare_string(trim($name));

	  	$sql="/* $debugMsg */  SELECT CF.*, CFNT.node_type_id,NT.description AS node_type" .
	  	     " FROM {$this->tables['custom_fields']} CF, {$this->tables['cfield_node_types']} CFNT," .
	  	     " {$this->tables['node_types']} NT" .
	  	     " WHERE CF.id=CFNT.field_id " .
	  	     " AND CFNT.node_type_id=NT.id " .
	  	     " AND name='{$my_name}' ";
    	return($this->db->fetchRowsIntoMap($sql,'id'));
  }

  /*
    function: get_by_id
              get custom field definition

    args: $id: custom field id

    returns: hash

  */
	function get_by_id($id)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	  	$sql="/* $debugMsg */ SELECT CF.*, CFNT.node_type_id" .
	  	     " FROM {$this->tables['custom_fields']}  CF, {$this->tables['cfield_node_types']} CFNT" .
	  	     " WHERE CF.id=CFNT.field_id " .
	  	     " AND   CF.id={$id} ";
    	return($this->db->fetchRowsIntoMap($sql,'id'));
	}

  /*
    function: get_available_item_type
              get information about what item type (testcase,testplan, etc)
              can use this custom field

    args: $id: custom field id

    returns: 

  */
	function get_available_item_type($id)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	  	$sql="/* $debugMsg */ SELECT CFNT.field_id,CFNT.node_type_id ".
	  	     " FROM {$this->tables['cfield_node_types']} CFNT, " .
	  	     "      {$this->tables['nodes_types']} NT " .
	  	     " WHERE NT.id=CFNT.node_type_id " .
	  	     " CFNt.field_id={$id} ";

    	return($this->db->fetchRowsIntoMap($sql,'field_id'));
	}

	/*
	 *
	 *	keys	name	-> trim will be applied
     *			label	-> trim will be applied
     *	   		type	-> intval() wil be applied
     *	   		possible_values
     *	   		show_on_design	-> trasformation on 1/0 using intval() [*]
     *	   		enable_on_design	-> [*]
     *	   		show_on_execute	-> [*]
     *	   		enable_on_execute	-> [*]
     *	   		show_on_testplan_design	-> [*]
     *	   		enable_on_testplan_design	-> [*]
     *	   		required	-> [*]
     *
	 */
	function sanitize($cf)
	{
		$safe = $cf;
		
		// remove the standard set of characters considered harmful
		// "\0" - NULL, "\t" - tab, "\n" - new line, "\x0B" - vertical tab
		// "\r" - carriage return
		// and spaces
		// fortunatelly this is trim standard behaviour
		$k2san = array('name','label');
		foreach($k2san as $key)
		{	
			$safe[$key] = $this->db->prepare_string(trim($cf[$key]));
		}	    
		
		// seems here is better do not touch.
	    $safe['possible_values'] = $this->db->prepare_string($cf['possible_values']);

		$onezero = array('required','show_on_design','enable_on_design','show_on_testplan_design',
						 'enable_on_testplan_design','show_on_execution','enable_on_execution');
	
		foreach($onezero as $key)
	    {
	    	$safe[$key] = (isset($cf[$key]) && intval($cf[$key])) > 0 ? 1 : 0;
	    }

	    $safe['type'] = intval($cf['type']);
		
		return $safe;
	}	

  /*
    function: create a custom field

    args: $hash:
          keys   name
                 label
                 type
                 possible_values
                 show_on_design
                 enable_on_design
                 show_on_execute
                 enable_on_execute
                 show_on_testplan_design
                 enable_on_testplan_design
                 node_type_id

    returns: -

    rev: 
    	 20101226 - franciscom - added default management to avoid crashes when used to import
    	 						 with files with older structure (missing fields) 
    	 20101219 - franciscom - BUGID 4088: Required parameter for custom fields
    	 20080810 - franciscom - BUGID 1650

  */
function create($cf)
{
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	$ret = array('status_ok' => 0, 'id' => 0, 'msg' => 'ko');

	// This method is also used to create CF using import.
	// To avoid crash if people import using old format (previous to 2.0), 
	// I will check for missing fields and add default value.
	//
	$default_values = array('required' => 0);
	$cf = array_merge($default_values,$cf);
	$safecf = $this->sanitize($cf);	

	$sql="/* $debugMsg */ INSERT INTO {$this->object_table} " .
	     " (name,label,type,possible_values, " .
	     "  show_on_design,enable_on_design, " .
	     "  show_on_testplan_design,enable_on_testplan_design, " .
	     "  show_on_execution,enable_on_execution,required) " .
	     " VALUES('" . $safecf['name'] . "','" . $safecf['label'] . "'," . 
	     		intval($safecf['type']) . ",'" . $safecf['possible_values'] . "', " .
	     "		{$safecf['show_on_design']},{$safecf['enable_on_design']}," .
	     "		{$safecf['show_on_testplan_design']},{$safecf['enable_on_testplan_design']}," .
	     "		{$safecf['show_on_execution']},{$safecf['enable_on_execution']}," . 
	     "		{$safecf['required']})";

    $result=$this->db->exec_query($sql);

   	if ($result)
  	{
  	  // at least for Postgres DBMS table name is needed.
  	  $field_id=$this->db->insert_id($this->object_table);

      $sql="INSERT INTO {$this->tables['cfield_node_types']} " .
           " (field_id,node_type_id) " .
           " VALUES({$field_id},{$cf['node_type_id']}) ";
      $result=$this->db->exec_query($sql);
    }

    if ($result)
	  {
       $ret = array('status_ok' => 1, 'id' => $field_id, 'msg' => 'ok');
    }
    return($ret);
  } //function end


  /*
    function: update a custom field

    args: $hash:
          keys   name
                 label
                 type
                 possible_values
                 show_on_design
                 enable_on_design
                 show_on_execute
                 enable_on_execute
                 show_on_testplan_design
                 enable_on_testplan_design
                 node_type_id
                 required

    returns: -
    
    @internal revision
    20101226 - franciscom - BUGID 4088: Required parameter for custom fields
    
  */
	function update($cf)
	{

		$safecf = $this->sanitize($cf);
		$sql =	"UPDATE {$this->tables['custom_fields']}  " .
			 	" SET	name='" . $safecf['name'] . "'," . 
			 	"		label='" . $safecf['label'] . "'," .
			 	"     	type={$safecf['type']}," .
			 	"		required={$safecf['required']}," .
			 	"		possible_values='" . $safecf['possible_values'] . "'," .
			 	"     	show_on_design={$safecf['show_on_design']}," .
			 	"     	enable_on_design={$safecf['enable_on_design']}," .
			 	"     	show_on_testplan_design={$safecf['show_on_testplan_design']}," .
			 	"     	enable_on_testplan_design={$safecf['enable_on_testplan_design']}," .
			 	"     	show_on_execution={$safecf['show_on_execution']}," .
			 	"     	enable_on_execution={$safecf['enable_on_execution']}" .
			 	" WHERE id={$safecf['id']}";
			 
			 
		$result = $this->db->exec_query($sql);

		if ($result)
		{
			$sql = 	"UPDATE {$this->tables['cfield_node_types']} " .
					" SET node_type_id={$safecf['node_type_id']}" .
					" WHERE field_id={$safecf['id']}";
			$result = $this->db->exec_query($sql);
		}
		return $result ? 1 : 0;
  } //function end


  /**
   * delete()
   * Will delete custom field definition and also ALL assigned values
   * If custom field is linked to test projects, these links must be removed
   *
   */
	function delete($id)
	{
        // Before deleting definition I need to remove values
        if( $this->is_used($id) )
        {
            $this->remove_all_scopes_values($id);
		}
		$linked_tprojects = $this->get_linked_testprojects($id);
		if( !is_null($linked_tprojects) && count($linked_tprojects) > 0 )
		{
		    $target=array_keys($linked_tprojects);
		    foreach($target as $tproject_id)
		    {
                $this->unlink_from_testproject($tproject_id,(array)$id);
		    }
		}
		
		$sql="DELETE FROM {$this->tables['cfield_node_types']} WHERE field_id={$id}";
		$result=$this->db->exec_query($sql);
		if($result)
		{
			$sql="DELETE FROM {$this->tables['custom_fields']} WHERE id={$id}";
			$result=$this->db->exec_query($sql);
		}
		return $result ? 1 : 0;
	}


  /*
    function: is_used

    args: $id: custom field id

    returns: 1/0
    
    rev: 20080810 - franciscom - BUGID 1650
  */
	function is_used($id)
	{
	  $sql="SELECT field_id FROM {$this->tables['cfield_design_values']} " .
	       "WHERE  field_id={$id} " .
	       "UNION " .
	       "SELECT field_id FROM {$this->tables['cfield_testplan_design_values']} " .
	       "WHERE  field_id={$id} " .
	       "UNION " .
	       "SELECT field_id FROM {$this->tables['cfield_execution_values']} " .
	       "WHERE  field_id={$id} ";
	  $result=$this->db->exec_query($sql);
	  return($this->db->num_rows( $result ) > 0 ? 1 : 0);
	} //function end

  /*
    function: whoIsUsingMe

    args: $id: custom field id

    returns:
  */
	function whoIsUsingMe($id)
	{
	  $sql=" SELECT field_id,name ".
	       " FROM {$this->tables['cfield_design_values']} CFDV, ".
	       "      {$this->tables['cfield_node_types']} CFNT, " .
	       "      {$this->tables['nodes_hierarchy']} NH " .
	       " WHERE CFDV.field_id=CFNT.field_id " .
	       " AND NH.id=CFDV.node_id " .
	       " CFDV.field_id={$id} ";
	} //function end




/*
    function: name_is_unique

    args: $id
          $name

    returns: 1 => name is unique
*/
function name_is_unique($id,$name)
{
    $cf=$this->get_by_name($name);
    $status=0;
    if( is_null($cf) || isset($cf[$id]) )
    {
       $status=1;
    }
    return($status);
} //function end



  # --------------------
	# Adapted from Mantis code
	# Prepare a string containing a custom field value for display
	# $p_field_def 		  definition of the custom field
	# $p_node_id	bug id to display the custom field value for
	#
	# [$p_value_field]: field id, to point to the field value in $p_field_def
	function string_custom_field_value( $p_field_def, $p_node_id,$p_value_field='value')
	{

		$t_custom_field_value=htmlspecialchars($p_field_def[$p_value_field]);

		switch ($this->custom_field_types[$p_field_def['type']])
  	{
			case 'email':
				return "<a href=\"mailto:$t_custom_field_value\">$t_custom_field_value</a>";
				break;

			case 'enum':
			case 'list':
			case 'multiselection list':
			case 'checkbox':
				return str_replace( '|', ', ', $t_custom_field_value );
				break;

			case 'date':
				if ($t_custom_field_value != null)
				{
				  // must remove %
				  $t_date_format=str_replace("%","",config_get( 'date_format'));
				  $xdate=date( $t_date_format, $t_custom_field_value);
					return  $xdate;
				}
				break ;

			case 'datetime':
				if ($t_custom_field_value != null)
				{
				    // must remove %
				    // $t_date_format=str_replace("%","",config_get( 'timestamp_format'));
                    // $datetime_format=$t_date_format;
                    $t_date_format=str_replace("%","",config_get( 'date_format'));
                    $cfg=config_get('gui');
                    $datetime_format=$t_date_format . " " .$cfg->custom_fields->time_format;
                    $xdate=date( $datetime_format, $t_custom_field_value);
					return  $xdate;
				}
				break ;


		  case 'text area':
                if ($t_custom_field_value != null)
			    {
					return nl2br($t_custom_field_value);
                }
        break;

			default:
			  // 20071027 - franciscom
			  // This code manages URLs
				return string_display_links( $t_custom_field_value );

				// return($t_custom_field_value);
		}
	}



  /*
    function: get_linked_cfields_at_execution
              returns information about custom fields that can be used
              at least at executed, with the value assigned (is any has been assigned).


    $tproject_id: needed because is possible to associate/link
                  a different set of custom field for every test project

    $enabled    : 1 -> get custom fields that are has been configured
                       to be shown during test case execution AND are enabled.

    [$node_type]: default: null
                  verbose id ('testcase', 'testsuite', etc) of a node type.
                  custom fields are linked also to different node types.
                  Example:
                  I can define a custom field "Aspect" with values
                  Performace, Usability and wnat use it only for test suites.

    [$node_id]: default: null
                identification of a node/element on node hierarchy.
                Needed when I want to get the value of custom fields
                linked to a node.
                Example:
                Have two test cases (ID:9999, ID:89800), and want to get
                the value assigned to custom field "Operating System".
                I will do two calls to this method.


    [execution_id]
    [testplan_id]
    [access_key]
    [location]

    returns: hash
             key: custom field id


    @internal Revisions:
   *    20100930 - asimon - added platform id to statement
        20100825 - eloff - added platform name to output
        20090717 - franciscom - added location argument
        20070526 - franciscom - changed order by clause

  */
  function get_linked_cfields_at_execution($tproject_id,$enabled,
                                           $node_type=null,$node_id=null,
                                           $execution_id=null,$testplan_id=null,
                                           $access_key='id',$location=null)
  {
    $base_values="CF.*,";
    $additional_join="";
    $additional_values="";
    $additional_filter="";
    $order_clause=" ORDER BY display_order,CF.id ";

    $fetchMethod='fetchRowsIntoMap';

    if( !is_null($node_type) )
    {
   		$hash_descr_id = $this->tree_manager->get_available_node_types();
        $node_type_id=$hash_descr_id[$node_type];

        $additional_join  .= " JOIN {$this->tables['cfield_node_types']} CFNT ON CFNT.field_id=CF.id " .
                               " AND CFNT.node_type_id={$node_type_id} ";
    }
    
    if( !is_null($node_id) && !is_null($execution_id) && !is_null($testplan_id) )
    {
        $additional_values .= ",CFEV.value AS value,CFEV.tcversion_id AS node_id";
        $additional_join .= " LEFT OUTER JOIN {$this->tables['cfield_execution_values']} CFEV ON CFEV.field_id=CF.id " .
                            " AND CFEV.tcversion_id={$node_id} " .
                            " AND CFEV.execution_id={$execution_id} " .
                            " AND CFEV.testplan_id={$testplan_id} ";
    }
    else
    {
        // This piece is useful for report implementation done by: Amit Khullar - amkhullar@gmail.com
        if( !is_null($testplan_id) )
        {
            $base_values ='';

            // asimon - 20100930 - added platform id to statement
            $additional_values .= ",CF.name,CF.label,CF.id,CFEV.value AS value,CFEV.tcversion_id AS node_id," .
                                  "EXECU.id AS exec_id, EXECU.tcversion_id,EXECU.tcversion_number," .
                                  "EXECU.execution_ts,EXECU.status AS exec_status,EXECU.notes AS exec_notes, " .
                                  "NHB.id AS tcase_id, NHB.name AS tcase_name, TCV.tc_external_id, " .
                                  "B.id AS builds_id,B.name AS build_name, U.login AS tester, " .
                                  "PLAT.name AS platform_name, COALESCE(PLAT.id,0) AS platform_id";
            
            $additional_join .= " JOIN {$this->tables['cfield_execution_values']} CFEV ON CFEV.field_id=CF.id " .
                                " AND CFEV.testplan_id={$testplan_id} " .
                                " JOIN {$this->tables['executions']} EXECU ON CFEV.tcversion_id = EXECU.tcversion_id " .
                                " AND CFEV.execution_id = EXECU.id " ;
            
            $additional_join .= " JOIN {$this->tables['builds']} B ON B.id = EXECU.build_id " .
                                " AND B.testplan_id = EXECU.testplan_id " ;

            $additional_join .= " JOIN {$this->tables['tcversions']} TCV ON TCV.version = EXECU.tcversion_number " .
			                          " AND TCV.id = EXECU.tcversion_id " ;
            
            $additional_join .= " JOIN {$this->tables['users']} U ON  U.id = EXECU.tester_id " .
                                " JOIN {$this->tables['nodes_hierarchy']} NHA ON NHA.id = EXECU.tcversion_id " .
                                " JOIN {$this->tables['nodes_hierarchy']} NHB ON NHB.id = NHA.parent_id  " ;

            // Use left join, if platforms is not used platform_name will become null
            $additional_join .= " LEFT JOIN {$this->tables['platforms']} PLAT ON EXECU.platform_id = PLAT.id";
            $order_clause="ORDER BY EXECU.tcversion_id,exec_status,exec_id";
            
            $fetchMethod='fetchArrayRowsIntoMap';
    
        }
    }

    if( !is_null($location) )
    {
    	$additional_filter .= " AND CF.id= " . intval($location) . " ";
    }

    $sql = "SELECT {$base_values} CFTP.display_order,CFTP.location" .
           $additional_values .
           " FROM {$this->tables['custom_fields']} CF " .
           " JOIN {$this->tables['cfield_testprojects']} CFTP ON CFTP.field_id=CF.id " .
           $additional_join .
           " WHERE CFTP.testproject_id={$tproject_id} " .
           " AND CFTP.active=1 " .
           " AND CF.enable_on_execution={$enabled} " .
           " AND CF.show_on_execution=1 {$additional_filter} {$order_clause} ";
   
    $map = $this->db->$fetchMethod($sql,$access_key);
    return $map;
  }




  /*
    function: execution_values_to_db
              write values of custom fields that are used at execution time.
              if record exists => UPDATE

    args: $hash: contains info about CF gathered at user interface.
                 (normally $_REQUEST variable)
                 key: custom_field_<field_type_id>_<cfield_id>.
                      Example custom_field_0_67 -> 0=> string field

          $node_id:
          $execution_id:
          $testplan_id:

          [$cf_map]:  hash -> all the custom fields linked and enabled
                              that are applicable to the node type of $node_id.

                            For the keys not present in $hash, we will write
                            an appropriate value according to custom field
                            type.

                            This is needed because when trying to udpate
                            with hash being $_REQUEST, $_POST or $_GET
                            some kind of custom fields (checkbox, list, multiple list)
                            when has been deselected by user.


          [hash_type]: default null, string that can be used to change how hash
                       is processed.
                       
    rev:
        20090727 - franciscom - added [hash_type], to reuse this method on API
        20070501 - franciscom - limiting lenght of value before writting
  */
  function execution_values_to_db($hash,$node_id,$execution_id,$testplan_id,
                                  $cf_map=null,$hash_type=null)
  {
    if( is_null($hash) && is_null($cf_map) )
    {
       return;
    }

    if( is_null($hash_type) )
    {
      $cfield=$this->_build_cfield($hash,$cf_map);
    }
    else
    {
      $cfield=$hash;
    }

    if( !is_null($cfield) )
    {
      foreach($cfield as $field_id => $type_and_value)
      {
        $value = $type_and_value['cf_value'];

        $where_clause = " WHERE field_id={$field_id} AND tcversion_id={$node_id} " .
 			            " AND execution_id={$execution_id} AND testplan_id={$testplan_id}" ;

        $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

        // do I need to update or insert this value?
        $sql = " SELECT value,field_id,execution_id " .
               " FROM {$this->tables['cfield_execution_values']} " . $where_clause;

        $rs = $this->db->get_recordset($sql); 			   

        // max_length_value = 0 => no limit
        if( $this->max_length_value > 0 && tlStringLen($value) > $this->max_length_value)
        {
           $value = substr($value,0,$this->max_length_value);
        }
        $safe_value=$this->db->prepare_string($value);

		// BUGID 3989
        if( count($rs) > 0 && $value != "")   //$this->db->num_rows($result) > 0 )
        {
          $sql = "UPDATE {$this->tables['cfield_execution_values']} " .
                 " SET value='{$safe_value}' " .
    	         $where_clause;
	      $this->db->exec_query($sql);
        }
        // BUGID 3989
        else if (count($rs) == 0 && $value != "")
        {

          # Remark got from Mantis code:
  		  # Always store the value, even if it's the default value
  		  # This is important, as the definitions might change but the
  		  #  values stored with a bug must not change
  		  $sql = "INSERT INTO {$this->tables['cfield_execution_values']} " .
  				 " ( field_id, tcversion_id, execution_id,testplan_id,value ) " .
  			     " VALUES	( {$field_id}, {$node_id}, {$execution_id}, {$testplan_id}, '{$safe_value}' )";
		  $this->db->exec_query($sql);

        // BUGID 3989
        } else if (count($rs) > 0 && $value == "") {
  			$sql = "/* $debugMsg */ DELETE FROM {$this->tables['cfield_execution_values']} " .
  				   $where_clause;
	        $this->db->exec_query($sql);
  		}

      } //foreach($cfield
    } //if( !is_null($cfield) )
  } //function end



  /*
    function: _build_cfield
              support function useful for method used to write CF values to db:
              - design_values_to_db()
              - execution_values_to_db()
              - testplan_design_values_to_db()

    args: $hash:
           key: custom_field_<field_type_id>_<cfield_id>[_<name_suffix>][_<date_part>].
                Example custom_field_0_67 -> 0=> string field
                
                In certain situation we can get:
                custom_field_0_67_234
                0 => string field
                234 => item owner of CF.
                       this happens when you can have multiple times same CF on a page, as happens
                       on execution page if configure TL to work on all test cases in test suite,
                       or when you use CF on testplan_design.
                                                
                To understand [<_date_part>] read below on "Notes on DATE PART - _build_cfield"

           value: can be an array, or a string depending the <field_type_id>

           $cf_map: hash
           key: cfield_id
           value: custom field definition data


    returns: hash or null.

             key: cfield_id
             value: hash ('type_id'  => field_type_id,
                          'cf_value' => value)

    rev: 
		20101025 - asimon - BUGID 3716: date pull downs changed to calendar interface
		20080816 - franciscom
         - added code to manange user defined (and code developed) Custom Fields.
           Important: solution is a mix of own ideas and Mantis 1.2.0a1 approach
         - added logic to manage datetime custom field type.  
  */
  function _build_cfield($hash,$cf_map)
  {
  	// BUGID 3930
	global $g_locales_date_format;
	$locale = (isset($_SESSION['locale'])) ? $_SESSION['locale'] : 'en_GB';
	$date_format = str_replace('%', '', $g_locales_date_format[$locale]);
  	
    // carved in the stone
    $html_date_input_suffix = array('input' => true,
                                    'hour' => true,
                                    'minute' => true,
                                    'second' => true);

    $cf_prefix=$this->name_prefix;
    $len_cfp = tlStringLen($cf_prefix);
    $cftype_pos=2;
    $cfid_pos=3;
    $cfield=null;

    // -------------------------------------------------------------------------
    if( !is_null($cf_map) )
    {
      foreach($cf_map as $key => $value)
      {
        $cfield[$key]=array("type_id"  => $value['type'],
                            "cf_value" => '');
      }
    }
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Overwrite with values if custom field id exist
    if( !is_null($hash) )
    {
      foreach($hash as $key => $value)
      {
        if( strncmp($key,$cf_prefix,$len_cfp) == 0 )
        {
          // Notes on DATE PART - _build_cfield
          // 
          // When using Custom Fields on Test Spec:
          // key has this format (for every type except date )
          // custom_field_0_10 for every type except for type date.
          //
          // For date custom fields:
          // custom_field_8_10_day, custom_field_8_10_month, custom_field_8_10_year
          //
          // After explode()
          // Position 2: CF type
          // Position 3: CF id
          // Position 4: only available for date CF, is date part indicator
          //
          // When using Custom Fields on Execution
          // another piece is added (TC id) then for a date CF, 
          // date part indicator is Position 5, instead of 4
          //
          // When using Custom Fields on Testplan Design 
          // another piece is added (testplan_tcversion.id) then for a date CF, 
          // date part indicator is Position 5, instead of 4
          //        	
          $dummy=explode('_',$key);
          $last_idx=count($dummy)-1;
          $the_value=$value;
          if( isset($html_date_input_suffix[$dummy[$last_idx]]) )
          {
            $the_value=array();
            if( isset($cfield[$dummy[$cfid_pos]]) )
            {
              $the_value=$cfield[$dummy[$cfid_pos]]['cf_value'];
            }
            $the_value[$dummy[$last_idx]]=$value;
          }
          $cfield[$dummy[$cfid_pos]]=array("type_id"  => $dummy[$cftype_pos],
                                           "cf_value" => $the_value);
        }
      }
    } //if( !is_null($hash) )

    if( !is_null($cfield) )
    {
      foreach($cfield as $field_id => $type_and_value)
      {
        $value = $type_and_value['cf_value'];
        $verbose_type=trim($this->custom_field_types[$type_and_value['type_id']]);

        switch ($verbose_type)
        {
          case 'multiselection list':
          case 'checkbox':
            if( count($value) > 1)
            {
              $value=implode('|',$value);
            }
            else
            {
              $value=is_array($value) ? $value[0] : $value;
            }
            $cfield[$field_id]['cf_value']=$value;
          break;

          case 'date':
          	if (($value == 0) || ($value == ''))
            {
              $cfield[$field_id]['cf_value']='';
            }
            else
            {
				$parsed_value = split_localized_date($value['input'], $date_format);
				if($parsed_value != null) {
					$parsed_value = mktime(0, 0, 0, $parsed_value['month'], $parsed_value['day'], $parsed_value['year']);
					$cfield[$field_id]['cf_value'] = $parsed_value;
				} else {
					$cfield[$field_id]['cf_value']='';
				}
			}
          break;
          
          case 'datetime':
          	
			if ($value['input'] == '') {
              $cfield[$field_id]['cf_value']='';
            }
            else
            {
            	$parsed_value = split_localized_date($value['input'], $date_format);
            	if($parsed_value != null) {
            		if($value['hour'] == -1 || $value['minute'] == -1 || $value['second'] == -1) {
            			$value['hour'] = $value['minute'] = $value['second'] = 0;
            		}
            		$cfield[$field_id]['cf_value'] = mktime($value['hour'], $value['minute'], $value['second'],
            	                                            $parsed_value['month'], $parsed_value['day'], 
            	                                            $parsed_value['year']);
            	} else {
            		$cfield[$field_id]['cf_value']='';
            	}
            }
          break;         

          default:
            $dynamic_call='build_cfield_' . str_replace(' ', '_', $verbose_type);
            if( function_exists($dynamic_call) )
            {
                $cfield[$field_id]['cf_value']=$dynamic_call($value);      
            }
            else if( method_exists($this,$dynamic_call) )
            {
                $cfield[$field_id]['cf_value']=$this->$dynamic_call($value);      
            }
            else
            {
                $cfield[$field_id]['cf_value']=$value;
            }    
          break;

        }
      } // foreach
    }

    return($cfield);
 } // function end






 /*
   function: set_display_order

   args :  $tproject_id: needed because is possible to associate/link
                         a different set of custom field for every test project
           $map_field_id_display_order



   returns:

 */
 function set_display_order($tproject_id, $map_field_id_display_order)
 {
 	$tproject_info = $this->tree_manager->get_node_hierarchy_info($tproject_id);
    foreach($map_field_id_display_order as $field_id => $display_order)
    {
		$sql = "UPDATE {$this->tables['cfield_testprojects']}  " .
		      " SET display_order=" . intval($display_order) .
		      " WHERE testproject_id={$tproject_id} AND field_id={$field_id} ";
		$this->db->exec_query($sql);
    }
	if ($tproject_info)
	{
		logAuditEvent(TLS("audit_cfield_display_order_changed",$tproject_info['name']),
		                  "SAVE",$tproject_id,"testprojects");
	}	
 } // function end


/**
 * set value of location attribute for one or multiple custom fields.
 *
 * 
 */
 function setDisplayLocation($tproject_id, $field_id_location)
 {
 	$tproject_info = $this->tree_manager->get_node_hierarchy_info($tproject_id);
    foreach($field_id_location as $field_id => $location)
    {
		$sql = "UPDATE {$this->tables['cfield_testprojects']}  " .
		      " SET location=" . intval($location) .
		      " WHERE testproject_id={$tproject_id} AND field_id={$field_id} ";
		$this->db->exec_query($sql);
    }
	if ($tproject_info)
	{
		logAuditEvent(TLS("audit_cfield_location_changed",$tproject_info['name']),
		                  "SAVE",$tproject_id,"testprojects");
	}	
 } // function end


 # code from mantis helper_api.php
 # --------------------
 # returns a tab index value and increments it by one.  This is used to give sequential tab index on
 # a form.
 function helper_get_tab_index_value() {
	 static $tab_index = 0;
	 return ++$tab_index;
 }

 # --------------------
 # returns a tab index and increments internal state by 1.  This is used to give sequential tab index on
 # a form.  For example, this function returns: tabindex="1"
 function helper_get_tab_index() {
	 return 'tabindex="' . helper_get_tab_index_value() . '"';
 }



/**
 * Retrieves the XML-RPC Server Parameters specified through custom fields.
 * 
 * @param integer $node_id Accepts current node id from nodes hierarchy level
 * @return mixed An array of config params if found, else returns null
 *
 * @internal rev:
 * 
 * 20110123 - franciscom -	need refactoring after we have choose to link custom field
 *							values to test case version not to test case
 *
 * 20071102 - franciscom - refactoring
 * 200710 - creation - Swanand
 **/
function getXMLRPCServerParams($node_id)
{
	static $node_type;
	
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

	$srv_cfg = new stdClass();
	
	if( is_null($node_type) )
	{
		$node_type=$this->tree_manager->get_available_node_types();
	}
		
	$node_info=$this->tree_manager->get_node_hierarchy_info($node_id);
	$ret=null;
	
	if( !is_null($node_info) )
	{
		$prefix = "";
		$ret = array('xml_server_host' => null,	'xml_server_port' => null,
					 'xml_server_path' => null);
	
	
		if( $node_info['node_type_id'] == $node_type['tcversion'])
		{
			$prefix = "tc_";
		}
		$srv_cfg->host = $prefix . "server_host";
		$srv_cfg->port = $prefix . "server_port";
		$srv_cfg->path = $prefix . "server_path";
		
		$sql = 	" /* $debugMsg */ SELECT cf.name, cfdv.value " .
				" FROM {$this->tables['cfield_design_values']} cfdv," .
				" {$this->tables['custom_fields']}  cf " .
				" WHERE cfdv.field_id = cf.id AND cfdv.node_id = {$node_id}";
		
		$server_info = $this->db->fetchRowsIntoMap($sql,'name');
		// $server_cfg_is_ok=0;
		$server_use_host_port=0;
		$server_use_path=0;
		
		if( (isset($server_info[$srv_cfg->host]) && $server_info[$srv_cfg->host]['value'] != "") &&
			(isset($server_info[$srv_cfg->port]) && $server_info[$srv_cfg->port]['value'] != "") )
		{
			// $server_cfg_is_ok=1;
			$ret['xml_server_host'] = $server_info[$srv_cfg->host]['value'];
			$ret['xml_server_port'] = $server_info[$srv_cfg->port]['value'];
		}
		else if (isset($server_info[$srv_cfg->path]) && $server_info[$srv_cfg->path]['value'] != "")
		{
			// $server_cfg_is_ok=1;
			$ret['xml_server_path'] = $server_info[$srv_cfg->path]['value'];
		}
		else
		{
			// 20110123 - franciscom
			// At time of initial development this was thinked to try to get
			// server info from Test Suite.
			// Because with TL 1.9.x when working with test case  we will receive 
			// Test Case Version ID, instead of Test Case ID (1.8.x), we will do 
			// a call to reach Test Case and then another to reach Test Suite
			// 
			//
			if($node_info['parent_id'] != "")
			{
				$ret = $this->getXMLRPCServerParams($node_info['parent_id']);
			}
		}
	} // if( !is_null($node_info) )
	
	return $ret;
} //function end


  /*
    function: testplan_design_values_to_db
              write values of custom fields that are used at testplan design time.

    args: $hash: contains info about CF gathered at user interface.
                 (normally $_REQUEST variable)
                 key: custom_field_<field_type_id>_<cfield_id>.
                      Example custom_field_0_67 -> 0=> string field

          $node_id: Remember that this CF are used to extend information
                    on test cases (tcversions) linked to test plans.
                    Then node_id can not point to other type of node than test case version,
                    then node_id will contain a tcversion_id.
                    
                    I have leave this argument to 
          
          
          
          $link_id: Remember that this CF are used to extend information
                    on test cases (tcversions) linked to test plans.
                    Link information is store in testplan_tcversions table,
                    $link_id points to this link (testplan_tcversions.id field)

          [$cf_map]:  hash -> all the custom fields linked and enabled
                              that are applicable to the node type of $node_id.

                              For the keys not present in $hash, we will write
                              an appropriate value according to custom field
                              type.
                              This is needed because when trying to udpate
                              with hash being $_REQUEST, $_POST or $_GET
                              some kind of custom fields (checkbox, list, multiple list)
                              when has been deselected by user.
                              
          [$hash_type]:  NEED TO BE COMMENTED
                         

    rev:
  */
  function testplan_design_values_to_db($hash,$node_id,$link_id,$cf_map=null,$hash_type=null)
  {
	if( is_null($hash) && is_null($cf_map) )
	{
	   return;
	}
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
	$cfield = is_null($hash_type) ? $this->_build_cfield($hash,$cf_map) : $hash;
	if( !is_null($cfield) )
	{
	  foreach($cfield as $field_id => $type_and_value)
	  {
	  	// echo "DEBUG: \$field_id:$field_id - \$link_id:$link_id<br>";
	    $value = $type_and_value['cf_value'];

	    // do I need to update or insert this value?
	    $sql = "SELECT value FROM {$this->tables['cfield_testplan_design_values']} " .
			   " WHERE field_id={$field_id} AND	link_id={$link_id}";
	
	    $result = $this->db->exec_query($sql);
	
	    // max_length_value = 0 => no limit
	    if( $this->max_length_value > 0 && tlStringLen($value) > $this->max_length_value)
	    {
	       $value = substr($value,0,$this->max_length_value);
	    }
	
	    $safe_value=$this->db->prepare_string($value);
		// BUGID 3989
	    if($this->db->num_rows( $result ) > 0 && $value != "")
	    {
	
	      $sql = "UPDATE {$this->tables['cfield_testplan_design_values']} " .
	             " SET value='{$safe_value}' " .
			     " WHERE field_id={$field_id} AND	link_id={$link_id}";
		    $this->db->exec_query($sql);
	    }
	    // BUGID 3989
	    else if ($this->db->num_rows( $result ) == 0 && $value != "")
	    {
	      # Remark got from Mantis code:
		    # Always store the value, even if it's the dafault value
		    # This is important, as the definitions might change but the
		    #  values stored with a bug must not change
		    $sql = "INSERT INTO {$this->tables['cfield_testplan_design_values']} " .
				   " ( field_id, link_id, value ) " .
				   " VALUES	( {$field_id}, {$link_id}, '{$safe_value}' )";
		    $this->db->exec_query($sql);
	    // BUGID 3989
        } else if ($this->db->num_rows( $result ) > 0 && $value == "") {
  			$sql = "/* $debugMsg */ DELETE FROM {$this->tables['cfield_testplan_design_values']} " .
  				   " WHERE field_id={$field_id} AND	link_id={$link_id}";
  			$this->db->exec_query($sql);
  		}

	  } //foreach($cfield
	} //if( !is_null($cfield) )

  } //function end


  
  /*
    function: get_linked_cfields_at_testplan_design
              returns information about custom fields that can be used
              at least at testplan design time (test case assignment), 
              with the value assigned (is any has been assigned).


    $tproject_id: needed because is possible to associate/link
                  a different set of custom field for every test project

    $enabled    : 1 -> get custom fields that are has been configured
                       to be shown during test case execution AND are enabled.

    [$node_type]: default: null
                  verbose id ('testcase', 'testsuite', etc) of a node type.
                  custom fields are linked also to different node types.
                  Example:
                  I can define a custom field "Aspect" with values
                  Performace, Usability and wnat use it only for test suites.

    [$node_id]: default: null
                identification of a node/element on node hierarchy.
                Needed when I want to get the value of custom fields
                linked to a node.
                Example:
                Have two test cases (ID:9999, ID:89800), and want to get
                the value assigned to custom field "Operating System".
                I will do two calls to this method.

                IMPORTANT:
                Fot testplan_design Custom Field this will be a TCVERSION_ID,
                not a TESTCASE_ID


    [link_id]: points to testplan_tcversions.id field
    [testplan_id]


    returns: hash
             key: custom field id
             
             

  */
  function get_linked_cfields_at_testplan_design($tproject_id,$enabled,
                                                 $node_type=null,$node_id=null,
                                                 $link_id=null,$testplan_id=null,$access_key = 'id')
  {
    $additional_join="";
    $additional_values="";
    $additional_filter="";
    
    $order_by_clause = " ORDER BY display_order,CF.id ";
    $fetchMethod = 'fetchRowsIntoMap';

    if( !is_null($node_type) )
    {
   		$hash_descr_id = $this->tree_manager->get_available_node_types();
        $node_type_id=$hash_descr_id[$node_type];

        $additional_join  .= " JOIN {$this->tables['cfield_node_types']} CFNT ON CFNT.field_id=CF.id " .
                           " AND CFNT.node_type_id={$node_type_id} ";
    }
    // }
    
    //-amitkhullar - Created this logic to get the linked tcversions for a testplan 
    //                 that have custom field values at test plan level - BUGID 2410
    if( is_null($link_id) && !is_null($testplan_id))
    {
        $additional_values .= ",CFTDV.value AS value, CFTDV.link_id AS node_id, " . 
                              "NHB.id AS tcase_id, NHB.name AS tcase_name, " .
                              "TCV.tc_external_id ";
                               //"TCV.tc_external_id, EXECU.status ";
                               
        $additional_join .= "JOIN {$this->tables['testplan_tcversions']} TPTC" .
                          " ON TPTC.testplan_id = {$testplan_id}" .
        				  " JOIN {$this->tables['cfield_testplan_design_values']} CFTDV " .
                          " ON CFTDV.field_id=CF.id " .
                          " AND CFTDV.link_id = TPTC.id ";
        
        $additional_join .= " JOIN {$this->tables['tcversions']} TCV ON TCV.id = TPTC.tcversion_id " .
		                    " AND TCV.id = TPTC.tcversion_id " .
         					" JOIN {$this->tables['nodes_hierarchy']} NHA ON NHA.id = TPTC.tcversion_id " .
                            " JOIN {$this->tables['nodes_hierarchy']} NHB ON NHB.id = NHA.parent_id  " ;
        
        //$additional_join .= " JOIN executions EXECU on TPTC.tcversion_id = EXECU.tcversion_id  ";
        
        $order_by_clause = " ORDER BY node_id,display_order,CF.id "; 
        $fetchMethod = 'fetchArrayRowsIntoMap';
        $access_key = 'node_id';
        
    }

    elseif( !is_null($link_id) )
    {
        $additional_values .= ",CFTDV.value AS value, CFTDV.link_id AS node_id";
        $additional_join .= " LEFT OUTER JOIN {$this->tables['cfield_testplan_design_values']} CFTDV " .
                            " ON CFTDV.field_id=CF.id " .
                            " AND CFTDV.link_id={$link_id} ";
    }

    
    
    $sql="SELECT CF.*,CFTP.display_order" .
         $additional_values .
         " FROM {$this->tables['custom_fields']} CF " .
         " JOIN {$this->tables['cfield_testprojects']} CFTP ON CFTP.field_id=CF.id " .
         $additional_join .
         " WHERE CFTP.testproject_id={$tproject_id} " .
         " AND   CFTP.active=1     " .
         " AND   CF.enable_on_testplan_design={$enabled} " .
         
         // 20090523 - franciscom 
         // missing refactoring when changing custom field management
         // " AND   CF.show_on_testplan_design=1 " .
         $order_by_clause;
    $map = $this->db->$fetchMethod($sql,$access_key);
    return($map);
  }

  /*
    function: string_input_radio
              returns an string with the html needed to display radio custom field.
              Is normally called by string_custom_field_input()

    args: p_field_def: contains the definition of the custom field
                       (including it's field id)

          p_input_name: html input name
          
          p_custom_field_value: html input value
                                htmlspecialchars() must be applied to this
                                argument by caller.

    returns: html string

    Development Note: based on Mantis 1.2.0a1 code
  
    @internal revisions 
         
  */
  function string_input_radio($p_field_def, $p_input_name, $p_custom_field_value) 
  {
    $str_out='';
    $t_values = explode( '|', $p_field_def['possible_values']);                                        
    $t_checked_values = explode( '|', $p_custom_field_value );                                         
    $required = $p_field_def['required'] ? ' class="required" ' : ' class="" ';

    foreach( $t_values as $t_option )                                                                  
    {                                                                                                  
      $str_out .= '<input type="radio" ' . $required . 'name="' . $p_input_name . '[]"' .
      			  'id="' . $p_input_name . '[]"' ;
      			                                  
      if( in_array( $t_option, $t_checked_values ) )                                                   
      {                                                                                                
    	  $str_out .= ' value="' . $t_option . '" checked="checked">&nbsp;' . $t_option . '&nbsp;&nbsp;';
      }                                                                                                
      else                                                                                             
      {                                                                                                
    	  $str_out .= ' value="' . $t_option . '">&nbsp;' . $t_option . '&nbsp;&nbsp;';                  
      }                                                                                                
    }
    return $str_out;
  }               

  /*
    function: build_cfield_radio
              support function useful for method used to write radio CF values to db.
              Is normally called by _build_cfield()
              
    args: custom_field_value: value to be converted to be written to db.
    
    returns: value converted
    
    rev: 20080816 - franciscom

  */
  function build_cfield_radio($custom_field_value) 
  {
      if( count($custom_field_value) > 1)
      {
        $value=implode('|',$custom_field_value);
      }
      else
      {
        $value=is_array($custom_field_value) ? $custom_field_value[0] :$custom_field_value;
      }
      return $value;
  }


/*
    function: string_input_string
              returns an string with the html needed to display custom field of type:
              string, email, numeric, float
               
              Is normally called by string_custom_field_input()

    args: p_field_def: contains the definition of the custom field
                       (including it's field id)

          p_input_name: html input name
          
          p_custom_field_value: html input value
                                htmlspecialchars() must be applied to this
                                argument by caller.
          
          p_size: html input size

    returns: html string
  
         
  */
  function string_input_string($p_field_def, $p_input_name, $p_custom_field_value, $p_size) 
  {
    $required = $p_field_def['required'] ? ' class="required" ' : ' class="" ';
 	$str_out='';
    $size = intval($p_size) > 0 ? $p_size : self::DEFAULT_INPUT_SIZE;
  	$str_out .= "<input type=\"text\" name=\"{$p_input_name}\" id=\"{$p_input_name}\" size=\"{$size}\" {$required} ";
	if( 0 < $p_field_def['length_max'] )
	{
	  $str_out .= ' maxlength="' . $p_field_def['length_max'] . '"';
	}
	else
	{
	   $str_out .= ' maxlength="255"';
	}
	$str_out .= ' value="' . $p_custom_field_value .'"></input>';
    return $str_out;
  }               



/**
 * exportValueAsXML
 * generate XML with custom field name, and custom field value
 * useful on export to XML method for items that can have custom fields,
 * example: test cases, test suites, req specification, etc.
 *
 * @param map $cfMap: key: custom file ID, value: map with at least keys 'name', 'value'
 *
 */
 function exportValueAsXML($cfMap)
 {
    $cfRootElem = "<custom_fields>\n{{XMLCODE}}\n</custom_fields>";
    $cfElemTemplate = "\t" . "<custom_field>\n\t\t<name><![CDATA[||NAME||]]></name>\n\t\t" .
	                           "<value><![CDATA[||VALUE||]]></value>\n" .
	                  "\t" . "</custom_field>";
    $cfDecode = array ("||NAME||" => "name","||VALUE||" => "value");
	$cfXML = exportDataToXML($cfMap,$cfRootElem,$cfElemTemplate,$cfDecode,true);
  	return $cfXML; 
 }


/**
 * remove_all_scopes_values
 * For a given custom field id remove all assigned values in any scope 
 *
 * @param int $id: custom field id
 * 
 * 
 *
 *
 */
function remove_all_scopes_values($id)
{
    // some sort of blind delete
    $sql=array();
    $sql[]="DELETE FROM {$this->tables['cfield_design_values']} WHERE field_id={$id} ";
    $sql[]="DELETE FROM {$this->tables['cfield_execution_values']} WHERE field_id={$id} ";
    $sql[]="DELETE FROM {$this->tables['cfield_testplan_design_values']} WHERE field_id={$id} ";
  
    foreach($sql as $s)
    {
        $this->db->exec_query($s);        
    }
}

/**
 * get_linked_testprojects
 * For a given custom field id return all test projects where is linked.
 *
 * @param int $id: custom field id
 *
 */
function get_linked_testprojects($id)
{
    $sql=" SELECT NH.id, NH.name " .
         " FROM {$this->tables['cfield_testprojects']} CFTP, {$this->tables['nodes_hierarchy']} NH " .
         " WHERE CFTP.testproject_id=NH.id " .
         " AND CFTP.field_id = {$id} ORDER BY NH.name ";

    $rs=$this->db->fetchRowsIntoMap($sql,'id');
    return $rs;
}


/**
 * @param string node type in verbose form. Example 'testcase'
 *
 * returns map with key: verbose location (see custom field class $locations)
 *                  value: array with fixed key 'location'
 *                         value: location code
 *
 */
static function buildLocationMap($nodeType)
{
	$locationMap = null;
  $dummy = self::getLocations();
	$verboseLocationCode = array_flip($dummy[$nodeType]);
	if( !is_null($verboseLocationCode) && count($verboseLocationCode) > 0 )
	{
		foreach($verboseLocationCode as $key => $value)
		{
			$locationMap[$key]['location']=$value;
		}
	}	     
  return $locationMap; 
}


/**
 * @param int linkID: how is used depends on $options['scope']
 *                    $options['scope']=design => node_id
 *                    $options['scope']=testplan_design => feature_id (see testplan_tcversions table)
 *                    $options['scope']=execution => execution_id
 *
 */
function getByLinkID($linkID, $options=null)
{
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

	$my['options'] = array('scope' => 'design', 'output' => 'field_id'); 	
	$my['options'] = array_merge($my['options'], (array)$options);

 	switch($my['options']['output'])
 	{
 		case 'field_id':
			 $sql = "/* debugMsg */ SELECT field_id FROM ";
 		break;

 		case 'full':
			 $sql = "/* debugMsg */ SELECT * FROM ";
 		break;
 		
 	}
 	 
	switch($my['options']['scope'])
	{
		case 'design':
			 $sql .= " {$this->tables['cfield_design_values']} " .
			         " WHERE node_id = {$linkID} ";
		break;
		
		case 'testplan_design':
			 $sql .= " {$this->tables['cfield_testplan_design_values']} " .
			         " WHERE feature_id = {$linkID} ";
		break;
		
		case 'execution':
			 $sql .= " {$this->tables['cfield_execution_values']} " .
			         " WHERE execution_id = {$linkID} ";
		break;
	
	}
	$rs = $this->db->get_recordset($sql);
	
	
    return $rs; 
}



/**
 * buildHTMLInputName
 *
 */
function buildHTMLInputName($cf,$name_suffix)
{
	return "{$this->name_prefix}{$cf['type']}_{$cf['id']}{$name_suffix}";
}



/**
 * 
 *
 */
function html_table_inputs($cfields_map,$name_suffix='',$input_values=null)
{
	$cf_smarty = '';
    if(!is_null($cfields_map))
    {
		$cf_map = $this->getValuesFromUserInput($cfields_map,$name_suffix,$input_values);
    	$NO_WARNING_IF_MISSING=true;
    	$cf_smarty = "<table>";
    	foreach($cf_map as $cf_id => $cf_info)
    	{
            $label=str_replace(TL_LOCALIZE_TAG,'',
                               lang_get($cf_info['label'],null,$NO_WARNING_IF_MISSING));


	     	// IMPORTANT NOTICE
	     	// assigning an ID with this format is CRITIC to Javascript logic used
	     	// to validate input data filled by user according to CF type
			// extract input html id
			// Want to give an html id to <td> used as labelHolder, to use it in Javascript
			// logic to validate CF content
			$cf_html_string = $this->string_custom_field_input($cf_info,$name_suffix);
			$dummy = explode(' ', strstr($cf_html_string,'id="custom_field_'));
	     	$td_label_id = str_replace('id="', 'id="label_', $dummy[0]);           
	     	
    		$required_sign = $cf_info['required'] ? ' (*) ' : '  ';
    		$required_hint = $cf_info['required'] ? ' title="required field" ' : '  ';
    		$cf_smarty .= "<tr><td class=\"labelHolder\" {$td_label_id} {$required_hint} > " . $required_sign .
    					  htmlspecialchars($label) . ":</td><td>" .
    			          $this->string_custom_field_input($cf_info,$name_suffix) .
    					  "</td></tr>\n";
    	}
    	$cf_smarty .= "</table>";
    }
    return $cf_smarty;

	

}


/**
 * 
 *
 */
function getValuesFromUserInput($cf_map,$name_suffix='',$input_values=null)
{
 	if( !is_null($input_values) )
    {
		foreach($cf_map as &$cf_info)
		{
			$value=null;
			$cf_info['html_input_name'] = $this->buildHTMLInputName($cf_info,$name_suffix);
			if (isset($input_values[$cf_info['html_input_name']])) 
			{
				$value = $input_values[$cf_info['html_input_name']];
			} 
			else if (isset($cf_info['value'])) 
			{
				$value = $cf_info['value'];
			}
			$verbose_type = trim($this->custom_field_types[$cf_info['type']]);
			if ($verbose_type == 'date') 
			{
			    // if cf is a date field, convert the three given values to unixtime format
				$kd = array();
				$kd['day'] = array('input' => $cf_info['html_input_name'] . '_day', 'value' => -1);
				$kd['month'] = array('input' => $cf_info['html_input_name'] . '_month', 'value' => -1);
				$kd['year'] = array('input' => $cf_info['html_input_name'] . '_year', 'value' => -1);
				
				$doIt = true;
				foreach($kd as &$date_part)
				{
					if( !isset($input_values[$date_part['input']]) )
					{
						$doIt = false;
						break;
					}
					$date_part['value'] = $input_values[$date_part['input']];
		
				}
			    if ($doIt)
			    {
			     	$value = mktime(0, 0, 0, $kd['month']['value'],$kd['day']['value'], $kd['year']['value']);
			    }
			}
			
			if (!is_null($value) && is_array($value)){
			    $value = implode("|", $value);
			}
			
			$cf_info['value'] = $value;
		}
    }
    return $cf_map;
}


/**
 * given a CF id and value, returns all node_id that has requested node type
 *
 * Important Notice: for Test Cases and Requirements (where CF are saved at Version level)
 *					 we have to understand that we use 'testcase' but the real type of node_id
 *					 will be tcversion.
 *
 * @param int $id Custom Field ID
 * @param string $value Custom Field Value
 * @param string $node_type verbose node type (see tree.class.php)
 * @param map $options default -> null , get defaults inside method
 *
 * @return map key: depends of options 
 *
 * @internal revision
 * 20101110 - franciscom - created as part of refactoring related to BUGID 3843	
 *
 */
function get_linked_items_at_design($id,$value,$node_type,$options=null)
{
	$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

	$my['opt'] = array('access_key' => 'node_id', 'secondary:_access_key' => null,
					   'cumulative' => !database::CUMULATIVE);
	$my['opt'] = array_merge($my['opt'], (array)$options);
  	
    $hash_descr_id = $this->tree_manager->get_available_node_types();
    $node_type_id=$hash_descr_id[$node_type];

    $safe_value = $this->db->prepare_string($value);

    $sql="/* $debugMsg */ SELECT CF.name,CFDV.value,CFDV.node_id AS node_id,NH.parent_id" .
         " FROM {$this->object_table} CF " .
		 " JOIN {$this->tables['cfield_node_types']} CFNT ON CFNT.field_id=CF.id " .
      	 " JOIN {$this->tables['cfield_design_values']} CFDV ON CFDV.field_id=CF.id " .
      	 " JOIN {$this->tables['nodes_hierarchy']} NH ON NH.id=CFDV.node_id " .
         " WHERE CFNT.node_type_id={$node_type_id} " .
         " AND CFDV.value = '{$safe_value}' ";

	if( is_null($my['opt']['secondary_access_key']) ) 
	{
    	$rs = $this->db->fetchRowsIntoMap($sql,$my['opt']['access_key'],$my['opt']['cumulative']);
    }
    else
    {
    	$rs = $this->db->fetchMapRowsIntoMap($sql,$my['opt']['access_key'],
    										 $my['opt']['secondary_access_key'],$my['opt']['cumulative']);
    }
    return($rs);
}


} // end class
?>
