<?php
/*
**********************************************

     *** StripeCX Checkout for WHMCS ***

File:					stripecx/callback.php
File version:			0.0.1
Date:					05-08-2014

Copyright (C) NetDistrict 2014
All Rights Reserved
**********************************************
*/

# Required File Includes
include("../../../dbconnect.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

$gatewaymodule = "stripecx"; # Enter your gateway module name here replacing template

function stripecx_capture($amount,$currency,$token,$invoice)
{	
	global $gatewaymodule;
	$GATEWAY = getGatewayVariables($gatewaymodule);
	
	// Post data variables
	$data = 'amount='.$amount.'&currency='.$currency.'&card='.$token.'&description='.$invoice;
	
	// Send Capture Request
    $ch = curl_init('https://api.stripe.com/v1/charges');

	curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Client: StripeCX v0.0.1'));
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERPWD, $GATEWAY['private-key'] . ":" . NULL);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
	curl_setopt($ch, CURLOPT_CAINFO, realpath(dirname(__FILE__) . "/cacert.pem"));

	$return = curl_exec($ch);

	curl_close($ch);
    return $return;
}

# Process and store transaction
if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
 
	$ca = new WHMCS_ClientArea();
		 
	# Check login status
	if ($ca->isLoggedIn()) {

		$invoice_id = (int)$_REQUEST['id'];
		$store_token = $_REQUEST['token'];
		$currency = $_REQUEST['currency'];
		$amount = (int)$_REQUEST['amount'];
								
		if (preg_match("#^[A-Z]{3}$#", $currency) && preg_match("#^[a-zA-Z 0-9\_\-]*$#", $store_token)) {

			//Get invoice details
			$sql = "SELECT * FROM tblinvoices WHERE id = '$invoice_id'";
			$result = mysql_query($sql) or die(mysql_error);
			$row = mysql_fetch_array($result);
			
			$invoice_num = $row['invoicenum'];
			
			if ($invoice_num == NULL) $invoice_num = $invoice_id;
						
			//Create Capture request
			$capture = json_decode(stripecx_capture($amount,$currency,$token,$invoice_num));
						
			if ($capture->id == NULL) {
				if ($capture->error->charge) {
					$store_transaction = $capture->error->charge;
				}
			} else {
				$store_transaction = $capture->id;
			}
			
			if (!empty($store_transaction)) {						
				$sql = "INSERT INTO stripecx_transactions (invoice_id, transaction_id) VALUES ('$invoice_id', '$store_transaction')";
				mysql_query($sql) or die(mysql_error);
			}
		}
	}
		
} else {

	//Process Payment	
	$GATEWAY = getGatewayVariables($gatewaymodule);
	if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback

	// Retrieve the request's body and parse it as JSON
	$input = @file_get_contents("php://input");
	$event_json = json_decode($input);
	
	// Process returned data
	$transid = $event_json->data->object->id;
	$type = $event_json->type;
	$amount = ($event_json->data->object->amount) / 100;
	$currency = $event_json->data->object->currency;
	$fail_code = $event_json->data->object->failure_code;
	$fail_desc = $event_json->data->object->failure_message;
	$fee = (($amount * 0.029) + 0.30);
	
	//Get invoice id from database
	$sql = "SELECT * FROM stripecx_transactions WHERE transaction_id = '$transid'";
	$result = mysql_query($sql) or die(mysql_error);
	$row = mysql_fetch_array($result);
	$invoice = $row['invoice_id'];
	
	if ($type == 'charge.succeeded' && $invoice == '') {
		header("HTTP/1.1 400 BAD REQUEST");
		//http_response_code(400); // PHP 5.4 or greater
		exit();
	} elseif ($invoice == NULL) {
		$sql = "SELECT * FROM tblaccounts WHERE transid = '$transid'";
		$result = mysql_query($sql) or die(mysql_error);
		$row = mysql_fetch_array($result);
		$invoice = $row['invoiceid'];
	}
	
	$invoiceid = checkCbInvoiceID($invoice,$GATEWAY['name']); # Checks invoice ID is a valid invoice number or ends processing

	if ($type == 'charge.succeeded')
	checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does

	if ($type == 'charge.succeeded') {
		# Successful
		addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
		
		$output = "Transaction ID: " .$transid.
				"\r\nInvoice ID: " .$invoiceid.
				"\r\nStatus: " .$type;
								
		logTransaction($GATEWAY["name"],$output,"Successful"); # Save to Gateway Log: name, data array, status

		// Remove transaction from processing table
		$sql = "DELETE FROM stripecx_transactions WHERE invoice_id='$invoiceid'";
		mysql_query($sql) or die(mysql_error);
		
	} elseif ($type == 'charge.refunded') {
		# Refunded	
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  
		logTransaction($GATEWAY["name"],$output,"Refunded"); # Save to Gateway Log: name, data array, status
						
		// Remove transaction from processing table if it is there
		$sql = "DELETE FROM stripecx_transactions WHERE invoice_id='$invoiceid'";
		mysql_query($sql) or die(mysql_error);
	
	} elseif ($type == 'charge.updated') {
		# Updated
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  
		logTransaction($GATEWAY["name"],$output,"Updated"); # Save to Gateway Log: name, data array, status
			
	} elseif ($type == 'charge.failed') {
		# Unsuccessful
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type.
				  "\r\nFailure Code: " .$fail_code.
				  "\r\nFailure Description: " .$fail_desc;
				
		logTransaction($GATEWAY["name"],$output,"Unsuccessful"); # Save to Gateway Log: name, data array, status
		
		//Send fail mail to client
		if ($GATEWAY["sendfailedmail"] == 'on') {
			$command = "sendemail";
			$adminuser = "admin";
			$values["messagename"] = "Credit Card Payment Failed";
			$values["id"] = $invoiceid;
	 
			$results = localAPI($command,$values,$adminuser);
		}
		
		// Remove transaction from processing table
		$sql = "DELETE FROM stripecx_transactions WHERE invoice_id='$invoiceid'";
		mysql_query($sql) or die(mysql_error);
	
	} elseif ($type == 'charge.dispute.created' || $type == 'charge.dispute.updated' || $type == 'charge.dispute.closed') {
		# Dispute
		$output = "Transaction ID: " .$transid.
				  "\r\nInvoice ID: " .$invoiceid.
				  "\r\nStatus: " .$type;
				  				  
		logTransaction($GATEWAY["name"],$output,"Disputed"); # Save to Gateway Log: name, data array, status
	}
	
	header("HTTP/1.1 200 OK");
	//http_response_code(200); // PHP 5.4 or greater

}
?>