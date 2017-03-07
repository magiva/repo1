<?php
/********************************************************************
Version     :	1.0.10
Author      :   Mark Richardson
Purpose     :   b2b>iip>icm comms with inin and osc integration
				model built for integration as an include and as a posted service
				TDD: https://service.projectplace.com/pp/pp.cgi/r1350745059
Date        :   Feb 2017

$_GET[‘action’]
  retrieve  	include POST string with a group of key-value pairs with field name as the key and the accompanying value.
  				extensions: verbose=1, format=xml or json, perf_data=1, curl_data=1
  save      	include POST string with a group of key value pairs with field name as key and the accompanying value
  				extensions: none
  lookup    	include POST string with a group of key value pairs with field name as key and the accompanying value (searchSe=true & isPhone=TRUE)
  				extensions: perf_data=1, curl_data=1
  test			returns Posed and Url parameters, no data comms
  				extensions: none
  bypass		=1 return vanilla data from icmModel
  				extensions: format=json or xml
  - Extensions:
  	perf_data	=1 to append peformance data to a retrieve action
  	curl_data	=1 to append curl_data to a retrieve action  
*********************************************************************/
$ip_dbreq = true;
if (!defined('DOCROOT')) define('DOCROOT', get_cfg_var('doc_root'));
if (!defined('CPCORE')) define('CPCORE', DOCROOT."/cp/core/framework/3.3.3");

require_once('include/init.phph');
//require_once (DOCROOT . '/include/services/AgentAuthenticator.phph');
require_once(DOCROOT . '/include/ConnectPHP/Connect_init.phph' );
require_once(DOCROOT . '/include/config/config.phph' );
if (!function_exists("\curl_init")) \load_curl();

initConnectAPI("api_admin","Api_admin1");

$ext_call=true;  // cwsmodel needs this set to authenticate correctly otherwise it auths as a batchjob
require_once(DOCROOT . "/custom/cwsmodel.php");

use RightNow\Connect\v1_3 as RNCPHP;
use RightNow\Utils\Config as C_CONFIG;

list ($common_cfgid, $rnw_common_cfgid, $rnw_ui_cfgid, $custom_cfgid) = msg_init($p_cfgdir, 'config', array('common', 'rnw_common', 'rnw_ui', 'custom'));
list ($common_mbid, $rnw_mbid) = msg_init($p_cfgdir, 'msgbase', array('common', 'rnw'));

header('Content-type: text/html; charset=utf-8');  // reset output from included classes above for testing and errors

/*********************************************************************/

$icmModel             = new icmModel();
$icmModel->db_name    = cfg_get($common_cfgid, DB_NAME);

// action as specified in the get parameter
Switch ($icmModel->inputAction){
    case "retrieve": $icmModel->retrieveContacts();
        break;
    case "mark": $icmModel->mark();
        break;
    case "test": $icmModel->test();
        break;
    case "save": $icmModel->savePostedContact();
        break;
    case "lookup": $icmModel->inboundLookup();
        break;
    case "bypass": $icmModel->bypass();
        break;
    default:
        $this->return_message("","", "available action not specified");
}

class icmModel {
    // POST value identifiers, values here are what is expected to be received in $_POST as the label ident
    const pv_cid 			= "CID";					// contact ID
    const pv_seid			= "SEID";					// support enquiry ID
    const pv_icmmasterkey 	= "ICSPICMMasterKey";
    const pv_icmcustid 		= "ICSPICMCustomerRefId";
    const pv_email 			= "Email";
    const pv_phone 			= "Contact_Phone";
    const pv_custref 		= "ICSPICMCustomerRefId";
    const pv_firstname 		= "First_Name";
    const pv_lastname 		= "Last_Name";
    const pv_city 			= "ICSPCity";
    const pv_country 		= "ICSPCountry";
    const pv_postalcode 	= "ICSPPostalCode";
    const pv_familtyno 		= "ICSPFamilyNumber";
    const pv_loyaltyno 		= "ICSPLoyaltyCode";
    const pv_companyname 	= "ICSPCompanyName";
    const pv_mobilephone 	= "ICSPMobilePhone";
    const pv_workphone 		= "ICSPWorkPhone";
    const pv_homephone 		= "ICSPHomePhone";
    const pv_market 		= "Market";					// current market
    const pv_contactmarket 	= "Contact_Market";			// market set in the contact or to be set in the contact
    const pv_searchse 		= "searchSE";
    const pv_isphone 		= "isPhone";   

    // GET value identifiers
    const gv_action 		= "action";   	// lookup, retrieve, save, bypass(returns the vanilla output from icm), or test(returns post and get values)
    const gv_verbose 		= "verbose";   	// set to 1 for verbose or 0 for simple
    const gv_format 		= "format";    	// json, xml, array
    const gv_curl_data 		= "curl_data"; 	// set to 1 if required to return curl data
    const gv_perf_data 		= "perf_data";	// set to 1 if required to return peformance data
    const gv_type 			= "type";  		// REST, SOAP defaults to REST
    
    private $currmarket		= null;			// country object of current market - LookupName and Name
    private $isPhone 		= FALSE;
    private $searchSE 		= FALSE;
    private $roql_qry		= "";
    private $icm_enabled 	= TRUE;			// from the Markets object
    private $reportID 		= "102307";		// CUSTOM_CFG_ICMModel_reportID
    private $perf    		= array();
    private $do_qry 		= FALSE;
    private $ICM_Contacts 	= array();
    private $OSC_Contacts 	= array();
    private $OSC_Enquiry	= "";			// Incident Object when populated
    private $ICM_count 		= 0;
    private $OSC_count 		= 0;
    private $open_SE_count	= 0;
    private $market			= null;			// country object of market - LookupName and Name
        
    // public variables for setting comm values and getting returned data
    public $verbose 	= true;
    public $host 		= "";
    public $query_type	= "REST";
    public $url			= "";  				// CUSTOM_CFG_ICMModel_b2buser
    public $action 		= "";
    public $detail 		= "";
    public $auth_type	= "basic";			// basic or certs
    public $login 		= "";  				// required for basic auth - CUSTOM_CFG_ICMModel_b2buser
    public $password 	= "";   			// required for basic auth - CUSTOM_CFG_ICMModel_b2bpass
    public $db_name 	= ""; 				// required for certificates
    
    public $error 		= "";
    public $info 		= "";
    public $http_code	= 0;

    public $inputAction		= "";
    public $outputFormat	= "json";
    public $posted 			= array();

    public function __construct() {             // initialise varibles for class useage
        $this->performance(__FUNCTION__,"start");
        try{
            $this->posted = $_POST;  // keep initial array separate from used array

            if($_GET[self::gv_type]!="") $this->query_type = $_GET[self::gv_type]; // defaults to REST
            if($_GET[self::gv_verbose]=="0") $this->verbose = FALSE; // defaults to TRUE
            if($_GET[self::gv_format]!="") $this->outputFormat = $_GET[self::gv_format]; // defaults to json
            if($_GET[self::gv_action]!="") $this->inputAction = $_GET[self::gv_action]; // defaults to ""
            if($this->posted[self::pv_searchse]=="1")$this->searchSE = TRUE; // defaults to false
            if($this->posted[self::pv_isphone]=="1") $this->isPhone = TRUE; // defaults to false
            
            $this->host       	= RNCPHP\Configuration::fetch( CUSTOM_CFG_ICMModel_b2burl )->Value;
            $this->login      	= RNCPHP\Configuration::fetch( CUSTOM_CFG_ICMModel_b2buser )->Value;
            $this->password   	= RNCPHP\Configuration::fetch( CUSTOM_CFG_ICMModel_b2bpass )->Value;

            if($this->posted[self::pv_market]=="") $this->return_message("","","Market required");
			if($this->posted[self::pv_contactmarket]!="")  $this->contactmarket	= RNCPHP\Country::fetch($this->posted[self::pv_contactmarket]);

			if($this->posted[self::pv_market]=='GL'){
				$this->market 	= (object)array (
											"LookupName"=>"GL",
											"Name"=>"Global",
											"ISOCode"=>"GL"
											);
			} else {
				$this->market		= RNCPHP\Country::fetch($this->posted[self::pv_market]);	
			}
                        
    		$this->icm_enabled	= RNCPHP\Market\Markets::fetch($this->market->Name)->icm_enabled;
            
            $this->performance(__FUNCTION__,"finish");
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        }
      
  
    }
    
    private function init_ICM_vars(){
    	
		SWITCH ($this->query_type){
                case 'SOAP':
                    //echo "</br>SOAP data comm</br>";
                    $this->url        = $this->host."/ws/IKEA.MCMICMSecGateway.ws.OIPToB2BProvider.B2B_FindCustomerMasterICSPReqABCS/IKEA_MCMICMSecGateway_ws_OIPToB2BProvider_B2B_FindCustomerMasterICSPReqABCS_Port";
                    $this->action     = "IKEA_MCMICMSecGateway_ws_OIPToB2BProvider_B2B_FindCustomerMasterICSPReqABCS_Binder_FindCustomerMasterICSPReqABCS";
                    $this->detail     = $this->SOAP_find('DE', "some test data");
                    break;
                case 'REST':
                    //echo "</br>REST data comm</br>";
                    $this->url        = $this->host."/FindCustomerMasterICSPReqABCS";
                    $this->action     = "FindCustomerMasterICSPReqABCS";
                    $this->detail     = $this->REST_find();
                    break;
                default:
                    $this->url        = $this->host."/FindCustomerMasterICSPReqABCS";
                    $this->action     = "FindCustomerMasterICSPReqABCS";
                    $this->detail     = $this->REST_find();
                    break;
            }
    	return;
	}
    
    public function retrieveContacts(){         // 2.1.2.1 TDD
        $this->performance(__FUNCTION__,"start");
        $do_ICM=FALSE;
        $OSC_c = 0;
        $ICM_c = 0;
        // if a PUID is NOT found or NO contact records are found, do a lookup to ICM 
        
        $this->process_OSC();
        $OSC_c = sizeof($this->OSC_Contacts);
   
        if($OSC_c >0){
        
            for ($i = 0; $i < $OSC_c; $i++) {
                if($OSC_Contacts[$i]["ICSPICMMasterKey"]=="") $do_ICM = TRUE;
        
            }
        } else { // no OSC records found
            $do_ICM=TRUE;
        }
                
        if($do_ICM==TRUE) {
           $this->process_ICM(); // if no OSC records with a PUID then do a call to ICM
           $ICM_c = sizeof($this->ICM_Contacts); 
        }  
            
        
        if($OSC_c>0 || $ICM_c>0){
            $this->performance(__FUNCTION__,"finish");
            
            $this->return_output(); // compiles the data and returns data 
        } else {
            $this->return_message("","", "No Records found in OSC or ICM", "False");
        }
                 
  
        exit;
    }

    public function savePostedContact(){              // revised to create from posted values
        // if(empty($this->posted["Contacts"])) { 
            // $this->return_message("",$this->posted["Contacts"], "Posted data does not contain Contacts array");
        // } else {
            // if($this->posted["Contacts"]["Resident"]!= "ICM") {
                // $this->return_message("",$this->posted["Contacts"], "Posted data is not ICM resident: "); 
            // } else {                
//                         
                $OSC_ID = $this->create_OSC_contact($this->posted);
                
                if($OSC_ID!=""){
                    $this->return_message("",array("OSC_ID"=>$OSC_ID),"New record created in OSC","True");
                } else {
                    $this->return_message("",$this->posted, "create_OSC_contact returned false, record NOT created","False");
                }
//                 
            // }
        // }
        exit;
    }
    
    public function saveContact(){              // 2.1.2.2 TDD
        $ID = $this->posted[self::pv_icmcustid];
        $MK = $this->posted[self::pv_market];
        
        if($ID == "" || $MK == "") {
            $this->return_message("","", "Check posted data: ".self::pv_icmcustid." = '".$this->posted[self::pv_icmcustid].
                            "' & ".self::pv_market." = '".$this->posted[self::pv_market]."'"); 
        }                
            
        if($this->process_ICM()){
          
            // we have ICM data so lets create an OSC record
            $OSC_ID = $this->create_OSC_contact();
            
            if($OSC_ID!=""){
                $this->return_message("",array("OSC_ID"=>$OSC_ID),"New record created in OSC","True");
            } else {
                $this->return_message("","", "get_ICM returned true but data not refined, record NOT created","False");
            }
        }else {
                $this->return_message("","", "get_ICM returned false, record NOT created","False");
        }; 
        
        
        
        exit;
    }
    
    private function get_OSC_SE($seid){
    	if(is_null($seid)) $this->return_message("","",__FUNCTION__." Support Enquiry ID is null");
		$enquiry = RNCPHP\Incident::fetch($seid); 
		if(is_null($enquiry)) $this->return_message("","","Support Enquiry ID:".$seid." not found");
    	//$id = $enquiry->ID;
		$this->OSC_Enquiry = $enquiry;
		$enquiry = null;
		return TRUE;
	}
    
    private function appendThreadItem($enquiryObj, $threadMessage) {
    	
		// thread array can only be appended
		if($enquiryObj->Threads == null) {
			$enquiryObj->Threads = new RNCPHP\ThreadArray();
		}
		$tc = sizeof($enquiryObj->Threads);
		if($enquiryObj->Threads[$tc] == null) {
			$enquiryObj->Threads[$tc] = new RNCPHP\Thread();
			$enquiryObj->Threads[$tc]->chan_id = 9; // E-mail
			if($enquiryObj->Threads[$tc]->EntryType == null) {
				$enquiryObj->Threads[$tc]->EntryType = new RNCPHP\NamedIDOptList();
			}
			$enquiryObj->Threads[$tc]->EntryType->ID = 1; // private note - Used the ID here. See the Thread object for definition
			$enquiryObj->Threads[$tc]->Text = $threadMessage;
		}
		return $enquiryObj;
	}
    
    private function checkContactMarket($enquiryObj){
    	$se_contact = RNCPHP\Contact::fetch($enquiryObj->PrimaryContact->ID);
		$se_market = $se_contact->CustomFields->Market->market_name->LookupName; //Name="NL" LookupName="Netherlands"
		$market = $this->market->Name; // Name="Netherlands" LookupName="NL" - NO its not the same field assignments :(
				
		if($se_market==$market) {
			return TRUE;					
		} else {
			return FALSE;
		}
	}
    
    private function reassignToAnon($enquiryObj){
		// none or many found, assign anonymous contact record to enquiry
		try{
			
			$old_contact	= $enquiryObj->PrimaryContact;
			$old_market		= $old_contact->CustomFields->Market->market_name->LookupName;			
			$old_email		= $old_contact->Emails[0]->Address;  
			
			$res = $this->get_OSC_objects("select Contact from Contact where contact.Emails.EmailList.Address = 'anonymous@anonymous.com' and ".
										"contact.CustomFields.Market.market_name.LookupName='".$this->market->Name."' GROUP BY Contact.ID");
			$contact = $res->next();
						
			if(!is_null($contact)){
				
				$this->appendThreadItem(
							$enquiryObj,
							"Anonymous Contact assigned to this Enquiry\n\n".
							"Contact changed from ID:".$old_contact->ID." to ID:".$contact->ID."\n".
							"Previous Contact market was '".$old_market."'\n\n".
							"Previous Contact details:"."\n".
							"ID: ".$old_contact->ID."\n".
							"Name: ".$old_contact->Name->First." ".$old_contact->Name->Last."\n".
							"Email: ".$old_email."\n"
							);			
			
				$this->OSC_Enquiry->PrimaryContact = $contact;
				$this->OSC_Enquiry->save(RNCPHP\RNObject::SuppressAll);
				RNCPHP\ConnectAPI::commit();
				
				$enq = array("SEID"=>$this->OSC_Enquiry->ID,"ReferenceNumber"=>$this->OSC_Enquiry->ReferenceNumber);
				$con = array("OSC_ID"=>$contact->ID);
				$this->return_message($enq, $con, "Enquiry assigned from ID:".$old_contact->ID." to Anonymous contact ID:".$contact->ID);
				
			} else {
				$this->return_message("","","anonymous@anonymous.com not found for assignement to Enquiry ID:".$enquiryObj->ID);
				
			}
		
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
	}
	
	private function return_report(){
		$arrOutput = array(
			            "Report"=>array("Report_ID"=>$this->reportID),
			            "Error"=>array("Success"=>"True","Message"=>"(None or Multiple records) OR (OSC with no PUID and no ICM)"),
			            "Totals"=>array("OSC"=>$this->OSC_count,"ICM"=>$this->ICM_count)         
			            );
	    
	    header('Content-type: application/json');
	    echo json_encode($arrOutput);
	    exit;
	}
	
	private function updateMarketToCurrent(){
		try {
			
			$market = RNCPHP\Market\Markets::fetch($this->market->Name); // transpose NL to Netherlands from country table
			
			if($this->posted[self::pv_isphone]==TRUE){
				// get contact from contact
				$se_contact = RNCPHP\Contact::fetch($this->OSC_Contacts[0]["OSC_ID"]);
				$old_market = $se_contact->CustomFields->Market->market_name->LookupName;
				$enq = array();
				$con = array("OSC_ID"=>$se_contact->ID);
				$msg = "Contact updated with current Market";		
			} else {
				// get contact from enquiry
				$se_contact = RNCPHP\Contact::fetch($this->OSC_Enquiry->PrimaryContact->ID);
				$old_market = $se_contact->CustomFields->Market->market_name->LookupName;
				
				$this->OSC_Enquiry = $this->appendThreadItem(
												$this->OSC_Enquiry,
												"Assigned contact updated Market from: '".$old_market."' to '".$this->market->Name."'"
												);
				$this->OSC_Enquiry->save(RNCPHP\RNObject::SuppressAll);	
				
				$enq = array("SEID"=>$this->OSC_Enquiry->ID,"ReferenceNumber"=>$this->OSC_Enquiry->ReferenceNumber);
				$con = array("OSC_ID"=>$this->OSC_Enquiry->PrimaryContact->ID);
				$msg = "Assigned contact updated with current Market";
			}	
										
			$se_contact->CustomFields->Market->market_name = $market;
			$se_contact->save(RNCPHP\RNObject::SuppressAll);
					
			RNCPHP\ConnectAPI::commit();	
				
			$this->return_message($enq, $con, $msg ,"True");
			
		} catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
	}
	
	private function get_open_enquiries(){
		if($this->posted[self::pv_searchse]==TRUE){
			$res = $this->get_OSC_query("select ID from Incident where incident.primarycontact.parentcontact.id = ".
										$this->OSC_Contacts[0]["OSC_ID"]." and incident.statuswithtype.statustype=1");
			$this->open_SE_count = $res->count();
			if($res->count()==1) {
				return $res;
			} else {
				return null;
			}
				
		} else {
			return null;
			
		}
		
									
	}
    
    public function inboundLookup(){            // 2.1.2.3 TDD  lookup
	    try{
			
	        $this->performance(__FUNCTION__,"start");
	    	$this->ICM_Contacts = array(); // delete the array for output
	        $do_ICM=FALSE;
	        $OSC_c = 0;
	        $ICM_c = 0;
	         
	        if($this->posted[self::pv_isphone]=="") $this->return_message("","",self::pv_isphone."='".$this->posted[self::pv_isphone]."' 0 or 1 is required");  // move to pre-check (?) function
	   		if($this->posted[self::pv_isphone]==TRUE){
				// I N B O U N D   P H O N E
				if($this->posted[self::pv_phone]=="") $this->return_message("","",self::pv_phone. " is required when ".self::pv_isphone."='".$this->posted[self::pv_isphone]."'");
				$this->process_OSC();
	        	$OSC_c = $this->OSC_count;
					       	               	
	        	if($this->OSC_count==1) {
						if($this->OSC_Contacts[0]["Market"] == $this->market->Name){ 
					
							// contact market matches
							// if a PUID is NOT found or NO contact records are found, do a lookup to ICM 

	// tdd does not state to check for PUID for phone numbers, left in as i think it will come back

	            			if($this->OSC_Contacts[0]["ICSPICMMasterKey"]=="") {  
	            				// NO PUID
	            				/*
								$r = $this->process_ICM();
															
								if($this->ICM_count==1){
									//echo " icm got ";
									$contact_id = $this->create_OSC_contact($this->ICM_Contacts[0]);
									$this->return_message("",array("OSC_ID"=>$contact_id),"New OSC record created from ICM","True");
								} 
								
								if($this->ICM_count!=1) $this->return_report();
								*/
								
								$se_coll = $this->get_open_enquiries();
											
								if($se_coll){
									$se=$se_coll->next(); // get that first and only se record
									$this->return_message(array("SEID"=>$se["ID"]),array("OSC_ID"=>$this->OSC_Contacts[0]["OSC_ID"]),
												"OSC Contact found with 1 Open Enquiry, matching the current ".$this->market->LookupName." market","True");
								} else {
									$this->return_message("",array("OSC_ID"=>$this->OSC_Contacts[0]["OSC_ID"]),
												"OSC Contact found, matching the current ".$this->market->LookupName." market","True");	
								}
								
								
							} else {
								// GOT PUID
								// check for open enquiries
								
								$se_coll = $this->get_open_enquiries();
											
								if($se_coll){
									$se=$se_coll->next(); // get that first and only se record
									$this->return_message(array("SEID"=>$se["ID"]),array("OSC_ID"=>$this->OSC_Contacts[0]["OSC_ID"]),
												"OSC Contact found with 1 Open Enquiry, matching the current ".$this->market->LookupName." market","True");
								} else {
									$this->return_message("",array("OSC_ID"=>$this->OSC_Contacts[0]["OSC_ID"]),
												"OSC Contact found, matching the current ".$this->market->LookupName." market","True");	
								}
							}	
						} else {
							
							// Market doesnt match current market so check ICM
							$this->process_ICM();
													
							if($this->ICM_count==1){
								// got 1 ICM record back, create it
								$contact_id = $this->create_OSC_contact($this->ICM_Contacts[0]);
								$this->return_message("",array("OSC_ID"=>$contact_id),"New contact created form ICM","True");	
							} 
														
							if($this->ICM_count>1){
								$this->return_report();
							}	
							
							if($this->ICM_count==0){
								// if market is null, then update it to current market
								if($this->OSC_Contacts[0]["Market"]=="") $this->updateMarketToCurrent();
							} else {
								$this->return_report();
							}
							
								
							
						}
				}
				if($this->OSC_count>1) {
					
						// found more than 1 osc record 
						$this->return_report();
				}
				
				if($this->OSC_count==0) {
								
						// no OSC record found
						
						$this->process_ICM();
						
							if($this->ICM_count==1){
							
								// got 1 ICM record back, create it
								$contact_id = $this->create_OSC_contact($this->ICM_Contacts[0]);
								$this->return_message("",array("OSC_ID"=>$contact_id),"New OSC contact created from ICM","True");	
							} 
														
							if($this->ICM_count!=1){
							
								$this->return_report();
							}	
					
				}
				
				
				
			} else {
				// I N B O U N D   E M A I L / C H A T
				if($this->posted[self::pv_seid]=="") $this->return_message("","",self::pv_isphone."='0', ".self::pv_seid." is required");
				
				$this->get_OSC_SE($this->posted[self::pv_seid]); // this will exit with error message if not found, populates $this->OSC_Enquiry
					
				$se_contact = RNCPHP\Contact::fetch($this->OSC_Enquiry->PrimaryContact->ID);
				$se_market	= $se_contact->CustomFields->Market->market_name->LookupName;
				$se_email	= $se_contact->Emails[0]->Address;												
				
				$this->OSC_count=1;
				$this->open_SE_count=1;
				
				$res = $this->get_OSC_query("select count() from Contact where ".
											"Contact.Emails.EmailList.Address='".
											$se_email."' and ".
											"contact.CustomFields.Market.market_name.LookupName='".$this->market->Name."' GROUP BY Contact.ID"
											)->next();
				$contactCount = (int)$res["count()"];
			
				if($contactCount==1) {
					// 1 or more osc contact records found, tdd says deal with 1
					if($this->checkContactMarket($this->OSC_Enquiry)==TRUE){
						$this->return_message(array("SEID"=>$this->OSC_Enquiry->ID),array("OSC_ID"=>$se_contact->ID),"Contact Market confirmed","True");	
					} else {
						$this->reassignToAnon($this->OSC_Enquiry);
					}
				}
				
				if($contactCount==0){
					// no osc record found with the current market
					
					// Market doesnt match or is null, lookup in ICM.....email and market
					// clear posted vars and repopulate then use to send to icm
					$this->posted = array(
											self::pv_email=>$se_email,
											self::pv_market=>$this->market->LookupName
											);
					$this->process_ICM();
					
					if($this->ICM_count==1) {
										
			            $this->ICM_Contacts[0]["Email"] = $this->ICM_Contacts[0]["Email - Primary"];
			            $this->ICM_Contacts[0]["ICSPLangCode"] = "";
			            $new_contact_id = $this->create_OSC_contact($this->ICM_Contacts[0]); // this function should return the contact object - change later
						// set the enquiry contact to the new one 
						$se_new_Contact = RNCPHP\Contact::fetch($new_contact_id);  // poor overheads - get c-obj above
						$this->OSC_Enquiry->PrimaryContact = $se_new_Contact;
						$this->OSC_Enquiry->save(RNCPHP\RNObject::SuppressAll);
						RNCPHP\ConnectAPI::commit();
											
						// build return message
						$enq = array("SEID"=>$this->OSC_Enquiry->ID,"ReferenceNumber"=>$this->OSC_Enquiry->ReferenceNumber);
						$con = array("OSC_ID"=>$new_contact_id);
						$this->appendThreadItem(
							$this->OSC_Enquiry,
							"New contact created from ICM and assigned to Enquiry\n\n".
							"Contact changed from ID:".$se_contact->ID." to ID:".$se_new_Contact->ID."\n".
							"Previous Contact market was '".$se_market."' changed to a Contact created from ICM with Market '".
							$se_new_Contact->CustomFields->Market->market_name->LookupName."'\n\n".
							"Previous Contact details:"."\n".
							"ID: ".$se_contact->ID."\n".
							"Name: ".$se_contact->Name->First." ".$se_contact->Name->Last."\n".
							"Email: ".$se_email."\n"
							 );
						$this->return_message($enq, $con,"New contact created from ICM and assigned to Enquiry","True");
					} 
					
					if($this->ICM_count==0){
						// update the enquiry contact with current market
						if($se_market=="" || is_null($se_market)){
							$this->updateMarketToCurrent();	// this includes return message
						} else {
							// contact market is not a match but isnt null so reassign to annon
							$this->reassignToAnon($this->OSC_Enquiry);  // this includes return message
						}
						
					}
					
					if($this->ICM_count>1){
						// reassign to annon
						$this->reassignToAnon($this->OSC_Enquiry);  // this includes return message
					}
				}
				
				if($contactCount>1){
					// reassign to annon
					$this->reassignToAnon($this->OSC_Enquiry);  // this includes return message
				}
				
			}


		} catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
         
    }
    
    public function performance($function,$action){       // adds start and stop time for performance monitoring
        try{
            if ($_GET["perf_data"]==1) {
                $t = microtime(true);
                $micro = sprintf("%06d",($t - floor($t)) * 1000000);
                $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
                
                $this->perf[] = array($function.":".$action=>$d->format("Y-m-d H:i:s.u"));        
            }  
                        
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        }
    }

    public function test() {                    // returns the get and post data to confirm to sender, no action performed
        header('Content-type: application/json');
        $data = array_merge(array('GET'=>$_GET),array('POST'=>$_POST));
        echo json_encode(array('TEST'=>$data));
        return 200;
    }

    public function mark() {                    // marks testing ground
        header('Content-type: text/HTML');
        
        var_dump($f);
        //header($_SERVER['SERVER_PROTOCOL'].' 400 Bad Request')
        exit;
        //$this->get_OSC_query("Select )
        return 200;
    }

    public function bypass(){         // bypass and just return the b2b data
        switch($_GET[self::gv_format]){
            case "":
                $this->return_message("","", "format of json or xml is required");
                break;
            case "xml":
                $this->do_ICM_comms(); // populate icm_contacts 
                header('Content-type: xml');
                var_dump($this->ICM_Contacts);
                break;
            case "json":
            	$objXML     = new xml2Array();
                $arrOutput  = $objXML -> parse($this->ICM_Contacts);
                $this->process_ICM();  // populate icm_contacts 
                header('Content-type: application/json');
                echo json_encode($arrOutput);
                break;
            default:
                $this->do_ICM_comms(); // populate icm_contacts 
                header('Content-type: xml');
                var_dump($this->ICM_Contacts);
                break;
        }     
        
        return;    
    }

    public function return_output($format=""){    // format the output and send it
        $this->performance(__FUNCTION__,"start");
        $arrOutput = $this->compile_output(); // refined data
        //$arrOutput = $this->ICM_Contacts; // vanilla data - used in testing
        
        try{      
            if ($format=="") $format = $this->outputFormat; // allows easy override of format from $_GET['output']
                        
            switch ($format){
                case "xml":
                    //$objXML = new xml2Array();
                    //$arrOutput = $objXML -> parse($this->ICM_Contacts);
                    header('Content-type: xml');
                    print_r($arrOutput);
                case "json":
                    header('Content-type: application/json');
                    echo json_encode($arrOutput);
                    break;
                case "retagged_xml":
                    // //$objXML = new xml2Array();
                    // //$arrOutput = $objXML -> parse($this->ICM_Contacts);
                    // header('Content-type: text/xml');
                    // //echo '<ICM>';
                    // foreach($arrOutput as $index => $post) {
                        // if(is_array($post)) {
                            // foreach($post as $key => $value) {
                                // echo '<',$key,'>';
                                // if(is_array($value)) {
                                    // foreach($value as $tag => $val) {
                                        // echo '<',$tag,'>',htmlentities($val),'</',$tag,'>';
                                    // }
                                // }
                                // echo '</',$key,'>';
                            // }
                        // }
                    // }
                    //echo '</ICM>';
                    break;
                case "array":
                    //$objXML = new xml2Array();
                    //$arrOutput = $objXML -> parse($this->ICM_Contacts);
                    //header('Content-type: text');
                    //var_dump($arrOutput);
                    break;
                default:
                    //$objXML = new xml2Array();
                    //$arrOutput = $objXML -> parse($this->ICM_Contacts);
                    //$mergedOutput = array_merge($this->perf,$arrOutput); // add performance data to array 
                    header('Content-type: application/json');
                    echo json_encode($arrOutput);
                    break;
            }
        
        // clean up arrays and exit
        $this->ICM_Contacts = null;
        $this->OSC_Contacts = null;
        $this->perf = null;
        $arrOutput = null;
        exit;
        
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
    }
   
    private function process_ICM(){  // gets and refines the ICM data
       $this->performance(__FUNCTION__,"start");
        if($this->icm_enabled==TRUE){
                 	   
            $this->init_ICM_vars();

            if($this->do_ICM_comms()==TRUE){
   
              $this->performance(__FUNCTION__,"finish");
                
              return $this->refine_ICM(); // returns TRUE or FALSE
              
            }  
        }
        
        $this->performance(__FUNCTION__,"finish");
        return FALSE;
    }
    
    private function process_OSC(){ // gets and refines the OSC data
        $this->performance(__FUNCTION__,"OSC query start");
        
        //if($this->posted[self::pv_isphone])
        
        $this->do_qry = FALSE;
        // build the roql_qry and get contacts from OSC
        $this->roql_qry="";
        $this->build_roql("C.name.first like ", $this->posted[self::pv_firstname],"string");
        $this->build_roql("C.name.last like ", $this->posted[self::pv_lastname],"string");
        $this->build_roql("C.ID = ", $this->posted[self::pv_cid],"integer");
        $this->build_roql("C.Emails.EmailList.Address = ", $this->posted[self::pv_email],"string");
        $this->build_roql("C.CustomFields.CO.ICSPCity like ", $this->posted[self::pv_city],"string");
        $this->build_roql("C.CustomFields.CO.ICSPCountryId like ", $this->posted[self::pv_country],"string");
        $this->build_roql("C.CustomFields.CO.ICSPPostCode like ", $this->posted[self::pv_postalcode],"string");
        
        if($this->posted[self::pv_phone]!=""){
			$this->build_roql("","( C.CustomFields.CO.ICSPHomePhone like '".$this->posted[self::pv_phone]. "' OR ".
    							"C.CustomFields.CO.ICSPWorkPhone like '".$this->posted[self::pv_phone]. "' OR ".
        						"C.CustomFields.CO.ICSPMobilePhone like '".$this->posted[self::pv_phone]."')","statement"  );      
        }
		if($this->posted[self::pv_homephone]!="" || $this->posted[self::pv_workphone]!="" || $this->posted[self::pv_mobilephone]) {
			$this->build_roql("","( C.CustomFields.CO.ICSPHomePhone like '".$this->posted[self::pv_homephone]. "' OR ".
    							"C.CustomFields.CO.ICSPWorkPhone like '".$this->posted[self::pv_workphone]. "' OR ".
        						"C.CustomFields.CO.ICSPMobilePhone like '".$this->posted[self::pv_mobilephone]."')","statement"  );      
		}
        					        					  
        $this->build_roql("C.CustomFields.CO.ICSPLoyaltyNum like ", $this->posted[self::pv_familtyno],"string"); // Family Number
        $this->build_roql("C.CustomFields.CO.ICSPCompanyName like ", $this->posted[self::pv_companyname],"string"); // Business_Name
        
        $this->build_roql("C.CustomFields.market.market_name.LookupName = ",$this->market->Name,"string"); 
              
		$this->roql_qry = "select C.ID from Contact C where ".$this->roql_qry;              
                            

        if($this->do_qry==TRUE){  // if we have a qry that is built with 'where' criteria then do the qry to osc
                     
            $this->OSC_Contacts  = $this->get_OSC_query($this->roql_qry);
            $this->performance(__FUNCTION__,"OSC query finish");
            
            if($this->OSC_Contacts->count()==0){             // no contacts found  
                $this->OSC_Contacts = array();  
                return FALSE;
            } else { 										// we have OSC Contacts
                $result = $this->refine_OSC(); // refines and returns TRUE or FALSE
                $this->performance(__FUNCTION__,"finish");    
                return $result;    
            }
        } else {
            $this->OSC_Contacts = array();  
            $this->performance(__FUNCTION__,"finish");
            return FALSE;
        }
        
    }
    
    private function refine_OSC(){
        $this->performance(__FUNCTION__,"start");
        $data = array();
        $refined_data=array();
        
        try{
            if($this->OSC_Contacts->count()>0){
                
                $count = 0;
                while($contact = $this->OSC_Contacts->next())
                {
                    $cont = RNCPHP\Contact::fetch($contact["ID"]);  //easier to process, might be a higher overhead though
                    
                    $phones = array();
                    $emails = array();
                                     
                    $data = array(
                                "OSC_ID"=> nullq((string)$cont->ID),
                                "ICSPICMMasterKey"=> nullq($cont->CustomFields->CO->ICSPICMMasterKey),
                                "ICSPContactType"=> nullq($cont->ContactType->LookupName),
                                "ICSPContactStatus"=> nullq($cont->CustomFields->CO->ICSPContactStatus->LookupName),
                                "ICSPCustomerNum"=> nullq($cont->CustomFields->CO->ICSPCustomerNum),
                                "ICSPCountryId"=> nullq($cont->CustomFields->CO->ICSPCountryId->LookupName),
                                "ICSPLangCode"=> nullq($cont->CustomFields->CO->ICSPLangCode),
                                "ICSPPreferredStore"=> nullq($cont->CustomFields->RFC->rfc_tp_tp_store->LookupName),
                                "ICSPPrefType"=> nullq($cont->CustomFields->CO->ICSPPrefType),                   
                                "ICSPICMCustomerRefId"=> nullq($cont->CustomFields->CO->ICSPICMCustomerRefId),
                                "First_Name"=> nullq($cont->Name->First),
                                "ICSPMiddleName"=> nullq($cont->CustomFields->CO->ICSPMiddleName),
                                "Last_Name"=> nullq($cont->Name->Last),
                                "ICSPTitle"=> nullq($cont->CustomFields->CO->ICSPTitle),
                                "ICSPGender"=> nullq($cont->CustomFields->CO->ICSPGender),
                                "ICSPProtIden"=> nullq($cont->CustomFields->CO->ICSPProtIden),                   
                                "ICSPICMAddressRefId"=> nullq($cont->CustomFields->CO->ICSPICMAddressRefId),
                                "ICSPStreet"=> nullq($cont->CustomFields->CO->ICSPStreet),
                                "ICSPCity"=> nullq($cont->CustomFields->CO->ICSPCity),
                                "ICSPPostalCode"=> nullq($cont->CustomFields->CO->ICSPPostCode),
                                "ICSPCountry"=> nullq($cont->CustomFields->CO->ICSPCountry),
                                "ICSPCounty"=> nullq($cont->CustomFields->CO->ICSPCounty),
                                "ICSPProvince"=> nullq($cont->CustomFields->CO->ICSPProvince),
                                "ICSPState"=> nullq($cont->CustomFields->CO->ICSPState),
                                "ICSPHouseNum"=> nullq($cont->CustomFields->CO->ICSPHouseNum),
                                "ICSPApartNum"=> nullq($cont->CustomFields->CO->ICSPApartNum),
                                "ICSPFloorNum"=> nullq($cont->CustomFields->CO->ICSPFloorNum),
                                "ICSPWard"=> nullq($cont->CustomFields->CO->ICSPWard),
                                "ICSPCName"=> nullq($cont->CustomFields->CO->ICSPCName),
                                "ICSPStrAd1"=> nullq($cont->CustomFields->CO->ICSPStrAd1),
                                "ICSPStrAd2"=> nullq($cont->CustomFields->CO->ICSPStrAd2),
                                "ICSPStrAd3"=> nullq($cont->CustomFields->CO->ICSPStrAd3),
                                "ICSPStrAd4"=> nullq($cont->CustomFields->CO->ICSPStrAd4),
                                "ICSPStrAd5"=> nullq($cont->CustomFields->CO->ICSPStrAd5),
                                "ICSPStrAd6"=> nullq($cont->CustomFields->CO->ICSPStrAd6),
                                "ICSPStrAd7"=> nullq($cont->CustomFields->CO->ICSPStrAd7),      
                                
                                "ICSPDialingCodeMobile"=> nullq($cont->CustomFields->CO->ICSPDialingCodeMobile->LookupName),
                                "ICSPMobilePhone"=> nullq($cont->CustomFields->CO->ICSPMobilePhone),
                                "ICSPDialingCodeHome"=> nullq($cont->CustomFields->CO->ICSPDialingCodeHome->LookupName),
                                "ICSPHomePhone"=> nullq($cont->CustomFields->CO->ICSPHomePhone),
                                "ICSPDialingCodeWork"=> nullq($cont->CustomFields->CO->ICSPDialingCodeWork->LookupName),
                                "ICSPWorkPhone"=> nullq($cont->CustomFields->CO->ICSPWorkPhone),
                                
                                "IntegrityType"=> nullq($cont->CustomFields->CO->IntegrityType),
                                "ConsentCode"=> nullq($cont->CustomFields->CO->ConsentCode),
                                "ICSPLoyaltyCode"=> nullq($cont->CustomFields->CO->ICSPLoyaltyCode),
                                "ICSPLoyaltyStatus"=> nullq($cont->CustomFields->CO->ICSPLoyaltyStatus),
                                "ICSPOrg"=> nullq($cont->CustomFields->CO->ICSPOrg),
                                "ICSPOrgType"=> nullq($cont->CustomFields->CO->ICSPOrgType),
                                "ICSPOrgId"=> nullq($cont->CustomFields->CO->ICSPOrgId),
                                
                                "Market"=>nullq($cont->CustomFields->Market->market_name->LookupName)
                                );
                    
                    
                    
                    // add email addresses
                    $size = sizeof($cont->Emails);
                    for ($p = 0; $p<$size; $p++){
                        if($p == 0) {
                            $emails = array($cont->Emails[$p]->AddressType->LookupName => $cont->Emails[$p]->Address); 
                        }  else {
                            $emails[] = array($cont->Emails[$p]->AddressType->LookupName => $cont->Emails[$p]->Address);
                        }
                    }
                    
                    
                    // add phone numbers
                    $size = sizeof($cont->Phones);
                    for ($p = 0; $p<$size; $p++){
                        if($p == 0) {
                            $phones = array($cont->Phones[$p]->PhoneType->LookupName => $cont->Phones[$p]->Number);
                        } else {
                            $phones[] = array($cont->Phones[$p]->PhoneType->LookupName => $cont->Phones[$p]->Number);    
                        }
                    }
    
                    $footer = array("Resident"=>"OSC");         
                    $refined_data[$count] = array_merge($data,$emails,$phones,$footer);
                       
                    $count += 1;    
                }
    
                $this->OSC_count = $count;
                $this->OSC_Contacts = $refined_data;  // replace content with refined content
                $refined_data=null; // release memory
                $this->performance(__FUNCTION__,"finish");
                return TRUE;
            } else {
                $this->OSC_Contacts=array();
                return FALSE;
            }
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        }
       
    }

    private function refine_ICM(){            // 2.6 Field Mapping of TDD
        // refine and aggregate the ICM data into an array.
        $this->performance(__FUNCTION__,"start");
        try{    
            $objXML = new xml2Array();
            $curlResponse = $objXML -> parse($this->ICM_Contacts);
            // ---------------------------------------------------------------------------------------------
            // build base arrays                        
     
            
            $iAccount = $this->get_ICM_section($curlResponse,"name","ACCOUNT");

            if(empty($iAccount)) {
                $this->ICM_Contacts = array(); // empty it
                $this->performance(__FUNCTION__,"finish");
       
                return FALSE; // return false if no account is found
            }

       
            // ---------------------------------------------------------------------------------------------
                $count = 0;      
                // $iEmail = array();
                // $iPhone = array();      
                $size_iaccount = sizeof($iAccount);
                foreach($iAccount as $singleAccount) {
                     
                    $iContacts = $this->get_ICM_section($singleAccount,"name","LISTOFCONTACT","children");
                  
                    $iAddresses = $this->get_ICM_section($singleAccount,"name","LISTOFADDRESS","children");
                    
                    $iContactResidence = $this->get_ICM_section($this->get_ICM_section($singleAccount,"name","LISTOFCONTACTRESIDENCE"),"name","CONTACTRESIDENCE");    
                    $iContactMethods = $this->get_ICM_section($this->get_ICM_section($singleAccount,"name","LISTOFCONTACTMETHOD"),"name","CONTACTMETHOD");
                    $iLoyaltyMember = $this->get_ICM_section($this->get_ICM_section($singleAccount,"name","LISTOFLOYALTYMEMBER"),"name","LOYALTYMEMBER");
                    $iMembershipCard = $this->get_ICM_section($this->get_ICM_section($singleAccount,"name","LISTOFMEMBERSHIPCARD"),"name","MEMBERSHIPCARD"); 
                    $iOrg =  $this->get_ICM_section( $this->get_ICM_section($iAccount,"name","LISTOFACCOUNTORGANIZATION"),"name","ACCOUNTORGANIZATION"); 
                   
                                        // only 1 account  
                    $account = array(        
                        "OSC_ID"=>"",
                        "ICSPICMMasterKey"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPICMMASTERKEY","tagData")),
                        "ICSPCompanyName"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPCOMPANYNAME","tagData")),
                        "ICSPContactType"=> nullq($this->get_ICM_section($singleAccount,"name","CONTACTTYPE","tagData")),
                        "ICSPContactStatus"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPCONTACTSTATUS","tagData")),
                        "ICSPCustomerNum"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPCUSTOMERNUM","tagData")),
                        "ICSPCountryID"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPCOUTRYID","tagData")),
                        "ICSPLangCode"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPLANGCODE","tagData")),
                        "ICSPPreferredStore"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPPREFERREDSTORE","tagData")),
                        "ICSPPrefType"=> nullq($this->get_ICM_section($singleAccount,"name","ICSPPREFTYPE","tagData"))
                        );
                        
                    // build the contacts list - can be 1+
                            
                    $contacts = array();
                    $contact[0]["ICSPCustomerRefID"] = "";
                    $contact[0]["First_Name"] = "";
                    $contact[0]["ICSPMiddleName"] = "";
                    $contact[0]["Last_Name"] = "";
                    $contact[0]["Contact_Title"] = "";
                    $contact[0]["ICSPGender"] = "";
                    $contact[0]["ICSPProtiden"] = "";
                                       
                    $size_icontact = sizeof($iContacts);
                    for ($ic = 0; $ic < $size_icontact; $ic++) {
                        $iContact = $this->get_ICM_section($iContacts[$ic],"name","CONTACT","children");
                        
                        $contacts[$ic] = array(            
                                    "ICSPCustomerRefID"=> nullq($this->get_ICM_section($iContact,"name","ICSPICMCUSTOMERREFID","tagData")),
                                    "First_Name"=> nullq($this->get_ICM_section($iContact,"name","FIRST_NAME","tagData")),
                                    "ICSPMiddleName"=> nullq($this->get_ICM_section($iContact,"name","ICSPMIDDLENAME","tagData")),
                                    "Last_Name"=> nullq($this->get_ICM_section($iContact,"name","LAST_NAME","tagData")),
                                    "Contact_Title"=> nullq($this->get_ICM_section($iContact,"name","ICSPTITLE","tagData")),
                                    "ICSPGender"=> nullq($this->get_ICM_section($iContact,"name","ICSPGENDER","tagData")),
                                    "ICSPProtiden"=> nullq($this->get_ICM_section($iContact,"name","ICSPPROTIDEN","tagData")),
                                    );
                    }
                    
                    
                    $contactMethod = array();
                    $contactMethod["Email - Primary"]="";
                    $contactMethod["ICSPDialingCodeHome"]="";
                    $contactMethod["ICSPHomePhone"]="";
                    $contactMethod["ICSPDialingCodeWork"]="";
                    $contactMethod["ICSPWorkPhone"]="";
                    $contactMethod["ICSPDialingCodeMobile"]="";
                    $contactMethod["ICSPMobilePhone"]="";      
                    
                    foreach($iContactMethods as $singleMethod) {
                        
                        if(sizeof($this->get_ICM_section($singleMethod,"tagData","EMAIL"))>0) {
                            $contactMethod["Email - Primary"] =  nullq($this->get_ICM_section($singleMethod["children"],"name","VALUE","tagData")); 
                         
                        }
                        
                        if(sizeof($this->get_ICM_section($singleMethod,"tagData","PHONE"))>0) {
                            
                            $pref= $this->get_ICM_section($singleMethod,"name","LISTOFPREFERREDCONTACTMETHOD","children");
                            foreach($prefMethod as $pref){
                                $type = get_ICM_section($pref,"name","CONTEXTTYPE","tagdata");
                                switch($type) {
                                    case "HOME_GRP_PHONE":
                                        $contactMethod["ICSPHomePhone"] =  nullq($this->get_ICM_section($singleMethod["children"],"name","VALUE","tagData")); 
                                        $contactMethod["ICSPDialingCodeHome"] =  nullq($this->get_ICM_section($singleMethod,"name","CountryCode","tagData"));
                                        break;
                                    case "HOME_GRP_PHONE2":
                                        $contactMethod["ICSPWorkPhone"] =  nullq($this->get_ICM_section($singleMethod["children"],"name","VALUE","tagData")); 
                                        $contactMethod["ICSPDialingCodeWork"] =  nullq($this->get_ICM_section($singleMethod,"name","CountryCode","tagData"));
                                        break;   
                                }
                            }
                            
                        }
                        
                        if(sizeof($this->get_ICM_section($singleMethod,"tagData","SMS"))>0) {
                            $contactMethod["ICSPMobilePhone"] =  nullq($this->get_ICM_section($singleMethod["children"],"name","VALUE","tagData")); 
                            $contactMethod["ICSPDialingCodeMobile"] =  nullq($this->get_ICM_section($singleMethod,"name","CountryCode","tagData"));
                        }
                          
                    }
                    
                    // can be 1+
                    $addresses = array();
                    $addresses[0]["ICSPAddressRefID"]="";
                    $addresses[0]["ICSPStreet"]="";
                    $addresses[0]["ICSPCity"]="";
                    $addresses[0]["ICSPPostalCode"]="";
                    $addresses[0]["ICSPCountry"]="";
                    $addresses[0]["ICSPCounty"]="";
                    $addresses[0]["ICSPState"]="";
                    $addresses[0]["ICSPProvince"]="";
                    $addresses[0]["ICSPWard"]="";
                    $addresses[0]["ICSPCname"]="";
                    $addresses[0]["ICSPStrAd1"]="";
                    $addresses[0]["ICSPStrAd2"]="";
                    $addresses[0]["ICSPStrAd3"]="";
                    $addresses[0]["ICSPStrAd4"]="";
                    $addresses[0]["ICSPStrAd5"]="";
                    $addresses[0]["ICSPStrAd6"]="";
                    $addresses[0]["ICSPStrAd7"]="";                    
                    
                    $size_iaddress = sizeof($iAddresses);
                    for ($ia = 0; $ia < $size_iaddress; $ia++) {
                        $iAddress = $this->get_ICM_section($iAddresses[$ia],"name","ADDRESS","children");
                        
                        $addresses[$ia] = array(                                    
                                    "ICSPAddressRefID"=> nullq($this->get_ICM_section($iAddress,"name","ICSPICMADDRESSREFID","tagData")),
                                    "ICSPStreet"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTREETADDRESS","tagData")),
                                    "ICSPCity"=> nullq($this->get_ICM_section($iAddress,"name","ICSPCITY","tagData")),
                                    "ICSPPostalCode"=> nullq($this->get_ICM_section($iAddress,"name","ICSPPOSTALCODE","tagData")),
                                    "ICSPCountry"=> nullq($this->get_ICM_section($iAddress,"name","ICSPCOUNTRY","tagData")),
                                    "ICSPCounty"=> nullq($this->get_ICM_section($iAddress,"name","ICSPCOUNTY","tagData")),
                                    "ICSPState"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTATE","tagData")),
                                    "ICSPProvince"=> nullq($this->get_ICM_section($iAddress,"name","ICSPPROVINCE","tagData")),
                                    "ICSPWard"=> nullq($this->get_ICM_section($iAddress,"name","ICSPWARD","tagData")),
                                    "ICSPCname"=> nullq($this->get_ICM_section($iAddress,"name","ICSPCNAME","tagData")),
                                    "ICSPStrAd1"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD1","tagData")),
                                    "ICSPStrAd2"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD2","tagData")),
                                    "ICSPStrAd3"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD3","tagData")),
                                    "ICSPStrAd4"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD4","tagData")),
                                    "ICSPStrAd5"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD5","tagData")),
                                    "ICSPStrAd6"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD6","tagData")),
                                    "ICSPStrAd7"=> nullq($this->get_ICM_section($iAddress,"name","ICSPSTRAD7","tagData"))
                                    );

                    }
                      
                        
                    $integrityCodes = array(
                        "ICSPIntegrityType"=> nullq($this->get_ICM_section($iAddress,"name","INTEGRITYTYPE","tagData")),
                        "ICSPConsentCode"=> nullq($this->get_ICM_section($iAddress,"name","CONSENTCODE","tagData"))
                        );
                    $loyaltyMember = array(           
                        "ICSPLoyaltyCode"=> nullq($this->get_ICM_section($iAddress,"name","ICSPLOYALTYCODE","tagData")),
                        "ICSPLoyaltyStatus"=> nullq($this->get_ICM_section($iAddress,"name","ICSPLOYALTYSTATUS","tagData"))
                        );
                        
                    $accountOrg = array(           
                        "ICSPOrg"=> nullq($this->get_ICM_section($iOrg,"name","ICSPORG","tagData")),
                        "ICSPOrgType"=> nullq($this->get_ICM_section($iOrg,"name","ICSPORGTYPE","tagData")),
                        "ICSPOrgID"=> nullq($this->get_ICM_section($iOrg,"name","ICSPORGID","tagData")),
                        "Market"=> nullq($this->get_ICM_section($iOrg,"name","ICSPORG","tagData"))
                        );
                        
                    
                    // "Contact_ID"=>"12345",
                        // "ICSPFamilyNumber"=>"122222"
                       
                    $footer = array("Resident"=>"ICM");
                    
                    
                    // create entry for each contact with an entry for each address
                    for  ($ic = 0; $ic < $size_icontact; $ic++) {
                        $count += $ic;    
                        for  ($ia = 0; $ia < $size_iaddress; $ia++) {
                          $count += $ia;
                          $refined_data[] = $account+$contacts[$ic]+$addresses[$ia]+$contactMethod+$integrityCodes+$loyaltyMember+$accountOrg+$footer ;  
                        }
                    }
                    //$refined_data[$count] = array_merge($account+$firstContact+$contacts+$firstAddress+$addresses+$contactMethod+$integrityCodes+$loyaltyMember+$accountOrg+$other+$footer) ;
                    $count++;
                }
                
                $this->ICM_Contacts = $refined_data; // replace with refined data;
// var_dump($this->ICM_Contacts);
                $this->ICM_count = sizeof($this->ICM_Contacts);
                $refined_data=null; // release memory
                $this->performance(__FUNCTION__,"finish");
                return TRUE;
            
          
    
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        }        
        
        
    }

    private function build_roql($oscfield, $value, $type){  // build the roql query to post to OSC
        //$this->performance(__FUNCTION__,"start");
        if(is_null($value)) $value="";
        try{            
            if(strlen($value)>0){
				if($this->roql_qry!="") $this->roql_qry = $this->roql_qry." and ";              
                Switch ($type) {
                	case "statement":
                	   $this->roql_qry = $this->roql_qry." ".$oscfield.$value;
                       break;
                    case "integer":
                       $this->roql_qry = $this->roql_qry." ".$oscfield.$value;
                       break;
    
                    case "string":
                       $this->roql_qry = $this->roql_qry." ".$oscfield."'".$value."'";
                       break;
                }
                
                
                
                $this->do_qry = TRUE;
                return;
            }
            //$this->performance(__FUNCTION__,"finish");
            return;
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage()."[qry:".$this->roql_qry."]");
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage(). "[qry:".$this->roql_qry."]");
        }
               
    }

    private function get_ICM_section($array, $key, $value, $branch="")    {    // search within the returned ICM xml data - data must be parsed to array first!
        //$this->performance(__FUNCTION__,"start");
        $results = array();
        try{
            if (is_array($array)) {
                if (isset($array[$key]) && $array[$key] == $value) {
                    $results[] = $array;
                }
        
                foreach ($array as $subarray) {
                    $results = array_merge($results, $this->get_ICM_section($subarray, $key, $value));
                }
            }
            
            if($branch!=""){
                return $results[0][$branch];
            } else {
                return $results;
            }
           //$this->performance(__FUNCTION__,"finish");
            return $results;
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
    }

    private function compile_output(){          // called from return_output()
        $this->performance(__FUNCTION__,"start");
       
        $compiled_data=array();
        try{
            $OSC_c = sizeof($this->OSC_Contacts); 
            $ICM_c = sizeof($this->ICM_Contacts);
            $OSC_refined = array();
            $ICM_refined = array();
            $refined = array();

        switch($_GET[$this::gv_action]){
                 
            case "retrieve":                        
                if($this->verbose==TRUE){
                    // VERBOSE
                    $refined["Contacts"] = $this->OSC_Contacts+$this->ICM_Contacts;
                          
                } else {
                    // NON VERBOSE
                    // build simple OSC
                    $data = array();
                    for ($i = 0; $i < $OSC_c; $i++) {
                        $data = array();
                        foreach($this->OSC_Contacts[$i] as $key=>$val){
                            $data = array("OSC_ID"=>$this->OSC_Contacts[$i]["OSC_ID"],
                                      "ICSPICMMasterKey"=>$this->OSC_Contacts[$i]["ICSPICMMasterKey"],
                                      "Resident"=>"OSC");
                        }
                        
                        // if(empty($refined["Contacts"])) {
                            // $refined["Contacts"] = array($data);
                        // } else {
                            $refined["Contacts"][] = $data;
                        //}
                    }
                    // build simplpe ICM
                    for ($i = 0; $i < $ICM_c; $i++) {
                        $data = array();
                        foreach($this->ICM_Contacts[$i] as $key=>$val){
                              $data = array("OSC_ID"=>$this->ICM_Contacts[$i]["OSC_ID"],
                                      "ICSPICMMasterKey"=>$this->ICM_Contacts[$i]["ICSPICMMasterKey"],
                                      "Resident"=>"ICM"); 
                        }
                        // if(empty($refined["Contacts"])) {
                            // $refined["Contacts"] = $data;
                        // } else {
                            $refined["Contacts"][] = $data;
                        //}
                    }
                    
                }
                break;
            case "lookup":
                $data = array();
                if(($OSC_c+$ICM_c)==1){
                    for ($i = 0; $i < $OSC_c; $i++) {
                        $data = array();
                        foreach($this->OSC_Contacts[$i] as $key=>$val){
                            $data = array("OSC_ID"=>$this->OSC_Contacts[$i]["OSC_ID"]);
                        }
                        
                        $refined["Contacts"] = $data;
                       
                    }
                } else {
                    $data = array("Report_ID"=>$this->reportID);
                    $refined["Report"] = $data;
                }   
                    // for ($i = 0; $i < $ICM_c; $i++) {
                        // $data = array();
                        // foreach($this->ICM_Contacts[$i] as $key=>$val){
                              // $data = array("OSC_ID"=>$this->ICM_Contacts[$i]["OSC_ID"]); 
                        // }
                        // if(empty($refined["Contacts"])) {
                            // $refined["Contacts"] = $data;
                        // } else {
                            // $refined["Contacts"][] = $data;
                        // }
                    // }
                break;
            }
              
            $compiled_data = $refined;
            // add the Error tag
            $compiled_data["Error"] = array("Success"=>"True");
            $compiled_data["Totals"] = array("OSC"=>$this->OSC_count,"ICM"=>$this->ICM_count); 
                
            
             // Curl
            if ($_GET["curl_data"]==1){  // add curl data if requested
                $curlData = array("http_code"=>$this->http_code, "curl_error"=>$this->error, "curl_info"=>$this->info);    
                $compiled_data["curl_data"] = $curlData;            
            }
            
            // Performance
            if ($_GET["perf_data"]==1){  // add performance data if requested
                $this->performance(__FUNCTION__,"finish"); // add final finish
                $compiled_data["perf_data"] = $this->perf;  
            }
            
            return $compiled_data;
       
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
        } 
    }

    private function pre_check(){               // sepaate function as it may grow for pre-checking of posted data
        if($this->posted[self::pv_market]=="")  $this->return_message("","", $this->posted[self::pv_market]." is required");     
    }

    private function get_curr_interfaceID(){
        // strip the server name to get domain, a better way is probably available for this
        $interface = str_replace(".custhelp.com","",str_replace("-", "_", $_SERVER['SERVER_NAME']));
        $qry = "Select ID from SiteInterface where LookupName='".$interface."'";
        $res = $this->get_OSC_query($qry);
        $f = $res->next();
        return $f['ID']; // return the interface record ID 
    }

    private function get_OSC_contact($cid) {
        $this->performance(__FUNCTION__,"start");
        if (is_numeric($cid)) { // only do it if its a number 
            try {
                $contactRec = RNCPHP\Contact::fetch($cid); // get contact
                $this->performance(__FUNCTION__,"finish");
                return $contactRec;
            } catch (RNCPHP\ConnectAPIError $err) {
                $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage());
            } catch (Exception $err) {
                $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage());
            }
        } else {
            $this->performance(__FUNCTION__,"finish");
            return null;
        }
    }

    private function get_OSC_objects($qry){
        //returns all objects from query
        $this->performance(__FUNCTION__,"start");
        try{
            $res = RNCPHP\ROQL::queryObject( $qry );
            $resObjects=$res->next(); // get records object
            $this->performance(__FUNCTION__,"finish");
            return $resObjects;
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",  "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage(). "[qry:".$this->roql_qry."]");
        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": " . $err->getMessage(). "[qry:".$this->roql_qry."]");
        }
    }

    private function get_OSC_query($qry) {
        $this->performance(__FUNCTION__,"start");
        //returns all array rows from query
        try{
            $res = RNCPHP\ROQL::query( $qry )->next(); // get records array
            $this->performance(__FUNCTION__,"finish"); 
            return $res;
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",   "ConnectAPIError in ".__FUNCTION__.": " . $err->getMessage(). "[qry:".$this->roql_qry."]");
        } catch (Exception $err) {
            $this->return_message("","",   "Exception in ".__FUNCTION__.": " . $err->getMessage(). "[qry:".$this->roql_qry."]");
        }
    }

    private function create_OSC_contact($posted_contact=array()) { // create contact in OSC
        $this->performance(__FUNCTION__,"start");
        try {
            $data = array();
            if(!empty($posted_contact)) {
            	// create from posted values
                $data = $posted_contact;
            } else {
                if(!empty($this->ICM_Contacts)) {
                    //echo "here"; 
                    $data = $this->ICM_Contacts[0];  // create from SINGLE icm_contacts
                    //echo $data["Email - Primary"];
                    //echo "------";
                    //echo $data["Email"];
                    $data["Email"] = $data["Email - Primary"];
                }
            }    
            
            if(empty($data))  return FALSE;

// will need a foreach if i keep the icm contacts creation part in        
            $contact = new RNCPHP\Contact();
            //$contact->Address = new RNCPHP\Address();  // not using stock address fields
        	$market  = RNCPHP\Market\Markets::fetch($this->market->Name);
        	
            $contact->Name = new RNCPHP\PersonName;   
            
            foreach($data as $key=>$val){
                if(strlen($val)>0){
                               
                    switch($key){
                        
                        case "First_Name" :  $contact->Name->First = $data["First_Name"];
                        case "Last_Name" :  $contact->Name->First = $data["Last_Name"];                           
                        
                        case "ICSPContactType" :
                        
                            if($data["ICSPContactType"]=="Individual" || $data["ICSPContactType"]=="") $data["ICSPContactType"]="Private";
                            
                            $contact->ContactType->LookupName->Value = $data["ICSPContactType"];
                            break;
                     
                        case "ICSPICMMasterKey" :  $contact->CustomFields->CO->ICSPICMMasterKey = $data["ICSPICMMasterKey"]; break;
                        case "ICSPContactStatus" :  $contact->CustomFields->CO->ICSPContactStatus->LookupName->Value = $data["ICSPContactStatus"];break;
                        case "ICSPCustomerNum" :  $contact->CustomFields->CO->ICSPCustomerNum = $data["ICSPCustomerNum"];break;
                        case "ICSPCountryId" :  //$contact->CustomFields->CO->ICSPCountryId->LookupName = $data["ICSPCountryId"];break;
                        case "ICSPLangCode" :  //$contact->CustomFields->CO->ICSPLangCode = $data["ICSPLangCode"];break;
                        case "ICSPPreferredStore" :  $contact->CustomFields->CO->RFC->rfc_tp_tp_store->LookupName->Value = $data["ICSPPreferredStore"];break;
                        case "ICSPPrefType" :  $contact->CustomFields->CO->ICSPPrefType = $data["ICSPPrefType"];break;                   
                        case "ICSPICMCustomerRefId" :  $contact->CustomFields->CO->ICSPICMCustomerRefId = $data["ICSPICMCustomerRefId"];break;
                        case "ICSPMiddleName" :  echo strlen($data["ICSPMiddleName"]); break;//$contact->CustomFields->CO->ICSPMiddleName = $data["ICSPMiddleName"];
                        case "ICSPTitle" :  $contact->CustomFields->CO->ICSPTitle = $data["ICSPTitle"];break;
                        case "ICSPGender" :  $contact->CustomFields->CO->ICSPGender = $data["ICSPGender"];break;
                        case "ICSPProtIden" :  $contact->CustomFields->CO->ICSPProtIden = $data["ICSPProtIden"];break;                   
                        case "ICSPICMAddressRefId" :  $contact->CustomFields->CO->ICSPICMAddressRefId = $data["ICSPICMAddressRefId"];break;
                        case "ICSPStreet" :  $contact->CustomFields->CO->ICSPStreet = $data["ICSPStreet"];break;
                        case "ICSPCity" :  $contact->CustomFields->CO->ICSPCity = $data["ICSPCity"];break;
                        case "ICSPPostalCode" :  $contact->CustomFields->CO->ICSPPostCode = $data["ICSPPostalCode"];break;
                        case "ICSPCountry" :  $contact->CustomFields->CO->ICSPCountry->LookupName = $data["ICSPCountry"];break;
                        case "ICSPCounty" :  $contact->CustomFields->CO->ICSPCounty = $data["ICSPCounty"];break;
                        case "ICSPProvince" :  $contact->CustomFields->CO->ICSPProvince = $data["ICSPProvince"];break;
                        case "ICSPState" :  $contact->CustomFields->CO->ICSPState = $data["ICSPState"];break;
                        case "ICSPHouseNum" :  $contact->CustomFields->CO->ICSPHouseNum = $data["ICSPHouseNum"];break;
                        case "ICSPApartNum" :  $contact->CustomFields->CO->ICSPApartNum = $data["ICSPApartNum"];break;
                        case "ICSPFloorNum" :  $contact->CustomFields->CO->ICSPFloorNum = $data["ICSPFloorNum"];break;
                        case "ICSPWard" :  $contact->CustomFields->CO->ICSPWard = $data["ICSPWard"];break;
                        case "ICSPCName" :  $contact->CustomFields->CO->ICSPCName = $data["ICSPCName"];break;
                        case "ICSPStrAd1" :  $contact->CustomFields->CO->ICSPStrAd1 = $data["ICSPStrAd1"];break;
                        case "ICSPStrAd2" :  $contact->CustomFields->CO->ICSPStrAd2 = $data["ICSPStrAd2"];break;
                        case "ICSPStrAd3" :  $contact->CustomFields->CO->ICSPStrAd3 = $data["ICSPStrAd3"];break;
                        case "ICSPStrAd4" :  $contact->CustomFields->CO->ICSPStrAd4 = $data["ICSPStrAd4"];break;
                        case "ICSPStrAd5" :  $contact->CustomFields->CO->ICSPStrAd5 = $data["ICSPStrAd5"];break;
                        case "ICSPStrAd6" :  $contact->CustomFields->CO->ICSPStrAd6 = $data["ICSPStrAd6"];break;
                        case "ICSPStrAd7" :  $contact->CustomFields->CO->ICSPStrAd7 = $data["ICSPStrAd7"];break;                   
                        case "ICSPContMethodId" :  $contact->CustomFields->CO->ICSPContMethodId = $data["ICSPContMethodId"];break;
                        case "IntegrityType" :  //$contact->CustomFields->CO->IntegrityType = $data["IntegrityType"];
                        					break;
                        case "ConsentCode" : //$contact->CustomFields->CO->ConsentCode = $data["ConsentCode"];
                        					break;
                        case "ICSPLoyaltyCode" :  $contact->CustomFields->CO->ICSPLoyaltyCode = $data["ICSPLoyaltyCode"];break;
                        case "ICSPLoyaltyStatus" :  $contact->CustomFields->CO->ICSPLoyaltyStatus = $data["ICSPLoyaltyStatus"];break;
                        case "ICSPOrg" :  $contact->CustomFields->CO->ICSPOrg = $data["ICSPOrg"];break;
                        case "ICSPOrgType" :  $contact->CustomFields->CO->ICSPOrgType = $data["ICSPOrgType"];break;
                        case "ICSPOrgId" :  $contact->CustomFields->CO->ICSPICMOrgId = $data["ICSPOrgId"];break;
                        
                        case "ICSPDialingCodeMobile" : //$contact->CustomFields->CO->ICSPDialingCodeMobile = $data["ICSPDialingCodeMobile"];break;
                        case "ICSPMobilePhone" : $contact->CustomFields->CO->ICSPMobilePhone = $data["ICSPMobilePhone"];break;
                        case "ICSPDialingCodeHome" : //$contact->CustomFields->CO->ICSPDialingCodeHome = $data["ICSPDialingCodeHome"];break;
                        case "ICSPHomePhone" : $contact->CustomFields->CO->ICSPHomePhone = $data["ICSPHomePhone"];break;
                        case "ICSPDialingCodeWork" : //$contact->CustomFields->CO->ICSPDialingCodeWork = $data["ICSPDialingCodeWork"];break;
                        case "ICSPWorkPhone" : $contact->CustomFields->CO->ICSPWorkPhone = $data["ICSPWorkPhone"];break;
 						Case "Market" : $contact->CustomFields->Market->market_name = $market ;break;                 
                        case "Email" : 
                            //Add email address
                            $contact->Emails = new RNCPHP\EmailArray();
                            $contact->Emails[0] = new RNCPHP\Email();
                            $contact->Emails[0]->AddressType=new RNCPHP\NamedIDOptList();
                            $contact->Emails[0]->AddressType->ID = 0; //Primary email obvs
                            $contact->Emails[0]->Address = $data["Email"];
                            $contact->Emails[0]->Invalid = false;
                            break;
                    }
                }
            }
                                    
            $contact->save(RNCPHP\RNObject::SuppressAll);
            $this->performance(__FUNCTION__,"saved");

            RNCPHP\ConnectAPI::commit(RNCPHP\RNObject::SuppressAll);
            $this->performance(__FUNCTION__,"commit");

            return $contact->ID;            
        } catch (RNCPHP\ConnectAPIError $err) {
            $this->return_message("","",   "ConnectAPIError [".$err->getCode()."] in ".__FUNCTION__.": " . $err->getMessage());
        } catch (Exception $err) {
            $this->return_message("","",   "Exception in ".__FUNCTION__.": " . $err->getMessage());
        }
    }

    private function add_quotes($stringVal){    // to add quotes if data exists for use ion the REST message creation.
        if($stringVal!=""){
            return "='".$stringVal."'";
        }
        return $stringVal;
    }
    
    private function SOAP_find($org, $phone){
        return utf8_encode('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:b2b="B2B_FindCustomerMasterICSPReqABCS" xmlns:ns="ikea.com/system/iip/FindCustomerMasterICSPReqABCS/1.0/">
       <soapenv:Header/>
       <soapenv:Body>
          <b2b:FindCustomerMasterICSPReqABCS>
             <!--Optional:-->
             <ns:FindCustomerMasterRequest>
                <ns:ICOWSearch_Input>
                   <ns:ListOfSwiOrganizationExtIO lastpage="0">
                      <!--1 or more repetitions:-->
                      <ns:Account operation="" searchspec="">
                         <!--Optional:-->
                         <ns:ICSPICMMasterKey></ns:ICSPICMMasterKey>
                         <!--Optional:-->
                         <ns:ICSPCompanyName></ns:ICSPCompanyName>
                         <!--Optional:-->
                         <ns:ContactType></ns:ContactType>
                         <!--Optional:-->
                         <ns:ICSPContactStatus></ns:ICSPContactStatus>
                         <!--Optional:-->
                         <ns:ICSPCustomerNum></ns:ICSPCustomerNum>
                         <!--Optional:-->
                         <ns:ICSPCountryId></ns:ICSPCountryId>
                         <!--Optional:-->
                         <ns:ICSPLangCode></ns:ICSPLangCode>
                         <!--Optional:-->
                         <ns:ICSPPreferredStore></ns:ICSPPreferredStore>
                         <!--Optional:-->
                         <ns:ICSPPrefType></ns:ICSPPrefType>
                         <!--Optional:-->
                         <ns:ICSPICMCreatedDTime></ns:ICSPICMCreatedDTime>
                         <!--Optional:-->
                         <ns:ICSPICMUpdatedDTime></ns:ICSPICMUpdatedDTime>
                         <!--Optional:-->
                         <ns:ListOfContact lastpage="0">
                            <ns:Contact IsPrimaryMVG="" searchspec="">
                               <!--Optional:-->
                               <ns:ICSPICMCustomerRefId></ns:ICSPICMCustomerRefId>
                               <!--Optional:-->
                               <ns:first_name></ns:first_name>
                               <!--Optional:-->
                               <ns:ICSPMiddleName></ns:ICSPMiddleName>
                               <!--Optional:-->
                               <ns:last_name></ns:last_name>
                               <!--Optional:-->
                               <ns:ICSPTitle></ns:ICSPTitle>
                               <!--Optional:-->
                               <ns:ICSPGender></ns:ICSPGender>
                               <!--Optional:-->
                               <ns:ICSPProtIden></ns:ICSPProtIden>
                            </ns:Contact>
                         </ns:ListOfContact>
                         <!--Optional:-->
                         <ns:ListOfAddress lastpage="0">
                            <!--Zero or more repetitions:-->
                            <ns:Address IsPrimaryMVG="" searchspec="">
                               <!--Optional:-->
                               <ns:ICSPICMAddressRefId></ns:ICSPICMAddressRefId>
                               <!--Optional:-->
                               <ns:ICSPStreetAddress></ns:ICSPStreetAddress>
                               <!--Optional:-->
                               <ns:ICSPCity></ns:ICSPCity>
                               <!--Optional:-->
                               <ns:ICSPPostalCode></ns:ICSPPostalCode>
                               <!--Optional:-->
                               <ns:ICSPCountry></ns:ICSPCountry>
                               <!--Optional:-->
                               <ns:ICSPCounty></ns:ICSPCounty>
                               <!--Optional:-->
                               <ns:ICSPProvince></ns:ICSPProvince>
                               <!--Optional:-->
                               <ns:ICSPState></ns:ICSPState>
                               <!--Optional:-->
                               <ns:ICSPHouseNum></ns:ICSPHouseNum>
                               <!--Optional:-->
                               <ns:ICSPApartNum></ns:ICSPApartNum>
                               <!--Optional:-->
                               <ns:ICSPFloorNum></ns:ICSPFloorNum>
                               <!--Optional:-->
                               <ns:ICSPWard></ns:ICSPWard>
                               <!--Optional:-->
                               <ns:ICSPCName></ns:ICSPCName>
                               <!--Optional:-->
                               <ns:ICSPStrAd1></ns:ICSPStrAd1>
                               <!--Optional:-->
                               <ns:ICSPStrAd2></ns:ICSPStrAd2>
                               <!--Optional:-->
                               <ns:ICSPStrAd3></ns:ICSPStrAd3>
                               <!--Optional:-->
                               <ns:ICSPStrAd4></ns:ICSPStrAd4>
                               <!--Optional:-->
                               <ns:ICSPStrAd5></ns:ICSPStrAd5>
                               <!--Optional:-->
                               <ns:ICSPStrAd6></ns:ICSPStrAd6>
                               <!--Optional:-->
                               <ns:ICSPStrAd7></ns:ICSPStrAd7>
                            </ns:Address>
                         </ns:ListOfAddress>
                         <!--Optional:-->
                         <ns:ListOfContactMethod lastpage="0">
                            <!--Zero or more repetitions:-->
                            <ns:ContactMethod searchspec="" IsPrimaryMVG="">
                               <!--Optional:-->
                               <ns:ICSPContMethodId></ns:ICSPContMethodId>
                               <!--Optional:-->
                               <ns:Type></ns:Type>
                               <!--Optional:-->
                               <ns:Value>='."'07796611852'".'</ns:Value>
                               <!--Optional:-->
                               <ns:Extension></ns:Extension>
                               <!--Optional:-->
                               <ns:AreaCode></ns:AreaCode>
                               <!--Optional:-->
                               <ns:CountryCode></ns:CountryCode>
                               <!--Optional:-->
                               <ns:ListOfPreferredContactMethod lastpage="0">
                                  <!--Zero or more repetitions:-->
                                  <ns:PreferredContactMethod searchspec="" IsPrimaryMVG="">
                                     <!--Optional:-->
                                     <ns:Id></ns:Id>
                                     <!--Optional:-->
                                     <ns:ContextType></ns:ContextType>
                                     <!--Optional:-->
                                     <ns:Priority></ns:Priority>
                                  </ns:PreferredContactMethod>
                               </ns:ListOfPreferredContactMethod>
                            </ns:ContactMethod>
                         </ns:ListOfContactMethod>
                         <!--Optional:-->
                         <ns:ListOfIntegrityCode lastpage="0">
                            <!--1 or more repetitions:-->
                            <ns:IntegrityCode searchspec="">
                               <!--Optional:-->
                               <ns:IntegrityType></ns:IntegrityType>
                               <!--Optional:-->
                               <ns:ConsentCode></ns:ConsentCode>
                            </ns:IntegrityCode>
                         </ns:ListOfIntegrityCode>
                         <!--Optional:-->
                         <ns:ListOfLoyaltyMember lastpage="0">
                            <!--Zero or more repetitions:-->
                            <ns:LoyaltyMember searchspec="">
                               <!--Optional:-->
                               <ns:ICSPLoyaltyCode></ns:ICSPLoyaltyCode>
                               <!--Optional:-->
                               <ns:ICSPLoyaltyStatus></ns:ICSPLoyaltyStatus>
                               <!--Optional:-->
                               <ns:ListOfMembershipCard lastpage="0">
                                  <!--1 or more repetitions:-->
                                  <ns:MembershipCard searchspec="">
                                     <!--Optional:-->
                                     <ns:ICSPLoyaltyNum></ns:ICSPLoyaltyNum>
                                  </ns:MembershipCard>
                               </ns:ListOfMembershipCard>
                            </ns:LoyaltyMember>
                         </ns:ListOfLoyaltyMember>
                         <!--Optional:-->
                         <ns:ListOfAccountOrganization lastpage="0">
                            <!--1 or more repetitions:-->
                            <ns:AccountOrganization IsPrimaryMVG="" searchspec="">
                               <!--Optional:-->
                               <ns:ICSPOrg>='."'DE'".'</ns:ICSPOrg>
                               <!--Optional:-->
                               <ns:ICSPOrgType></ns:ICSPOrgType>
                               <!--Optional:-->
                               <ns:ICSPOrgId></ns:ICSPOrgId>
                            </ns:AccountOrganization>
                         </ns:ListOfAccountOrganization>
                      </ns:Account>
                   </ns:ListOfSwiOrganizationExtIO>
                </ns:ICOWSearch_Input>
             </ns:FindCustomerMasterRequest>
          </b2b:FindCustomerMasterICSPReqABCS>
       </soapenv:Body>
    </soapenv:Envelope>');
    }

	private function REST_find(){
	
        if($this->posted[self::pv_email]!=""){
            $contactLookupType = "='EMAIL'";
            $contactLookupVal = $this->add_quotes($this->posted[self::pv_email]);
        } else {
            $contactLookupType = "";
            $contactLookupVal = $this->add_quotes($this->posted[self::pv_phone]);
        }
        
        return utf8_encode('
        <tns:FindCustomerMasterRequest xmlns:tns="ikea.com/system/iip/FindCustomerMasterICSPReqABCS/1.0/">
            <tns:ICOWSearch_Input>
            
               <tns:ListOfSwiOrganizationExtIO>
                  <tns:Account operation="" searchspec="">
                     <tns:ICSPICMMasterKey>'.$this->add_quotes($this->posted[self::pv_icmmasterkey]).'</tns:ICSPICMMasterKey>
                         <tns:ICSPCompanyName>'.$this->add_quotes($this->posted[self::pv_companyname]).'</tns:ICSPCompanyName>
                     <tns:ContactType></tns:ContactType>
                     <tns:ICSPContactStatus></tns:ICSPContactStatus>
                     <tns:ICSPCustomerNum></tns:ICSPCustomerNum>
                     <tns:ICSPCountryId></tns:ICSPCountryId>
                     <tns:ICSPLangCode></tns:ICSPLangCode>
                     <tns:ICSPPreferredStore></tns:ICSPPreferredStore>
                     <tns:ICSPPrefType></tns:ICSPPrefType>
                     <tns:ICSPICMCreatedDTime></tns:ICSPICMCreatedDTime>
                     <tns:ICSPICMUpdatedDTime></tns:ICSPICMUpdatedDTime>
                     <tns:ListOfContact lastpage="0">
                        <tns:Contact IsPrimaryMVG="" searchspec="">
                           <tns:ICSPICMCustomerRefId>'.$this->add_quotes($this->posted[self::pv_icmcustid]).'</tns:ICSPICMCustomerRefId>
                               <tns:first_name>'.$this->add_quotes($this->posted[self::pv_firstname]).'</tns:first_name>
                               <tns:ICSPMiddleName></tns:ICSPMiddleName>
                               <tns:last_name>'.$this->add_quotes($this->posted[self::pv_lastname]).'</tns:last_name>
                           <tns:ICSPTitle></tns:ICSPTitle>
                           <tns:ICSPGender></tns:ICSPGender>
                           <tns:ICSPProtIden></tns:ICSPProtIden>
                        </tns:Contact>
                     </tns:ListOfContact>
                     <tns:ListOfAddress lastpage="0">
                        <tns:Address IsPrimaryMVG="" searchspec="">
                           <tns:ICSPICMAddressRefId></tns:ICSPICMAddressRefId>
                           <tns:ICSPStreetAddress></tns:ICSPStreetAddress>
                            <tns:ICSPCity>'.$this->add_quotes($this->posted[self::pv_city]).'</tns:ICSPCity>
                                <tns:ICSPPostalCode>'.$this->add_quotes($this->posted[self::pv_postalcode]).'</tns:ICSPPostalCode>
                                <tns:ICSPCountry>'.$this->add_quotes($this->posted[self::pv_country]).'</tns:ICSPCountry>
                            <tns:ICSPCounty></tns:ICSPCounty>
                            <tns:ICSPProvince></tns:ICSPProvince>
                            <tns:ICSPState></tns:ICSPState>
                            <tns:ICSPHouseNum></tns:ICSPHouseNum>
                            <tns:ICSPApartNum></tns:ICSPApartNum>
                            <tns:ICSPFloorNum></tns:ICSPFloorNum>
                            <tns:ICSPWard></tns:ICSPWard>
                            <tns:ICSPCName></tns:ICSPCName>
                            <tns:ICSPStrAd1></tns:ICSPStrAd1>
                            <tns:ICSPStrAd2></tns:ICSPStrAd2>
                            <tns:ICSPStrAd3></tns:ICSPStrAd3>
                            <tns:ICSPStrAd4></tns:ICSPStrAd4>
                            <tns:ICSPStrAd5></tns:ICSPStrAd5>
                            <tns:ICSPStrAd6></tns:ICSPStrAd6>
                            <tns:ICSPStrAd7></tns:ICSPStrAd7>
                        </tns:Address>
                     </tns:ListOfAddress>
                      <tns:ListOfContactMethod lastpage="0">
                         <tns:ContactMethod searchspec="" IsPrimaryMVG="">
                            <tns:ICSPContMethodId></tns:ICSPContMethodId>
                                <tns:Type>'.$contactLookupType.'</tns:Type>
                                <tns:Value>'.$contactLookupVal.'</tns:Value>
                            <tns:Extension></tns:Extension>
                            <tns:AreaCode></tns:AreaCode>
                            <tns:CountryCode></tns:CountryCode>
                            <tns:ListOfPreferredContactMethod lastpage="0">
                               <tns:PreferredContactMethod searchspec="" IsPrimaryMVG="">
                                  <tns:Id></tns:Id>
                                  <tns:ContextType></tns:ContextType>
                                  <tns:Priority></tns:Priority>
                              </tns:PreferredContactMethod>
                           </tns:ListOfPreferredContactMethod>
                        </tns:ContactMethod>                   
                     </tns:ListOfContactMethod>
                      <tns:ListOfIntegrityCode lastpage="0">
                         <tns:IntegrityCode searchspec="">
                            <tns:IntegrityType></tns:IntegrityType>
                            <tns:ConsentCode></tns:ConsentCode>
                        </tns:IntegrityCode>
                     </tns:ListOfIntegrityCode>
                      <tns:ListOfLoyaltyMember lastpage="0">
                         <tns:LoyaltyMember searchspec="">
                            <tns:ICSPLoyaltyCode></tns:ICSPLoyaltyCode>
                            <tns:ICSPLoyaltyStatus></tns:ICSPLoyaltyStatus>
                            <tns:ListOfMembershipCard lastpage="0">
                               <tns:MembershipCard searchspec="">
                                 <tns:ICSPLoyaltyNum>'.$this->add_quotes($this->posted[self::pv_loyaltyno]).'</tns:ICSPLoyaltyNum>
                              </tns:MembershipCard>
                           </tns:ListOfMembershipCard>
                        </tns:LoyaltyMember>
                     </tns:ListOfLoyaltyMember>
                      <tns:ListOfAccountOrganization lastpage="0">
                         <tns:AccountOrganization IsPrimaryMVG="" searchspec="">
                            <tns:ICSPOrg>'.$this->add_quotes($this->posted[self::pv_market]).'</tns:ICSPOrg>
                            <tns:ICSPOrgType></tns:ICSPOrgType>
                            <tns:ICSPOrgId></tns:ICSPOrgId>
                        </tns:AccountOrganization>
                     </tns:ListOfAccountOrganization>
                  </tns:Account>
                             </tns:ListOfSwiOrganizationExtIO>
                </tns:ICOWSearch_Input>
             </tns:FindCustomerMasterRequest>
        ');

    }

    private function oldREST_find(){

        if($this->posted[self::pv_email]!=""){
            $contactLookupType = "='EMAIL'";
            $contactLookupVal = $this->add_quotes($this->posted[self::pv_email]);
        } else {
            $contactLookupType = "";
            $contactLookupVal = $this->add_quotes($this->posted[self::pv_phone]);
        }

        //$contactLookupType = "EMAIL" (UPPERCASE) OR "" FOR PHONE (BLANK)
        return utf8_encode('<tns:FindCustomerMasterRequest xmlns:tns="ikea.com/system/iip/FindCustomerMasterICSPReqABCS/1.0/">
                <tns:ICOWSearch_Input>
                   <tns:ListOfSwiOrganizationExtIO>
                      <tns:Account operation="" searchspec="">
                         <tns:ICSPICMMasterKey>'.$this->add_quotes($this->posted[self::pv_icmmasterkey]).'</tns:ICSPICMMasterKey>
                         <tns:ICSPCompanyName>'.$this->add_quotes($this->posted[self::pv_companyname]).'</tns:ICSPCompanyName>
                         <tns:ContactType></tns:ContactType>
                         <tns:ICSPContactStatus></tns:ICSPContactStatus>
                         <tns:ICSPCustomerNum></tns:ICSPCustomerNum>
                         <tns:ICSPCountryId></tns:ICSPCountryId>
                         <tns:ICSPLangCode></tns:ICSPLangCode>
                         <tns:ICSPPreferredStore></tns:ICSPPreferredStore>
                         <tns:ICSPPrefType></tns:ICSPPrefType>
                         <tns:ICSPICMCreatedDTime></tns:ICSPICMCreatedDTime>
                         <tns:ICSPICMUpdatedDTime></tns:ICSPICMUpdatedDTime>
                         <tns:ListOfContact lastpage="0">
                            <tns:Contact IsPrimaryMVG="" searchspec="">
                               <tns:ICSPICMCustomerRefId>'.$this->add_quotes($this->posted[self::pv_icmcustid]).'</tns:ICSPICMCustomerRefId>
                               <tns:first_name>'.$this->add_quotes($this->posted[self::pv_firstname]).'</tns:first_name>
                               <tns:ICSPMiddleName></tns:ICSPMiddleName>
                               <tns:last_name>'.$this->add_quotes($this->posted[self::pv_lastname]).'</tns:last_name>
                               <tns:ICSPTitle></tns:ICSPTitle>
                               <tns:ICSPGender></tns:ICSPGender>
                               <tns:ICSPProtIden></tns:ICSPProtIden>
                           </tns:Contact>
                         </tns:ListOfContact>
                         <tns:ListOfAddress lastpage="0">
                            <tns:Address IsPrimaryMVG="" searchspec="">
                               <tns:ICSPICMAddressRefId></tns:ICSPICMAddressRefId>
                               <tns:ICSPStreetAddress></tns:ICSPStreetAddress>
                                <tns:ICSPCity>'.$this->add_quotes($this->posted[self::pv_city]).'</tns:ICSPCity>
                                <tns:ICSPPostalCode>'.$this->add_quotes($this->posted[self::pv_postalcode]).'</tns:ICSPPostalCode>
                                <tns:ICSPCountry>'.$this->add_quotes($this->posted[self::pv_country]).'</tns:ICSPCountry>
                                <tns:ICSPCounty></tns:ICSPCounty>
                                <tns:ICSPProvince></tns:ICSPProvince>
                                <tns:ICSPState></tns:ICSPState>
                                <tns:ICSPHouseNum></tns:ICSPHouseNum>
                                <tns:ICSPApartNum></tns:ICSPApartNum>
                                <tns:ICSPFloorNum></tns:ICSPFloorNum>
                                <tns:ICSPWard></tns:ICSPWard>
                                <tns:ICSPCName></tns:ICSPCName>
                                <tns:ICSPStrAd1></tns:ICSPStrAd1>
                                <tns:ICSPStrAd2></tns:ICSPStrAd2>
                                <tns:ICSPStrAd3></tns:ICSPStrAd3>
                                <tns:ICSPStrAd4></tns:ICSPStrAd4>
                                <tns:ICSPStrAd5></tns:ICSPStrAd5>
                                <tns:ICSPStrAd6></tns:ICSPStrAd6>
                                <tns:ICSPStrAd7></tns:ICSPStrAd7>
                            </tns:Address>
                         </tns:ListOfAddress>
                          <tns:ListOfContactMethod lastpage="0">
                             <tns:ContactMethod searchspec="" IsPrimaryMVG="">
                                <tns:ICSPContMethodId></tns:ICSPContMethodId>
                                <tns:Type>'.$contactLookupType.'</tns:Type>
                                <tns:Value>'.$contactLookupVal.'</tns:Value>
                                <tns:Extension></tns:Extension>
                                <tns:AreaCode></tns:AreaCode>
                                <tns:CountryCode></tns:CountryCode>
                                <tns:ListOfPreferredContactMethod lastpage="0">
                                   <tns:PreferredContactMethod searchspec="" IsPrimaryMVG="">
                                      <tns:Id></tns:Id>
                                      <tns:ContextType></tns:ContextType>
                                      <tns:Priority></tns:Priority>
                                  </tns:PreferredContactMethod>
                               </tns:ListOfPreferredContactMethod>
                            </tns:ContactMethod>
                         </tns:ListOfContactMethod>
                          <tns:ListOfIntegrityCode lastpage="0">
                             <tns:IntegrityCode searchspec="">
                                <tns:IntegrityType></tns:IntegrityType>
                                <tns:ConsentCode></tns:ConsentCode>
                            </tns:IntegrityCode>
                         </tns:ListOfIntegrityCode>
                          <tns:ListOfLoyaltyMember lastpage="0">
                             <tns:LoyaltyMember searchspec="">
                                <tns:ICSPLoyaltyCode></tns:ICSPLoyaltyCode>
                                <tns:ICSPLoyaltyStatus></tns:ICSPLoyaltyStatus>
                                <tns:ListOfMembershipCard lastpage="0">
                                   <tns:MembershipCard searchspec="">
                                      <tns:ICSPLoyaltyNum>'.$this->add_quotes($this->posted[self::pv_loyaltyno]).'</tns:ICSPLoyaltyNum>
                                  </tns:MembershipCard>
                               </tns:ListOfMembershipCard>
                            </tns:LoyaltyMember>
                         </tns:ListOfLoyaltyMember>
                          <tns:ListOfAccountOrganization lastpage="0">
                             <tns:AccountOrganization IsPrimaryMVG="" searchspec="">
                                <tns:ICSPOrg>'.$this->add_quotes($this->posted[self::pv_market]).'</tns:ICSPOrg>
                                <tns:ICSPOrgType></tns:ICSPOrgType>
                                <tns:ICSPOrgId></tns:ICSPOrgId>
                            </tns:AccountOrganization>
                         </tns:ListOfAccountOrganization>
                      </tns:Account>
                   </tns:ListOfSwiOrganizationExtIO>
                </tns:ICOWSearch_Input>
             </tns:FindCustomerMasterRequest>
        ');

    }

	private function return_message($enquiry, $contacts, $message, $success="False"){ // as per TDD 2.1.2.1 figure 3
	    // json message delivery 
	    $compiled_data = array();
		if($contacts=="" && !is_array($contacts)) $contacts = array();
	
		$compiled_data["Contacts"]	= $contacts;
				
		// Enquiries
		if(!empty($enquiry))  $compiled_data["supportEnquiry"] = $enquiry;
		
		// Errors
		$compiled_data["Error"]		= array(
		                            	"Success"=>$success,
		                            	"Message"=>$message
		                        		);
		// Totals		                        		
		$compiled_data["Totals"]	= array(
		            					"OSC"=>$this->OSC_count,
		            					"ICM"=>$this->ICM_count		            				
		            					); 
		if($this->searchSE==TRUE) $compiled_data["Totals"]["OpenSE"] = $this->open_SE_count;
    			  		
		// Curl
        if ($_GET["curl_data"]==1){  // add curl data if requested
            $curlData = array("http_code"=>$this->http_code, "curl_error"=>$this->error, "curl_info"=>$this->info);    
            $compiled_data["curl_data"] = $curlData;            
        }
        
        // Performance
        if ($_GET["perf_data"]==1){  // add performance data if requested
            $this->performance(__FUNCTION__,"finish"); // add final finish
            $compiled_data["perf_data"] = $this->perf;  
        }            		
	            		
	    header('Content-type: application/json');
	    echo json_encode($compiled_data);
	    exit;
	}

    private function do_ICM_comms() {              // main comm function to send the message to the B2B url
        // puts the returned data into $this->ICM_Contacts
   
        $this->performance(__FUNCTION__,"start");
        $this->http_code=0;
        
        try{
            $headers =  array(
                'Accept-Encoding: ',
                'Content-Type: text/xml;charset=UTF-8',
                'Host: '.$this->host,
                'Connection: Keep-Alive',
                'User-Agent: Apache-HttpClient/4.1.1 (java 1.5)',
                'SOAPAction: '.$this->action,
                'Content-Length: '.strlen($this->detail)
            );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->detail);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_ENCODING, '');

            switch($this->auth_type){
                case "basic":    // Basic Authentication
                    //echo "</br></br>Using Basic Authentication with ".$this->login."</br></br>";
                    //curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                    curl_setopt($ch, CURLOPT_USERPWD, urlencode($this->login).':'.urlencode($this->password));
                    break;
                case "certs":    // Certificates
                    //echo "</br></br>Using Certificated Authentication</br></br>";
                    $root_cert = sprintf('/cgi-bin/%s.db/certs/uca/ikeadt-root-ca.pem', $this->db_name);
                    $client_cert = sprintf('/cgi-bin/%s.db/certs/uca/b2b.icm.ikeadt.com.pem', $this->db_name);

                    if (file_exists($root_cert)) {
                        echo "The root cert $root_cert exists</br>";
                    } else {
                        echo "The root cert $root_cert does not exist</br>";
                    }

                    if (file_exists($client_cert)) {
                        echo "The client cert $client_cert exists</br>";
                    } else {
                        echo "The client cert $client_cert does not exist</br>";
                    }

                    curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
                    curl_setopt($ch, CURLOPT_CAINFO, $root_cert);
                    curl_setopt($ch, CURLOPT_SSLCERT, $client_cert);
                    curl_setopt($ch, CURLOPT_CAPATH, sprintf('/cgi-bin/%s.db/certs/uca', $this->db_name));
                    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                    break;
            }

            $this->ICM_Contacts = curl_exec($ch);
//var_dump($this->ICM_Contacts);

            $this->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->info = curl_getinfo($ch);
            $this->error = curl_error($ch);
            //echo_details($http_code, $ci, $ce, $response);

            curl_close($ch);
            $this->performance(__FUNCTION__,"finish");
            
            if ($this->http_code == "200") {
                return TRUE; 
            } else {
                return FALSE;
            }
         

        } catch (Exception $err) {
            $this->return_message("","",  "Exception in ".__FUNCTION__.": ".$err->getMessage()) ;
        }
    }
}

class xml2Array {
    // xml parser to array for amending and refining data
    var $arrOutput = array();
    var $resParser;
    var $strXmlData;

    function parse($strInputXML) {
    try{
        $this -> resParser = xml_parser_create();

        xml_set_object($this -> resParser, $this);
        xml_set_element_handler($this -> resParser, "tagOpen", "tagClosed");

        xml_set_character_data_handler($this -> resParser, "tagData");

        $this -> strXmlData = xml_parse($this -> resParser, $strInputXML);
        
        if (!$this -> strXmlData) {
            return array();
            //$this->return_message("","", (sprintf("XML error: %s at line %d", xml_error_string(xml_get_error_code($this -> resParser)), xml_get_current_line_number($this -> resParser))));
        }

        xml_parser_free($this -> resParser);
        } catch (Exception $err) {
            echo "Exception in ".__FUNCTION__.": ".$err->getMessage() ;
        }
        return $this -> arrOutput;
    }

    function tagOpen($parser, $name, $attrs) {
        $name = str_replace("TNS:", "", $name); // icm data contains a prefix of TNS:
        $tag = array("name" => $name, "attrs" => $attrs);
        array_push($this -> arrOutput, $tag);
    }

    function tagData($parser, $tagData) {

        if (trim($tagData)) {
            if (isset($this -> arrOutput[count($this -> arrOutput) - 1]['tagData'])) {
                $this -> arrOutput[count($this -> arrOutput) - 1]['tagData'] .= $tagData;
            } else {
                $this -> arrOutput[count($this -> arrOutput) - 1]['tagData'] = $tagData;
            }
        }
    }

    function tagClosed($parser, $name) {
        $this -> arrOutput[count($this -> arrOutput) - 2]['children'][] = $this -> arrOutput[count($this -> arrOutput) - 1];
        array_pop($this -> arrOutput);
    }

}

function nullq($var){
        return (is_null($var) && !is_array($var)) ? "" : $var;
}



function array_to_objecttree($array) { // not currently used - to be removed if not used by final deployment
  if (is_numeric(key($array))) {
    foreach ($array as $key => $value) {
      $array[$key] = array_to_objecttree($value);
    }
    return $array;
  }
  $Object = new stdClass;
  foreach ($array as $key => $value) {
    if (is_array($value)) {
      $Object->$key = array_to_objecttree($value);
    }  else {
      $Object->$key = $value;
    }
  }
  return $Object;
}

function rest_array(){
    $objXML = new xml2Array();
    $arrOutput = $objXML -> parse(
            utf8_encode('<tns:FindCustomerMasterRequest xmlns:tns="ikea.com/system/iip/FindCustomerMasterICSPReqABCS/1.0/">
            <tns:ICOWSearch_Input>
               <tns:ListOfSwiOrganizationExtIO>
                  <tns:Account operation="" searchspec="">
                     <tns:ICSPICMMasterKey></tns:ICSPICMMasterKey>
                     <tns:ICSPCompanyName></tns:ICSPCompanyName>
                     <tns:ContactType></tns:ContactType>
                     <tns:ICSPContactStatus></tns:ICSPContactStatus>
                     <tns:ICSPCustomerNum></tns:ICSPCustomerNum>
                     <tns:ICSPCountryId></tns:ICSPCountryId>
                     <tns:ICSPLangCode></tns:ICSPLangCode>
                     <tns:ICSPPreferredStore></tns:ICSPPreferredStore>
                     <tns:ICSPPrefType></tns:ICSPPrefType>
                     <tns:ICSPICMCreatedDTime></tns:ICSPICMCreatedDTime>
                     <tns:ICSPICMUpdatedDTime></tns:ICSPICMUpdatedDTime>
                     <tns:ListOfContact lastpage="0">
                        <tns:Contact IsPrimaryMVG="" searchspec="">
                           <tns:ICSPICMCustomerRefId></tns:ICSPICMCustomerRefId>
                           <tns:first_name></tns:first_name>
                           <tns:ICSPMiddleName></tns:ICSPMiddleName>
                           <tns:last_name></tns:last_name>
                           <tns:ICSPTitle></tns:ICSPTitle>
                           <tns:ICSPGender></tns:ICSPGender>
                           <tns:ICSPProtIden></tns:ICSPProtIden>
                       </tns:Contact>
                     </tns:ListOfContact>
                     <tns:ListOfAddress lastpage="0">
                        <tns:Address IsPrimaryMVG="" searchspec="">
                           <tns:ICSPICMAddressRefId></tns:ICSPICMAddressRefId>
                           <tns:ICSPStreetAddress></tns:ICSPStreetAddress>
                            <tns:ICSPCity></tns:ICSPCity>
                            <tns:ICSPPostalCode></tns:ICSPPostalCode>
                            <tns:ICSPCountry></tns:ICSPCountry>
                            <tns:ICSPCounty></tns:ICSPCounty>
                            <tns:ICSPProvince></tns:ICSPProvince>
                            <tns:ICSPState></tns:ICSPState>
                            <tns:ICSPHouseNum></tns:ICSPHouseNum>
                            <tns:ICSPApartNum></tns:ICSPApartNum>
                            <tns:ICSPFloorNum></tns:ICSPFloorNum>
                            <tns:ICSPWard></tns:ICSPWard>
                            <tns:ICSPCName></tns:ICSPCName>
                            <tns:ICSPStrAd1></tns:ICSPStrAd1>
                            <tns:ICSPStrAd2></tns:ICSPStrAd2>
                            <tns:ICSPStrAd3></tns:ICSPStrAd3>
                            <tns:ICSPStrAd4></tns:ICSPStrAd4>
                            <tns:ICSPStrAd5></tns:ICSPStrAd5>
                            <tns:ICSPStrAd6></tns:ICSPStrAd6>
                            <tns:ICSPStrAd7></tns:ICSPStrAd7>
                        </tns:Address>
                     </tns:ListOfAddress>
                      <tns:ListOfContactMethod lastpage="0">
                         <tns:ContactMethod searchspec="" IsPrimaryMVG="">
                            <tns:ICSPContMethodId></tns:ICSPContMethodId>
                            <tns:Type>=""</tns:Type>
                            <tns:Value>=""</tns:Value>
                            <tns:Extension></tns:Extension>
                            <tns:AreaCode></tns:AreaCode>
                            <tns:CountryCode></tns:CountryCode>
                            <tns:ListOfPreferredContactMethod lastpage="0">
                               <tns:PreferredContactMethod searchspec="" IsPrimaryMVG="">
                                  <tns:Id></tns:Id>
                                  <tns:ContextType></tns:ContextType>
                                  <tns:Priority></tns:Priority>
                              </tns:PreferredContactMethod>
                           </tns:ListOfPreferredContactMethod>
                        </tns:ContactMethod>
                     </tns:ListOfContactMethod>
                      <tns:ListOfIntegrityCode lastpage="0">
                         <tns:IntegrityCode searchspec="">
                            <tns:IntegrityType></tns:IntegrityType>
                            <tns:ConsentCode></tns:ConsentCode>
                        </tns:IntegrityCode>
                     </tns:ListOfIntegrityCode>
                      <tns:ListOfLoyaltyMember lastpage="0">
                         <tns:LoyaltyMember searchspec="">
                            <tns:ICSPLoyaltyCode></tns:ICSPLoyaltyCode>
                            <tns:ICSPLoyaltyStatus></tns:ICSPLoyaltyStatus>
                            <tns:ListOfMembershipCard lastpage="0">
                               <tns:MembershipCard searchspec="">
                                  <tns:ICSPLoyaltyNum></tns:ICSPLoyaltyNum>
                              </tns:MembershipCard>
                           </tns:ListOfMembershipCard>
                        </tns:LoyaltyMember>
                     </tns:ListOfLoyaltyMember>
                      <tns:ListOfAccountOrganization lastpage="0">
                         <tns:AccountOrganization IsPrimaryMVG="" searchspec="">
                            <tns:ICSPOrg></tns:ICSPOrg>
                            <tns:ICSPOrgType></tns:ICSPOrgType>
                            <tns:ICSPOrgId></tns:ICSPOrgId>
                        </tns:AccountOrganization>
                     </tns:ListOfAccountOrganization>
                  </tns:Account>
               </tns:ListOfSwiOrganizationExtIO>
            </tns:ICOWSearch_Input>
         </tns:FindCustomerMasterRequest>
    ')
    );

    return $arrOutput;
}
?>